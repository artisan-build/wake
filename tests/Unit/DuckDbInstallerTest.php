<?php

declare(strict_types=1);

use App\Support\DuckDbInstaller;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/wake-duckdb-installer-'.bin2hex(random_bytes(8));
    mkdir($this->root, 0755, true);

    $this->archive = $this->root.'/fixture.zip';
    $zip = new ZipArchive;
    expect($zip->open($this->archive, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('duckdb', 'fixture-binary');
    $zip->close();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
});

it('installs a verified archive under the app root and skips a current installation', function (): void {
    $downloads = 0;
    $runs = [];
    $checksum = hash_file('sha256', $this->archive);
    expect($checksum)->toBeString();
    $assets = fixtureAssets($checksum);
    $installer = new DuckDbInstaller(
        root: $this->root,
        osFamily: 'TestOS',
        machine: 'test-machine',
        download: function (string $url, string $destination) use (&$downloads): void {
            $downloads++;
            expect($url)->toEndWith('/fixture.zip');
            copy($this->archive, $destination);
        },
        run: function (array $command) use (&$runs): void {
            $runs[] = $command;
        },
        assets: $assets,
    );

    expect($installer->install())->toBeTrue()
        ->and($installer->install())->toBeFalse()
        ->and($downloads)->toBe(1)
        ->and($runs)->toHaveCount(1)
        ->and($runs[0][0])->toStartWith($this->root.'/storage/app/duckdb-installer/')
        ->and($runs[0][3])->toContain($this->root.'/var/duckdb-extensions')
        ->and(file_get_contents($this->root.'/bin/duckdb'))->toBe('fixture-binary')
        ->and(is_executable($this->root.'/bin/duckdb'))->toBeTrue()
        ->and(is_file($this->root.'/var/duckdb-extensions/.wake-install.json'))->toBeTrue();
});

it('rejects an archive whose checksum does not match', function (): void {
    $installer = new DuckDbInstaller(
        root: $this->root,
        osFamily: 'TestOS',
        machine: 'test-machine',
        download: function (string $url, string $destination): void {
            copy($this->archive, $destination);
        },
        run: fn (array $command) => throw new RuntimeException('should not run'),
        assets: fixtureAssets(str_repeat('0', 64)),
    );

    expect(fn (): bool => $installer->install())
        ->toThrow(RuntimeException::class, 'DuckDB archive checksum mismatch')
        ->and(is_file($this->root.'/bin/duckdb'))->toBeFalse();
});

/** @return array<string, array{asset: string, checksum: string}> */
function fixtureAssets(string $checksum): array
{
    return [
        'TestOS-test-machine' => [
            'asset' => 'fixture.zip',
            'checksum' => $checksum,
        ],
    ];
}
