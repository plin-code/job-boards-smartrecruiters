<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/job-boards-smartrecruiters/main/art/banner.png" alt="Job Boards SmartRecruiters">
</p>

# Job Boards SmartRecruiters

<p align="center">
    <a href="https://packagist.org/packages/plin-code/job-boards-smartrecruiters"><img src="https://img.shields.io/packagist/v/plin-code/job-boards-smartrecruiters.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-smartrecruiters"><img src="https://img.shields.io/packagist/php-v/plin-code/job-boards-smartrecruiters.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-smartrecruiters"><img src="https://badge.laravel.cloud/badge/plin-code/job-boards-smartrecruiters?style=flat" alt="Laravel versions"></a>
    <a href="https://packagist.org/packages/plin-code/job-boards-smartrecruiters"><img src="https://img.shields.io/packagist/dt/plin-code/job-boards-smartrecruiters.svg?style=flat-square" alt="Total Downloads"></a>
</p>

SmartRecruiters connector for the [plin-code](https://github.com/plin-code) job boards family. It reads the public postings API, which needs no credentials but is paged:

```
GET https://api.smartrecruiters.com/v1/companies/{identifier}/postings?offset=0&limit=100
{ "offset": 0, "limit": 100, "totalFound": 210,
  "content": [ { "id": "744000147564650", "refNumber": "ID2609-00480A", "name": "...",
                 "language": { "code": "en" }, "location": { ... }, ... } ] }
```

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Installation

```bash
composer require plin-code/job-boards-smartrecruiters
```

## The language dedup, the most important thing here

SmartRecruiters publishes the same job **once per language**. Each copy carries its own `id` and its own `uuid`, but every copy of a job shares one `refNumber`:

| `id` | `uuid` | `refNumber` | `language.code` | `name` |
| --- | --- | --- | --- | --- |
| 744000147566189 | 553090ce… | ID2609-00480A | de | Praktikum Marketing & GTM Strategy |
| 744000147564650 | 94d0cec3… | ID2609-00480A | en | Intern Marketing & GTM Strategy |

Import both and a 210 posting board lands as roughly 420 rows, every job duplicated in German and English.

So postings are grouped by `refNumber`, and the English copy wins. Any `en` prefixed code counts (`en`, `en-US`, `en-GB`, `EN`); if no copy is English the first one seen is kept. A posting with no `refNumber` has nothing to group on and stands alone, so ref-less postings are never collapsed into each other. Grouping happens after every page has been read, so duplicates split across a page boundary still collapse.

`externalId` is the `refNumber`, not the `id`, precisely because the `refNumber` is what survives this: re-running a sync updates rows instead of creating new ones every time SmartRecruiters reshuffles which language copy comes back first.

## Framework agnostic on purpose

`SmartRecruitersClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\SmartRecruiters\SmartRecruitersClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new SmartRecruitersClient($http);

$jobs = $client->fetchJobsForCompany('ABOUTYOUGmbH');       // list<JobPostingDTO>, deduped
$name = $client->validateSlug('ABOUTYOUGmbH');              // ?string, the company name
$about = $client->fetchCompanyDescription('ABOUTYOUGmbH');  // always null, see below
```

`SmartRecruitersServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\SmartRecruiters\SmartRecruitersClient;

$client = app(SmartRecruitersClient::class);

foreach ($client->fetchJobsForCompany('ABOUTYOUGmbH') as $job) {
    JobPosting::updateOrCreate(
        ['external_id' => $job->externalId],
        $job->toArray(),
    );
}
```

Publish the config to change the base URL, the posting page template, the timeouts or the request headers:

```bash
php artisan vendor:publish --tag=job-boards-smartrecruiters-config
```

```php
'base_url'             => env('JOB_BOARDS_SMARTRECRUITERS_BASE_URL', SmartRecruitersClient::API_BASE_URL),
'posting_url_template' => env('JOB_BOARDS_SMARTRECRUITERS_POSTING_URL_TEMPLATE', SmartRecruitersClient::POSTING_URL_TEMPLATE),
'timeout'              => env('JOB_BOARDS_SMARTRECRUITERS_TIMEOUT', 30),
'lookup_timeout'       => env('JOB_BOARDS_SMARTRECRUITERS_LOOKUP_TIMEOUT', 15),
'headers'              => ['Accept' => 'application/json'],
```

The provider binds a PSR-18 client and a PSR-17 factory with `bindIf`, so an application that already binds its own keeps it. It deliberately does **not** bind `JobBoardClient` itself: several connectors implement that interface and would fight over the binding. Bind the one you want in your own application service provider.

## Pagination

Pages of 100, following `totalFound`, stopping early on an empty page. `MAX_PAGES` is 50, so at most 5000 postings are read from one board; hitting that limit logs a warning rather than looping forever behind a `totalFound` that never gets reached.

A page that fails mid-board abandons the whole fetch and returns an empty list. A half read board would look to the consumer like postings had been taken down.

## Mapping

| `JobPostingDTO` | SmartRecruiters field |
| --- | --- |
| `externalId` | `refNumber`, falling back to `id`, then `''` |
| `title` | `name`, trimmed, falling back to `'Untitled Position'` |
| `location` | `location.fullLocation`, else `location.city`, `location.region` and `location.country` joined with `', '`, else `null` |
| `url` | `postingUrl` when present, otherwise `https://jobs.smartrecruiters.com/{identifier}/{id}`, otherwise `''` |
| `department` | `department.label`, trimmed, or `null` |
| `rawPayload` | the untouched posting object |

### Why the URL is composed rather than fetched

The list endpoint does not expose `postingUrl` (only the per-posting detail endpoint does), and fetching detail for every posting would mean 100+ extra requests per company per sync. The public page accepts the bare posting id without the title slug, so it is built from the identifier and the posting id instead. Verified against live postings (744000147566189, 744000147564650 and 744000147556063 on `ABOUTYOUGmbH` all return 200 and render the correct job; an invalid id returns 404). The identifier is case-insensitive on that host.

## validateSlug and the empty board

`validateSlug()` reads one posting and returns `content[0].company.name`, or the slug itself when the board answers but names no company.

An empty result set returns **`null`**, and that is deliberate. SmartRecruiters answers `200` with an empty `content` array for identifiers that do not exist, so an empty board is the only signal there is that a slug is wrong. Turning this into "return the slug" would make every typo look like a valid company. There is a real trade-off here, a genuine company with nothing currently published also fails validation, and it is the one we want: a false negative asks the user to check the identifier, a false positive silently creates a company that will never sync anything.

## No company description

SmartRecruiters exposes no public company profile endpoint (`/v1/companies/{identifier}` responds 404 for every identifier), so `fetchCompanyDescription()` returns `null` without making a request. This is not a stub: there is nothing to fetch.

## Error handling

`fetchJobsForCompany()` never throws at the caller. Everything is logged through the injected PSR-3 logger and an empty list comes back, so one broken company cannot abort a sync over hundreds of them:

| Situation | Level | Message |
| --- | --- | --- |
| non 2xx status on any page | `warning` | `SmartRecruiters API request failed` |
| a page has no `content` array | `warning` | `SmartRecruiters API response missing content array` |
| pagination hit `MAX_PAGES` with more to read | `warning` | `SmartRecruiters pagination stopped at the page limit` |
| DNS failure, refused connection, timeout | `error` | `SmartRecruiters API connection error` |
| unreadable body, unexpected shape | `error` | `Unexpected error fetching SmartRecruiters jobs` |

Every record carries `company_slug`; the paging records also carry `offset`.

`validateSlug()` returns `null` for every failure and is silent about all of them except one: an empty result set logs `SmartRecruiters returned no postings for slug` at `warning`. That is the case where a user has probably mistyped an identifier, and it is worth being able to find in a log. `fetchCompanyDescription()` logs nothing and makes no request.

With no logger passed, a `NullLogger` is used and everything is silent.

## Timeouts

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so the configured 30 and 15 seconds are a request the transport may ignore. Note that the 30 seconds is per page, not per board. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Depending on core

```json
"require": {
    "plin-code/job-boards-core": "^0.2||^0.3"
}
```

Core is on Packagist, so that constraint is all this package needs: there is no `repositories` block to carry. Do **not** commit a `path` repository pointing at a sibling checkout of core. It resolves against the layout of one machine, and the package then fails to install from a fresh clone anywhere else.

## Development

```bash
composer install
composer lint          # pint, writes
composer lint:check    # pint, read only
composer analyse       # phpstan level 10, matching core
composer test:unit     # pest
composer test          # analyse + lint:check + test:unit
```

`tests/Unit` builds the client against a faked PSR-18 client and boots no framework. `tests/Feature` boots Testbench and covers the service provider only. The PSR-18 test doubles come from core, under `PlinCode\JobBoards\Testing`.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
