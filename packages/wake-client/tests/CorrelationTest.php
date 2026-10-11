<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Logging\CorrelationResolver;
use ArtisanBuild\WakeClient\Logging\CorrelationState;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;

afterEach(function (): void {
    Context::flush();
    app(CorrelationState::class)->finishQueueJob();
});

it('uses Cloud request ID as the request fallback', function (): void {
    app()->instance('request', Request::create('/', 'GET', server: ['HTTP_CLOUD_REQUEST_ID' => 'cloud-123']));

    expect(app(CorrelationResolver::class)->resolve([], []))->toBe('cloud-123');
});

it('uses deterministic correlation source precedence', function (): void {
    app()->instance('request', Request::create('/', 'GET', server: [
        'HTTP_CLOUD_REQUEST_ID' => 'cloud-value',
        'HTTP_X_CORRELATION_ID' => 'header-value',
    ]));
    Context::add('correlation_id', 'context-value');
    app(CorrelationState::class)->startQueueJob('job-value');
    $resolver = app(CorrelationResolver::class);

    expect($resolver->resolve(['trace_id' => 'record-context'], ['correlation_id' => 'record-extra']))
        ->toBe('record-context')
        ->and($resolver->resolve([], ['request_id' => 'record-extra']))->toBe('record-extra')
        ->and($resolver->resolve([], []))->toBe('context-value');

    Context::flush();
    expect($resolver->resolve([], []))->toBe('cloud-value');

    app()->instance('request', Request::create('/'));
    expect($resolver->resolve([], []))->toBe('job-value');
});

it('clears the active queue UUID after successful processing', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->once()->andReturn('job-success');
    $job->shouldReceive('payload')->once()->andReturn([]);
    event(new JobProcessing('sync', $job));

    expect(app(CorrelationResolver::class)->resolve([], []))->toBe('job-success');
    event(new JobProcessed('sync', $job));
    expect(app(CorrelationResolver::class)->resolve([], []))->toBeNull();
});

it('clears the active queue UUID after exceptional processing', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->once()->andReturn('job-failure');
    $job->shouldReceive('payload')->once()->andReturn([]);
    event(new JobProcessing('sync', $job));

    expect(app(CorrelationResolver::class)->resolve([], []))->toBe('job-failure');
    event(new JobExceptionOccurred('sync', $job, new RuntimeException('failed')));
    expect(app(CorrelationResolver::class)->resolve([], []))->toBeNull();
});
