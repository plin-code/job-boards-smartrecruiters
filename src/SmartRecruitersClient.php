<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\SmartRecruiters;

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Exceptions\TransportException;
use PlinCode\JobBoards\Http\HttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reads the public SmartRecruiters postings API:
 *
 *   GET https://api.smartrecruiters.com/v1/companies/{identifier}/postings?offset=0&limit=100
 *   { "offset": 0, "limit": 100, "totalFound": 210, "content": [ { "id": ..., "refNumber": ..., ... } ] }
 *
 * Two things make this connector bigger than the rest of the family: the board
 * is paged, and the same job is published once per language. See
 * {@see self::dedupeByRefNumber()}, which is the single most important method
 * here.
 *
 * Nothing here knows about Laravel. It is handed core's HttpClient and an
 * optional PSR-3 logger, both of which a Symfony or plain PHP consumer can
 * build by hand. {@see SmartRecruitersServiceProvider} is the only Laravel
 * aware file.
 */
final class SmartRecruitersClient implements JobBoardClient
{
    public const string API_BASE_URL = 'https://api.smartrecruiters.com/v1/companies';

    /**
     * Public job page. The list endpoint does not expose `postingUrl` (only the
     * per-posting detail endpoint does), and fetching detail for every posting
     * would mean 100+ extra requests per company per sync. The public page
     * accepts the bare posting id without the title slug, so we build it from
     * the company identifier and the posting id instead. Verified against live
     * postings (744000147566189, 744000147564650, 744000147556063 on
     * ABOUTYOUGmbH all return 200 and render the correct job; an invalid id
     * returns 404). The identifier is case-insensitive on this host.
     */
    public const string POSTING_URL_TEMPLATE = 'https://jobs.smartrecruiters.com/%s/%s';

    public const int PAGE_SIZE = 100;

    /**
     * Safety net against a totalFound that never gets reached
     * (PAGE_SIZE * MAX_PAGES postings).
     */
    public const int MAX_PAGES = 50;

    /**
     * Listing a whole board can take many pages, so it gets a longer budget
     * than the single page validateSlug() reads.
     */
    public const float TIMEOUT_SECONDS = 30.0;

