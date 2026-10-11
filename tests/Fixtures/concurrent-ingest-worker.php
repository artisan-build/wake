<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $ready, $go, $result, $secret, $key, $encodedBody] = $argv;

file_put_contents($ready, 'ready');

$deadline = microtime(true) + 10;
while (! file_exists($go)) {
    if (microtime(true) >= $deadline) {
        exit(2);
    }

    usleep(1_000);
}

$body = base64_decode($encodedBody, true);
if ($body === false) {
    exit(3);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$request = Request::create('/api/v1/ingest', 'POST', [], [], [], [
    'HTTP_AUTHORIZATION' => "Bearer {$secret}",
    'HTTP_IDEMPOTENCY_KEY' => $key,
    'CONTENT_TYPE' => 'application/json',
], $body);
$response = $kernel->handle($request);

file_put_contents($result, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR));

$kernel->terminate($request, $response);
