<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

final readonly class DuckDbInstaller
{
    private const string VERSION = '1.5.6';

    /** @var array<string, array{asset: string, checksum: string}> */
    private const array ASSETS = [
        'Darwin-arm64' => [
            'asset' => 'duckdb_cli-osx-arm64.zip',
            'checksum' => '8e0f6825653f8d057922e6147db920bebf072cb41f4b041fd35521c18d7d126e',
        ],
        'Darwin-x86_64' => [
            'asset' => 'duckdb_cli-osx-amd64.zip',
            'checksum' => 'ae74c8cd74304bde1d92d941aca37bc72084ee8daa5660913819d8a9e9d29331',
        ],
        'Linux-aarch64' => [
            'asset' => 'duckdb_cli-linux-arm64.zip',
            'checksum' => 'c544e92c9b7c31fc53c2139802cabd8e2d1b2b3e3f933117f31611239c1402db',
        ],
        'Linux-arm64' => [
            'asset' => 'duckdb_cli-linux-arm64.zip',
            'checksum' => 'c544e92c9b7c31fc53c2139802cabd8e2d1b2b3e3f933117f31611239c1402db',
        ],
        'Linux-x86_64' => [
            'asset' => 'duckdb_cli-linux-amd64.zip',
            'checksum' => '6e89deac1ebbc36eed0291caf8b567b030c7b86ac35998f71854e22b3c5d5e2f',
        ],
    ];

    private string $root;

    private string $osFamily;

    private string $machine;

    /** @var array<string, array{asset: string, checksum: string}> */
    private array $assets;

    /**
     * @param  null|Closure(string, string): void  $download
     * @param  null|Closure(list<string>): void  $run
     * @param  null|array<string, array{asset: string, checksum: string}>  $assets
     */
    public function __construct(
        ?string $root = null,
        ?string $osFamily = null,
        ?string $machine = null,
        private ?Closure $download = null,
        private ?Closure $run = null,
        ?array $assets = null,
    ) {
        $this->root = $root ?? base_path();
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->machine = $machine ?? strtolower(php_uname('m'));
        $this->assets = $assets ?? self::ASSETS;
    }

    public function install(): bool
    {
        $asset = $this->asset();
        $binary = $this->root.'/bin/duckdb';
        $extensionDirectory = $this->root.'/var/duckdb-extensions';
        $marker = $extensionDirectory.'/.wake-install.json';

        if ($this->isCurrent($binary, $marker, $asset)) {
            return false;
        }

        $temporaryDirectory = $this->root.'/storage/app/duckdb-installer/'.bin2hex(random_bytes(8));
        $archive = $temporaryDirectory.'/'.$asset['asset'];
        $stagedBinary = $temporaryDirectory.'/duckdb';

        $this->makeDirectory($temporaryDirectory);

        try {
            $this->download($this->downloadUrl($asset['asset']), $archive);
            $actualChecksum = $this->checksum($archive);

            if (! hash_equals($asset['checksum'], $actualChecksum)) {
                throw new RuntimeException("DuckDB archive checksum mismatch for {$asset['asset']}.");
            }

            $this->extractBinary($archive, $temporaryDirectory, $stagedBinary);
            $this->makeDirectory(dirname($binary));
            $this->makeDirectory($extensionDirectory);
            chmod($stagedBinary, 0755);

            $escapedExtensionDirectory = str_replace("'", "''", $extensionDirectory);
            $this->run([
                $stagedBinary,
                '-unsigned',
                '-c',
                "SET extension_directory='{$escapedExtensionDirectory}'; INSTALL ducklake; INSTALL postgres; INSTALL httpfs;",
            ]);

            if (! rename($stagedBinary, $binary)) {
                throw new RuntimeException('Unable to install the DuckDB binary.');
            }

            $this->writeMarker($marker, [
                'version' => self::VERSION,
                'asset' => $asset['asset'],
                'archive_checksum' => $asset['checksum'],
                'binary_checksum' => $this->checksum($binary),
            ]);
        } finally {
            $this->deleteDirectory($temporaryDirectory);
        }

        return true;
    }

    /** @return array{asset: string, checksum: string} */
    private function asset(): array
    {
        $platform = $this->osFamily.'-'.$this->machine;

        return $this->assets[$platform] ?? throw new RuntimeException("Unsupported platform: {$platform}.");
    }

    /**
     * @param  array{asset: string, checksum: string}  $asset
     */
    private function isCurrent(string $binary, string $marker, array $asset): bool
    {
        if (! is_file($binary) || ! is_executable($binary) || ! is_file($marker)) {
            return false;
        }

        try {
            $state = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($state)
            && ($state['version'] ?? null) === self::VERSION
            && ($state['asset'] ?? null) === $asset['asset']
            && ($state['archive_checksum'] ?? null) === $asset['checksum']
            && is_string($state['binary_checksum'] ?? null)
            && hash_equals($state['binary_checksum'], $this->checksum($binary));
    }

    private function downloadUrl(string $asset): string
    {
        return 'https://github.com/duckdb/duckdb/releases/download/v'.self::VERSION.'/'.$asset;
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            throw new RuntimeException("Unable to checksum file: {$path}");
        }

        return $checksum;
    }

    private function download(string $url, string $destination): void
    {
        if ($this->download !== null) {
            ($this->download)($url, $destination);

            return;
        }

        Http::withHeaders(['User-Agent' => 'Wake-DuckDB-Installer'])
            ->timeout(120)
            ->retry(2, 500)
            ->withOptions(['sink' => $destination])
            ->get($url)
            ->throw();

        if (! is_file($destination)) {
            throw new RuntimeException('Unable to write the downloaded DuckDB archive.');
        }
    }

    private function extractBinary(string $archive, string $directory, string $binary): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Unable to open the downloaded DuckDB archive.');
        }

        try {
            if ($zip->locateName('duckdb', ZipArchive::FL_NODIR) === false || ! $zip->extractTo($directory, ['duckdb'])) {
                throw new RuntimeException('The DuckDB archive does not contain the expected binary.');
            }
        } finally {
            $zip->close();
        }

        if (! is_file($binary)) {
            throw new RuntimeException('The DuckDB binary could not be extracted.');
        }
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        if ($this->run !== null) {
            ($this->run)($command);

            return;
        }

        $process = new Process($command, $this->root, timeout: 300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('DuckDB extension installation failed: '.trim($process->getErrorOutput()));
        }
    }

    /** @param array{version: string, asset: string, archive_checksum: string, binary_checksum: string} $state */
    private function writeMarker(string $marker, array $state): void
    {
        $temporaryMarker = $marker.'.tmp';
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

        if (file_put_contents($temporaryMarker, $json) === false || ! rename($temporaryMarker, $marker)) {
            throw new RuntimeException('Unable to record the DuckDB installation state.');
        }
    }

    private function makeDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
