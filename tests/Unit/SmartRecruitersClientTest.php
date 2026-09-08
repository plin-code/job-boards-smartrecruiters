<?php

declare(strict_types=1);

use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\SmartRecruiters\SmartRecruitersClient;
use PlinCode\JobBoards\Testing\FakePsrClient;
use PlinCode\JobBoards\Testing\RecordingLogger;

function smartRecruitersClient(FakePsrClient $fake, ?RecordingLogger $logger = null): SmartRecruitersClient
{
    return new SmartRecruitersClient($fake->asHttpClient(), logger: $logger);
}

/**
 * One posting in the shape SmartRecruiters actually sends.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function srPosting(array $overrides = []): array
{
    return array_merge([
        'id' => '744000000000001',
        'name' => 'Some Job',
        'uuid' => '00000000-0000-0000-0000-000000000001',
        'refNumber' => 'REF-001',
        'company' => ['identifier' => 'ACME', 'name' => 'Acme Inc.'],
        'location' => ['city' => 'Berlin', 'region' => 'BE', 'country' => 'de', 'fullLocation' => 'Berlin, BE, Germany'],
        'department' => ['id' => '1', 'label' => 'Engineering'],
        'language' => ['code' => 'en', 'label' => 'English'],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function srPage(array $content, int $totalFound, int $offset = 0, int $limit = 100): array
{
    return [
        'offset' => $offset,
        'limit' => $limit,
        'totalFound' => $totalFound,
        'content' => $content,
    ];
}

/**
 * @param  list<array<string, mixed>>  $content
 */
function srRespond(array $content, int $totalFound): FakePsrClient
{
    return (new FakePsrClient)->respondWithJson(srPage($content, $totalFound));
}

function srFixture(): FakePsrClient
{
    return (new FakePsrClient)->respondWith(
        200,
        (string) file_get_contents(__DIR__.'/../Fixtures/smartrecruiters-aboutyou-postings.json'),
        ['Content-Type' => 'application/json'],
    );
}

/*
|--------------------------------------------------------------------------
| The language dedup
|--------------------------------------------------------------------------
|
| SmartRecruiters publishes the same job once per language: same refNumber,
| different id and uuid. Without the grouping below, a 210 posting board
| imports as roughly 420 rows. This is the most important behaviour in the
| package.
|
*/

it('COLLAPSES LANGUAGE DUPLICATES THAT SHARE A refNumber AND KEEPS THE ENGLISH COPY', function (): void {
    $fake = srRespond([
        srPosting([
            'id' => '111',
            'uuid' => 'aaaaaaaa-0000-0000-0000-000000000001',
            'name' => 'Softwareentwickler (m/w/d)',
            'refNumber' => 'SHARED-REF',
            'language' => ['code' => 'de', 'label' => 'German'],
        ]),
        srPosting([
            'id' => '222',
            'uuid' => 'bbbbbbbb-0000-0000-0000-000000000002',
            'name' => 'Software Engineer (m/f/d)',
            'refNumber' => 'SHARED-REF',
            'language' => ['code' => 'en-US', 'label' => 'English (US)'],
        ]),
    ], 2);

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    // Two postings in, ONE job out. The English copy wins, and the surviving
    // job is keyed on the shared refNumber rather than on either id.
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->externalId)->toBe('SHARED-REF')
        ->and($jobs[0]->title)->toBe('Software Engineer (m/f/d)')
        ->and($jobs[0]->url)->toBe('https://jobs.smartrecruiters.com/acme/222');
});

it('prefers the english copy whichever order it arrives in, and accepts any en- variant', function (): void {
    $englishFirst = srRespond([
        srPosting(['id' => '1', 'name' => 'Engineer', 'refNumber' => 'R', 'language' => ['code' => 'EN']]),
        srPosting(['id' => '2', 'name' => 'Ingegnere', 'refNumber' => 'R', 'language' => ['code' => 'it']]),
    ], 2);

    $englishLast = srRespond([
        srPosting(['id' => '1', 'name' => 'Ingegnere', 'refNumber' => 'R', 'language' => ['code' => 'it']]),
        srPosting(['id' => '2', 'name' => 'Engineer', 'refNumber' => 'R', 'language' => ['code' => 'en-GB']]),
    ], 2);

    expect(smartRecruitersClient($englishFirst)->fetchJobsForCompany('acme')[0]->title)->toBe('Engineer')
        ->and(smartRecruitersClient($englishLast)->fetchJobsForCompany('acme')[0]->title)->toBe('Engineer');
});

