<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\CandidatePackageVersion;

function removeCandidateVersionFixture(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($root);
}

test('a changed starter constraint derives a compatible candidate archive version', function (): void {
    $root = sys_get_temp_dir().'/bfc-candidate-version-'.bin2hex(random_bytes(8));
    mkdir($root.'/composer-home', 0700, true);

    try {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $composer['name'] = 'built-for-cloud/candidate-version-control';
        $composer['require'] = ['artisan-build/built-for-cloud' => '^12.34'];
        unset($composer['require-dev'], $composer['scripts']);
        $composer['minimum-stability'] = 'stable';
        $candidateVersion = CandidatePackageVersion::fromStarterComposer($composer);
        $archivePath = $root.'/candidate.zip';
        $archive = new ZipArchive;
        expect($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        $archive->addFromString('candidate.txt', 'exact local candidate archive');
        $archive->close();

        $composer['repositories'] = [
            [
                'type' => 'package',
                'canonical' => true,
                'package' => [
                    'name' => 'artisan-build/built-for-cloud',
                    'version' => $candidateVersion,
                    'type' => 'library',
                    'dist' => [
                        'type' => 'zip',
                        'url' => 'file://'.$archivePath,
                        'reference' => 'candidate-version-control',
                    ],
                ],
            ],
            ['packagist.org' => false],
        ];
        file_put_contents(
            $root.'/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        $resolution = new Process([
            'composer', 'update', '--no-audit', '--no-interaction',
            '--no-plugins', '--no-progress', '--no-scripts',
        ], $root, [
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_HOME' => $root.'/composer-home',
        ], null, 120);
        $resolution->run();

        expect($resolution->isSuccessful())
            ->toBeTrue($resolution->getOutput().$resolution->getErrorOutput());

        $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $installed = array_column($lock['packages'] ?? [], null, 'name');

        expect($candidateVersion)->toBe('12.34.0')
            ->and($installed['artisan-build/built-for-cloud']['version'] ?? null)->toBe($candidateVersion)
            ->and($installed['artisan-build/built-for-cloud']['dist']['reference'] ?? null)
            ->toBe('candidate-version-control');
    } finally {
        removeCandidateVersionFixture($root);
    }
});
