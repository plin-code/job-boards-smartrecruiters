<?php

declare(strict_types=1);

use PlinCode\JobBoards\SmartRecruiters\SmartRecruitersClient;

return [

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | The SmartRecruiters companies root. The company identifier and
    | "/postings" are appended to it. Override it to point the connector at a
    | recorded fixture server.
    |
    */

    'base_url' => env('JOB_BOARDS_SMARTRECRUITERS_BASE_URL', SmartRecruitersClient::API_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Posting URL Template
    |--------------------------------------------------------------------------
    |
    | The public job page built for every posting: "%s" is the company
    | identifier, the second "%s" the posting id. The list endpoint does not
    | return a postingUrl, and fetching one per posting would mean a request
    | each, so the page is composed instead.
    |
    */

    'posting_url_template' => env('JOB_BOARDS_SMARTRECRUITERS_POSTING_URL_TEMPLATE', SmartRecruitersClient::POSTING_URL_TEMPLATE),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds, per request rather than per board: a large board is many pages.
    | "lookup_timeout" covers the single page validateSlug() reads. Honoured
    | only by PSR-18 clients that implement
    | PlinCode\JobBoards\Http\SupportsTimeout; other clients keep the timeout
    | they were built with.
    |
    */

    'timeout' => env('JOB_BOARDS_SMARTRECRUITERS_TIMEOUT', 30),

    'lookup_timeout' => env('JOB_BOARDS_SMARTRECRUITERS_LOOKUP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request. The postings endpoint is public and needs no
    | authentication, so Accept is all SmartRecruiters asks for.
    |
    */

    'headers' => [
        'Accept' => 'application/json',
    ],

];