    public const float LOOKUP_TIMEOUT_SECONDS = 15.0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl = self::API_BASE_URL,
        private readonly string $postingUrlTemplate = self::POSTING_URL_TEMPLATE,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly float $lookupTimeout = self::LOOKUP_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Never throws at the caller: whatever goes wrong is logged and an empty
     * list comes back, so one broken company cannot abort a sync over hundreds
     * of them.
     *
     * @return list<JobPostingDTO>
     */
    public function fetchJobsForCompany(string $slug): array
    {
        try {
            $postings = $this->fetchAllPostings($slug);

            if ($postings === null) {
                return [];
            }

            return array_map(
                fn (array $posting): JobPostingDTO => $this->mapToDTO($posting, $slug),
                $this->dedupeByRefNumber($postings),
            );
        } catch (TransportException $e) {
            $this->logger->error('SmartRecruiters API connection error', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error fetching SmartRecruiters jobs', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Returns the company name from the first posting, or the slug itself when
     * the board answers but names no company.
     *
     * Deliberately returns null on an empty result set. SmartRecruiters answers
     * 200 with an empty `content` for identifiers that do not exist, so an
     * empty board is the only signal there is that the slug is wrong. Do not
     * "fix" this into returning the slug: it would make every typo look valid.
     */
    public function validateSlug(string $slug): ?string
    {
        try {
            // tryGet() here: a lookup is silent about transport failures by
            // contract, so there is no message to keep.
            $response = $this->http->withTimeout($this->lookupTimeout)->tryGet($this->postingsUrl($slug), [
                'offset' => 0,
                'limit' => 1,
            ]);

            if ($response === null || $response->failed()) {
                return null;
            }

            $content = $response->json('content');

            if (! is_array($content) || $content === []) {
                $this->logger->warning('SmartRecruiters returned no postings for slug', [
                    'company_slug' => $slug,
                ]);

                return null;
            }

            $first = $content[array_key_first($content)];
            $name = is_array($first) ? $this->nested($first, 'company', 'name') : null;

            return is_string($name) && trim($name) !== '' ? trim($name) : $slug;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * SmartRecruiters exposes no public company profile endpoint
     * (/v1/companies/{identifier} responds 404 for every identifier), so there
     * is nothing to fetch.
     */
    public function fetchCompanyDescription(string $slug): ?string
    {
        return null;
    }

    /**
     * Page through the postings endpoint. Returns null when the API could not
     * be read, which is different from a board that is genuinely empty.
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchAllPostings(string $slug): ?array
    {
        $postings = [];
        $offset = 0;
        $page = 0;
        $total = 0;

        do {
            // get() rather than tryGet() so the transport error message survives
            // into the log of the caller. tryGet() would flatten it to a null.
            $response = $this->http->withTimeout($this->timeout)->get($this->postingsUrl($slug), [
                'offset' => $offset,
                'limit' => self::PAGE_SIZE,
            ]);

            if ($response->failed()) {
                $this->logger->warning('SmartRecruiters API request failed', [
                    'company_slug' => $slug,
                    'status' => $response->status(),
                    'offset' => $offset,
                ]);

                return null;
            }

            $content = $response->json('content');

            if (! is_array($content)) {
                $this->logger->warning('SmartRecruiters API response missing content array', [
                    'company_slug' => $slug,
                    'offset' => $offset,
                ]);

                return null;
            }

            foreach ($content as $posting) {
                if (is_array($posting)) {
                    /** @var array<string, mixed> $posting */
                    $postings[] = $posting;
                }
            }

            if ($content === []) {
                break;
            }

            $totalFound = $response->json('totalFound');
            $total = is_numeric($totalFound) ? (int) $totalFound : 0;
            $offset += self::PAGE_SIZE;
            $page++;
        } while ($offset < $total && $page < self::MAX_PAGES);

        if ($page >= self::MAX_PAGES && $offset < $total) {
            $this->logger->warning('SmartRecruiters pagination stopped at the page limit', [
                'company_slug' => $slug,
                'fetched' => count($postings),
                'total_found' => $total,
            ]);
        }

        return $postings;
    }

    /**
     * The single most important method in this connector.
     *
     * SmartRecruiters publishes the same job once per language. Each copy has
     * its own `id` and its own `uuid`, but they share a `refNumber`. Import
     * them all and a 210 posting board lands as roughly 420 rows, every job
     * duplicated in German and English.
     *
     * So: group by `refNumber`, keep the English copy when there is one,
     * otherwise the first one seen. A posting with no refNumber has nothing to
     * group on and stands alone.
     *
     * @param  list<array<string, mixed>>  $postings
     * @return list<array<string, mixed>>
     */
    private function dedupeByRefNumber(array $postings): array
    {
        $grouped = [];
        $standalone = 0;

        foreach ($postings as $posting) {
            $refNumber = $this->refNumber($posting);

            $key = $refNumber !== null ? 'ref:'.$refNumber : 'idx:'.$standalone++;

            if (! isset($grouped[$key])) {
                $grouped[$key] = $posting;

                continue;
            }

            if (! $this->isEnglish($grouped[$key]) && $this->isEnglish($posting)) {
                $grouped[$key] = $posting;
            }
        }

        return array_values($grouped);
    }

    /**
     * "en", "en-US" and "EN" all count. The label is not looked at.
     *
     * @param  array<string, mixed>  $posting
     */
    private function isEnglish(array $posting): bool
    {
        $code = $this->nested($posting, 'language', 'code');

        return is_string($code) && str_starts_with(strtolower($code), 'en');
    }

    /**
     * Read $data[$outer][$inner] without assuming either level exists or is an
     * array. The payload is untyped JSON from a third party.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function nested(array $data, string $outer, string $inner): mixed
    {
        $value = $data[$outer] ?? null;

        return is_array($value) ? ($value[$inner] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function refNumber(array $posting): ?string
    {
        $refNumber = $posting['refNumber'] ?? null;

        if (! is_string($refNumber) && ! is_int($refNumber)) {
            return null;
        }

        $refNumber = trim((string) $refNumber);

        return $refNumber !== '' ? $refNumber : null;
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function postingId(array $posting): string
    {
        $id = $posting['id'] ?? null;

        return is_string($id) || is_int($id) ? trim((string) $id) : '';
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function mapToDTO(array $posting, string $slug): JobPostingDTO
    {
        $id = $this->postingId($posting);

        $title = $posting['name'] ?? null;
        $title = is_string($title) ? trim($title) : '';

        $department = $this->nested($posting, 'department', 'label');
        $department = is_string($department) ? trim($department) : '';

        return new JobPostingDTO(
            // The refNumber, not the id: it is the identifier that survives the
            // language dedup, so re-running a sync updates rows instead of
            // creating new ones every time SmartRecruiters reshuffles which
            // language copy comes first.
            externalId: $this->refNumber($posting) ?? $id,
            title: $title !== '' ? $title : 'Untitled Position',
            location: $this->resolveLocation($posting),
            url: $this->resolveUrl($posting, $slug, $id),
            department: $department !== '' ? $department : null,
            rawPayload: $posting,
        );
    }

    /**
     * `fullLocation` when the API sends one, otherwise city, region and country
     * joined, otherwise null.
     *
     * @param  array<string, mixed>  $posting
     */
    private function resolveLocation(array $posting): ?string
    {
        $location = $posting['location'] ?? null;

        if (! is_array($location)) {
            return null;
        }

        $full = $location['fullLocation'] ?? null;

        if (is_string($full) && trim($full) !== '') {
            return trim($full);
        }

        $parts = [];

        foreach (['city', 'region', 'country'] as $key) {
            $value = $location[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $posting
     */
    private function resolveUrl(array $posting, string $slug, string $id): string
    {
        $postingUrl = $posting['postingUrl'] ?? null;

        if (is_string($postingUrl) && trim($postingUrl) !== '') {
            return trim($postingUrl);
        }

        if ($id === '') {
            return '';
        }

        return sprintf($this->postingUrlTemplate, $slug, $id);
    }

    /**
     * The identifier is a path segment, so it is escaped rather than trusted.
     * Decoding first keeps it idempotent for an identifier that already arrives
     * encoded.
     */
    private function postingsUrl(string $slug): string
    {
        return sprintf('%s/%s/postings', rtrim($this->baseUrl, '/'), rawurlencode(rawurldecode($slug)));
    }
}