it('keeps the first copy when no language variant is english', function (): void {
    $fake = srRespond([
        srPosting(['id' => '111', 'name' => 'Softwareentwickler', 'refNumber' => 'SHARED-REF', 'language' => ['code' => 'de']]),
        srPosting(['id' => '222', 'name' => 'Sviluppatore', 'refNumber' => 'SHARED-REF', 'language' => ['code' => 'it']]),
    ], 2);

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->title)->toBe('Softwareentwickler');
});

it('does not collapse postings that merely lack a refNumber', function (): void {
    // Nothing to group on is not the same as grouping to nothing: three
    // ref-less postings must stay three jobs.
    $fake = srRespond([
        srPosting(['id' => '1', 'name' => 'One', 'refNumber' => null]),
        srPosting(['id' => '2', 'name' => 'Two', 'refNumber' => null]),
        srPosting(['id' => '3', 'name' => 'Three', 'refNumber' => '']),
    ], 3);

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs)->toHaveCount(3)
        ->and(array_map(fn (JobPostingDTO $j): string => $j->title, $jobs))->toBe(['One', 'Two', 'Three']);
});

it('collapses duplicates spread across two pages, not just within one', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson(srPage([srPosting(['id' => '1', 'name' => 'Entwickler', 'refNumber' => 'R', 'language' => ['code' => 'de']])], 150))
        ->respondWithJson(srPage([srPosting(['id' => '2', 'name' => 'Engineer', 'refNumber' => 'R', 'language' => ['code' => 'en']])], 150, 100));

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->title)->toBe('Engineer');
});

/*
|--------------------------------------------------------------------------
| Mapping
|--------------------------------------------------------------------------
*/

it('fetches postings and maps them to DTOs', function (): void {
    $fake = srFixture();

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('ABOUTYOUGmbH');

    // The fixture holds three postings, two of which are the same job in German
    // and English.
    expect($jobs)->toHaveCount(2)
        ->and($jobs[0])->toBeInstanceOf(JobPostingDTO::class)
        ->and($jobs[0]->externalId)->toBe('ID2609-00480A')
        ->and($jobs[0]->title)->toBe('Intern Marketing & GTM Strategy (all genders)')
        ->and($jobs[0]->location)->toBe('Hamburg, HH, Germany')
        ->and($jobs[0]->department)->toBe('Business')
        ->and($jobs[0]->url)->toBe('https://jobs.smartrecruiters.com/ABOUTYOUGmbH/744000147564650')
        ->and($jobs[0]->rawPayload['uuid'] ?? null)->toBe('94d0cec3-a218-4d2a-9e77-fb566d4eebd8')
        ->and($jobs[1]->externalId)->toBe('ID2608-00454A')
        ->and($jobs[1]->department)->toBe('Tech')
        ->and($fake->lastUri())->toBe('https://api.smartrecruiters.com/v1/companies/ABOUTYOUGmbH/postings?offset=0&limit=100');
});

it('composes the location from city, region and country when fullLocation is absent', function (): void {
    $jobs = smartRecruitersClient(srFixture())->fetchJobsForCompany('ABOUTYOUGmbH');

    expect($jobs[1]->location)->toBe('Hamburg, HH, de');
});

it('returns a null location when the posting has no usable location data', function (): void {
    $fake = srRespond([srPosting(['location' => ['remote' => true]])], 1);

    expect(smartRecruitersClient($fake)->fetchJobsForCompany('acme')[0]->location)->toBeNull();
});

it('returns a null location when location is not an object at all', function (): void {
    $fake = srRespond([
        srPosting(['id' => '1', 'refNumber' => 'A', 'location' => 'Berlin']),
        srPosting(['id' => '2', 'refNumber' => 'B', 'location' => ['fullLocation' => '   ', 'city' => '  ']]),
    ], 2);

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs[0]->location)->toBeNull()
        ->and($jobs[1]->location)->toBeNull();
});

