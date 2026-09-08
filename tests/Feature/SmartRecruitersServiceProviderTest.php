<?php

declare(strict_types=1);

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\SmartRecruiters\SmartRecruitersClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('publishes a config file', function (): void {
    expect(config('job-boards-smartrecruiters.base_url'))->toBe(SmartRecruitersClient::API_BASE_URL)
        ->and(config('job-boards-smartrecruiters.posting_url_template'))->toBe(SmartRecruitersClient::POSTING_URL_TEMPLATE)
        ->and(config('job-boards-smartrecruiters.timeout'))->toBe(30)
        ->and(config('job-boards-smartrecruiters.lookup_timeout'))->toBe(15)
        ->and(config('job-boards-smartrecruiters.headers'))->toBe(['Accept' => 'application/json']);
});

it('resolves the client from the container', function (): void {
    expect(app(SmartRecruitersClient::class))->toBeInstanceOf(JobBoardClient::class);
});

it('binds a psr-18 client and a psr-17 request factory', function (): void {
    expect(app(ClientInterface::class))->toBeInstanceOf(ClientInterface::class)
        ->and(app(RequestFactoryInterface::class))->toBeInstanceOf(RequestFactoryInterface::class);
});

it('lets the application override the psr-18 client', function (): void {
    $custom = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new RuntimeException('never called');
        }
    };

    app()->instance(ClientInterface::class, $custom);

    expect(app(ClientInterface::class))->toBe($custom)
        ->and(app(SmartRecruitersClient::class))->toBeInstanceOf(SmartRecruitersClient::class);
});

it('does not bind the JobBoardClient contract, since connectors would collide', function (): void {
    expect(app()->bound(JobBoardClient::class))->toBeFalse();
});
