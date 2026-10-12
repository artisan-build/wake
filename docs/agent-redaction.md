# Agent redaction guide

Wake sends and stores each log payload raw by default. Exception messages and context can contain secrets, tokens, personal data, or other sensitive values. Redact those values in the client app before Wake receives them.

The client calls `ArtisanBuild\WakeClient\Contracts\PayloadFilter::filter(LogEvent $event): ?LogEvent` for every event. Return a replacement `LogEvent` to change a payload. Return `null` to drop the event.

Create a filter in the client app:

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeContracts\LogEvent;

final class RedactWakePayload implements PayloadFilter
{
    public function filter(LogEvent $event): ?LogEvent
    {
        if ($event->channel === 'private-audit') {
            return null;
        }

        return new LogEvent(
            eventId: $event->eventId,
            occurredAt: $event->occurredAt,
            level: $event->level,
            channel: $event->channel,
            message: $event->message,
            context: $this->redact($event->context),
            extra: $this->redact($event->extra),
            exception: $event->exception,
            environment: $event->environment,
            hostname: $event->hostname,
            correlationId: $event->correlationId,
        );
    }

    /** @param array<string, mixed> $values */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token/i', $key) === 1) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
```

Bind it in the client app's `App\Providers\AppServiceProvider`:

```php
use App\Logging\RedactWakePayload;
use ArtisanBuild\WakeClient\Contracts\PayloadFilter;

public function register(): void
{
    $this->app->singleton(PayloadFilter::class, RedactWakePayload::class);
}
```

Adapt the key list and event-drop rule to the data the app writes. Test the filter with representative nested context before connecting the app to Wake.

Continue with [setup](/docs/setup), [retention and cost](/docs/retention-cost), or the [ingest contract](/docs/ingest-contract).