it('falls back to the posting id when refNumber is missing or empty', function (): void {
    $fake = srRespond([
        srPosting(['id' => '111', 'refNumber' => null]),
        srPosting(['id' => '222', 'refNumber' => '   ']),
        srPosting(['id' => '333', 'name' => 'No ref key at all', 'refNumber' => null]),
    ], 3);

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs)->toHaveCount(3)
        ->and(array_map(fn (JobPostingDTO $j): string => $j->externalId, $jobs))->toBe(['111', '222', '333']);
});

it('accepts a numeric refNumber and a numeric id', function (): void {
    $fake = srRespond([
        srPosting(['id' => 744000000000001, 'refNumber' => 90210]),
        srPosting(['id' => ['not', 'a', 'scalar'], 'refNumber' => ['neither']]),
    ], 2);

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs[0]->externalId)->toBe('90210')
        ->and($jobs[0]->url)->toBe('https://jobs.smartrecruiters.com/acme/744000000000001')
        ->and($jobs[1]->externalId)->toBe('')
        // No id means no page can be composed, so the url stays empty rather
        // than pointing at a 404.
        ->and($jobs[1]->url)->toBe('');
});

it('falls back to a placeholder title when the name is empty', function (): void {
    $fake = srRespond([srPosting(['name' => ''])], 1);

    expect(smartRecruitersClient($fake)->fetchJobsForCompany('acme')[0]->title)->toBe('Untitled Position');
});

it('leaves the department null when it is missing or blank', function (): void {
    $fake = srRespond([
        srPosting(['id' => '1', 'refNumber' => 'A', 'department' => ['id' => '1', 'label' => '  ']]),
        srPosting(['id' => '2', 'refNumber' => 'B', 'department' => null]),
    ], 2);

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs[0]->department)->toBeNull()
        ->and($jobs[1]->department)->toBeNull();
});

it('prefers postingUrl when the payload already carries one', function (): void {
    $fake = srRespond([srPosting(['postingUrl' => 'https://jobs.smartrecruiters.com/ACME/999-some-job'])], 1);

    expect(smartRecruitersClient($fake)->fetchJobsForCompany('acme')[0]->url)
        ->toBe('https://jobs.smartrecruiters.com/ACME/999-some-job');
});

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

it('pages through the postings endpoint until totalFound is reached', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson(srPage([
            srPosting(['id' => '1', 'refNumber' => 'REF-1']),
            srPosting(['id' => '2', 'refNumber' => 'REF-2']),
        ], 150))
        ->respondWithJson(srPage([
            srPosting(['id' => '3', 'refNumber' => 'REF-3']),
        ], 150, 100));

    $jobs = smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($jobs)->toHaveCount(3)
        ->and(array_map(fn (JobPostingDTO $j): string => $j->externalId, $jobs))->toBe(['REF-1', 'REF-2', 'REF-3'])
        ->and($fake->uris())->toBe([
            'https://api.smartrecruiters.com/v1/companies/acme/postings?offset=0&limit=100',
            'https://api.smartrecruiters.com/v1/companies/acme/postings?offset=100&limit=100',
        ]);
});

it('stops paging when a page comes back empty', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson(srPage([srPosting(['id' => '1', 'refNumber' => 'REF-1'])], 500))
        ->respondWithJson(srPage([], 500, 100));

    expect(smartRecruitersClient($fake)->fetchJobsForCompany('acme'))->toHaveCount(1)
        ->and($fake->uris())->toHaveCount(2);
});

