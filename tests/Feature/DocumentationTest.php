<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('publishes linked setup redaction retention and ingest contract pages', function (): void {
    foreach (['setup', 'agent-redaction', 'retention-cost', 'ingest-contract'] as $page) {
        $this->get('/docs/'.$page)->assertOk()
            ->assertSee('data-testid="documentation"', false)
            ->assertSee('data-testid="documentation-content"', false)
            ->assertSee('href="http://localhost/docs/setup"', false)
            ->assertSee('href="http://localhost/docs/agent-redaction"', false)
            ->assertSee('href="http://localhost/docs/retention-cost"', false)
            ->assertSee('href="http://localhost/docs/ingest-contract"', false)
            ->assertSee('href="http://localhost/apps"', false);
    }

    $this->get('/docs/missing')->assertNotFound();
    expect(Route::has('docs.show'))->toBeTrue();
});

it('contains wide generated Markdown code inside the 390px documentation column', function (): void {
    $response = $this->get('/docs/setup')->assertOk()
        ->assertSee('[data-testid="documentation-content"] pre', false)
        ->assertSee('max-width: 100%; overflow-x: auto; white-space: pre;', false)
        ->assertSee('min-width:0; overflow:hidden;', false);

    expect($response->getContent())->not->toContain('min-width: max-content', 'overflow-x: visible');
});

it('documents the exact client setup and raw payload risk', function (): void {
    $response = $this->get('/docs/setup')->assertOk()
        ->assertSee('composer require artisan-build/wake-client')
        ->assertSee('WAKE_URL=https://wake.example.com')
        ->assertSee('WAKE_TOKEN=the-token-shown-by-wake')
        ->assertSee('LOG_STACK=single,wake')
        ->assertSee('client appends <code>/api/v1/ingest</code>', false)
        ->assertSee('without changing them by default')
        ->assertSee('secrets, tokens, personal data, or other sensitive values')
        ->assertSee('redact them in the client before delivery.');

    expect($response->getContent())->not->toContain('Log viewer', 'Search logs', '<canvas');
});

it('documents the real filter contract binding and drop behavior as escaped code', function (): void {
    $response = $this->get('/docs/agent-redaction')->assertOk()
        ->assertSee('PayloadFilter::filter(LogEvent $event): ?LogEvent')
        ->assertSee('Return <code>null</code> to drop the event.', false)
        ->assertSee('PayloadFilter::class, RedactWakePayload::class')
        ->assertSee('public function register(): void')
        ->assertSee('&lt;?php', false)
        ->assertDontSee('<?php', false);

    expect($response->getContent())->not->toContain('<script>', 'Log viewer', 'Search logs', '<canvas');
});

it('documents retention ordering and the exact cost basis', function (): void {
    $response = $this->get('/docs/retention-cost')->assertOk();
    $content = $response->getContent();

    $response->assertSee('30 days by default')
        ->assertSee('whole-UTC-day cutoff')
        ->assertSee('about 56 MB per 1 million log lines')
        ->assertSee('minute flush keeps PostgreSQL awake')
        ->assertSee('about $25 per month')
        ->assertSee('measured 35-second daily rewrite')
        ->assertSee('2 GiB Flex managed queue adds about $0.14 per month')
        ->assertSee('before object-storage request charges');

    expect(strpos($content, 'prunes the corresponding locator'))
        ->toBeLessThan(strpos($content, 'expires superseded DuckLake snapshots'))
        ->and(strpos($content, 'expires superseded DuckLake snapshots'))
        ->toBeLessThan(strpos($content, 'cleans up the physical files'));
});
