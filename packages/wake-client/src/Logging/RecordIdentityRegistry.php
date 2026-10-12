<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use DateTimeImmutable;
use Monolog\LogRecord;
use WeakMap;

/**
 * Assign one weak identity to each Monolog dispatch without changing record data.
 * LogRecord clones retain the same DateTimeImmutable object, so nested handlers
 * can recover the identity stamped after the logger's other processors run.
 */
final class RecordIdentityRegistry
{
    /** @var WeakMap<DateTimeImmutable, object> */
    private WeakMap $identities;

    public function __construct()
    {
        $this->identities = new WeakMap;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $this->identities[$record->datetime] = new \stdClass;

        return $record;
    }

    public function identity(LogRecord $record): ?object
    {
        return $this->identities[$record->datetime] ?? null;
    }
}