it('guards against runaway pagination', function (): void {
    $fake = new FakePsrClient;

    for ($i = 0; $i < SmartRecruitersClient::MAX_PAGES + 5; $i++) {
        $fake->respondWithJson(srPage([srPosting(['id' => (string) $i, 'refNumber' => 'REF-'.$i])], 999_999, $i * 100));
    }

    $logger = new RecordingLogger;

    $jobs = smartRecruitersClient($fake, $logger)->fetchJobsForCompany('acme');

    expect($fake->uris())->toHaveCount(SmartRecruitersClient::MAX_PAGES)
        ->and($jobs)->toHaveCount(SmartRecruitersClient::MAX_PAGES)
        ->and($logger->messages())->toBe(['SmartRecruiters pagination stopped at the page limit'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe([
            'company_slug' => 'acme',
            'fetched' => SmartRecruitersClient::MAX_PAGES,
            'total_found' => 999_999,
        ]);
});

it('makes a single request when the first page already covers totalFound', function (): void {
    $fake = srRespond([srPosting()], 1);

    smartRecruitersClient($fake)->fetchJobsForCompany('acme');

    expect($fake->uris())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Failure modes
|--------------------------------------------------------------------------
*/

it('returns an empty list when the board has no postings', function (): void {
    $fake = srRespond([], 0);

    expect(smartRecruitersClient($fake)->fetchJobsForCompany('empty'))->toBe([]);
});

it('returns an empty list when the response has no content key', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['totalFound' => 3]);
    $logger = new RecordingLogger;

    expect(smartRecruitersClient($fake, $logger)->fetchJobsForCompany('weird'))->toBe([])
        ->and($logger->messages())->toBe(['SmartRecruiters API response missing content array'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe(['company_slug' => 'weird', 'offset' => 0]);
});

it('returns an empty list on a 404 response', function (): void {
    $fake = (new FakePsrClient)->respondWith(404, 'Not Found');
    $logger = new RecordingLogger;

    expect(smartRecruitersClient($fake, $logger)->fetchJobsForCompany('gone'))->toBe([])
        ->and($logger->messages())->toBe(['SmartRecruiters API request failed'])
        ->and($logger->records[0]['context'])->toBe(['company_slug' => 'gone', 'status' => 404, 'offset' => 0]);
});

it('returns an empty list on a 500 response', function (): void {
    $fake = (new FakePsrClient)->respondWith(500, 'Server Error');
    $logger = new RecordingLogger;

    expect(smartRecruitersClient($fake, $logger)->fetchJobsForCompany('broken'))->toBe([])
        ->and($logger->messages())->toBe(['SmartRecruiters API request failed'])
        ->and($logger->records[0]['context']['status'])->toBe(500);
});

it('returns an empty list when a later page fails, rather than a partial board', function (): void {
    // A half read board would look like postings had been taken down, so the
    // whole fetch is abandoned instead.
    $fake = (new FakePsrClient)
        ->respondWithJson(srPage([srPosting(['id' => '1', 'refNumber' => 'REF-1'])], 500))
        ->respondWith(503, 'Service Unavailable');

    expect(smartRecruitersClient($fake)->fetchJobsForCompany('acme'))->toBe([]);
});

it('returns an empty list on a connection error', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(smartRecruitersClient($fake, $logger)->fetchJobsForCompany('timeout'))->toBe([])
        ->and($logger->messages())->toBe(['SmartRecruiters API connection error'])
        ->and($logger->levels())->toBe(['error'])
        ->and($logger->records[0]['context']['company_slug'])->toBe('timeout')
        ->and($logger->records[0]['context']['error'])->toContain('connection refused');
});

it('returns an empty list when the body is not json', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '<html>maintenance</html>');
    $logger = new RecordingLogger;

    expect(smartRecruitersClient($fake, $logger)->fetchJobsForCompany('acme'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching SmartRecruiters jobs'])
        ->and($logger->levels())->toBe(['error']);
});

/*
|--------------------------------------------------------------------------
| validateSlug
|--------------------------------------------------------------------------
*/

it('validateSlug returns the company name', function (): void {
    $fake = srFixture();

    expect(smartRecruitersClient($fake)->validateSlug('ABOUTYOUGmbH'))->toBe('ABOUT YOU SE & Co. KG')
        // One posting is enough to answer, so it asks for exactly one.
        ->and($fake->lastUri())->toBe('https://api.smartrecruiters.com/v1/companies/ABOUTYOUGmbH/postings?offset=0&limit=1');
});

it('validateSlug falls back to the slug when the company name is missing or blank', function (): void {
    expect(smartRecruitersClient(srRespond([srPosting(['company' => ['identifier' => 'ACME']])], 1))->validateSlug('acme'))->toBe('acme')
        ->and(smartRecruitersClient(srRespond([srPosting(['company' => ['name' => '   ']])], 1))->validateSlug('acme'))->toBe('acme')
        ->and(smartRecruitersClient(srRespond([srPosting(['company' => 'Acme'])], 1))->validateSlug('acme'))->toBe('acme');
});

it('validateSlug trims the company name', function (): void {
    $fake = srRespond([srPosting(['company' => ['name' => "  Acme Inc.\n"]])], 1);

    expect(smartRecruitersClient($fake)->validateSlug('acme'))->toBe('Acme Inc.');
});

it('validateSlug returns null on a 404 response', function (): void {
    $fake = (new FakePsrClient)->respondWith(404, 'Not Found');

    expect(smartRecruitersClient($fake)->validateSlug('gone'))->toBeNull();
});

it('validateSlug returns null when the API answers 200 with no postings', function (): void {
    // This is deliberate and load bearing. SmartRecruiters answers 200 with an
    // empty content array for identifiers that do not exist, so an empty board
    // is the ONLY signal that the slug is wrong. Returning the slug here would
    // make every typo look like a valid company.
    $fake = srRespond([], 0);
    $logger = new RecordingLogger;

    expect(smartRecruitersClient($fake, $logger)->validateSlug('unknown'))->toBeNull()
        ->and($logger->messages())->toBe(['SmartRecruiters returned no postings for slug'])
        ->and($logger->levels())->toBe(['warning']);
});

it('validateSlug returns null on a connection error and on a body that is not json', function (): void {
    expect(smartRecruitersClient((new FakePsrClient)->throwNetworkError())->validateSlug('timeout'))->toBeNull()
        ->and(smartRecruitersClient((new FakePsrClient)->respondWith(200, 'not json'))->validateSlug('acme'))->toBeNull()
        ->and(smartRecruitersClient((new FakePsrClient)->respondWith(204, ''))->validateSlug('acme'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The rest of the contract
|--------------------------------------------------------------------------
*/

it('fetchCompanyDescription always returns null and never calls out', function (): void {
    $fake = new FakePsrClient;

    expect(smartRecruitersClient($fake)->fetchCompanyDescription('any-slug'))->toBeNull()
        ->and($fake->requests)->toBe([]);
});

it('asks for 30 seconds per page when listing and 15 when looking up', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson(srPage([srPosting(['id' => '1', 'refNumber' => 'REF-1'])], 150))
        ->respondWithJson(srPage([srPosting(['id' => '2', 'refNumber' => 'REF-2'])], 150, 100))
        ->respondWithJson(srPage([srPosting()], 1));

    $client = smartRecruitersClient($fake);
    $client->fetchJobsForCompany('acme');
    $client->validateSlug('acme');

    expect($fake->appliedTimeouts)->toBe([30.0, 30.0, 15.0]);
});

it('percent encodes the identifier without double encoding one that already is', function (): void {
    $raw = srRespond([], 0);
    smartRecruitersClient($raw)->fetchJobsForCompany('a b/../c');

    $encoded = srRespond([], 0);
    smartRecruitersClient($encoded)->fetchJobsForCompany('Acme%20GmbH');

    expect($raw->lastUri())->toBe('https://api.smartrecruiters.com/v1/companies/a%20b%2F..%2Fc/postings?offset=0&limit=100')
        ->and($encoded->lastUri())->toBe('https://api.smartrecruiters.com/v1/companies/Acme%20GmbH/postings?offset=0&limit=100');
});

it('is safe with no logger at all', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();

    expect((new SmartRecruitersClient($fake->asHttpClient()))->fetchJobsForCompany('acme'))->toBe([]);
});

it('accepts a custom base url and posting url template', function (): void {
    $fake = srRespond([srPosting(['id' => '999'])], 1);

    $jobs = (new SmartRecruitersClient(
        $fake->asHttpClient(),
        'https://fixtures.test/companies/',
        'https://careers.test/%s/jobs/%s',
    ))->fetchJobsForCompany('acme');

    expect($fake->lastUri())->toBe('https://fixtures.test/companies/acme/postings?offset=0&limit=100')
        ->and($jobs[0]->url)->toBe('https://careers.test/acme/jobs/999');
});
