<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Console;

use ArtisanBuild\WakeClient\Logging\WakeHandler;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Monolog\Level;
use Monolog\LogRecord;

final class WakeDoctorCommand extends Command
{
    protected $signature = 'wake:doctor';

    protected $description = 'Check Wake configuration and ingest reachability';

    public function handle(WakeHandler $handler): int
    {
        $url = config('wake.url');
        $token = config('wake.token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            $this->components->error('Wake is not fully configured. Check the URL and token settings.');

            return self::FAILURE;
        }

        $succeeded = $handler->probe(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'wake-doctor',
            level: Level::Info,
            message: 'Wake doctor round trip',
            context: ['correlation_id' => 'wake-doctor'],
        ));
        if (! $succeeded) {
            $this->components->error('Wake could not confirm an ingest round trip.');

            return self::FAILURE;
        }

        $this->components->info('Wake configuration and ingest round trip are healthy.');

        return self::SUCCESS;
    }
}
