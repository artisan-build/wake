<?php

declare(strict_types=1);

use Tests\Support\ReferenceConsumerInventory;
use Tests\Support\ReferenceConsumerInventoryControls;

/** @param array<string, string> $files */
function inventoryControlRoot(array $files): string
{
    $root = sys_get_temp_dir().'/bfc-inventory-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $files += ['config/auth.php' => <<<'PHP'
<?php

use ArtisanBuild\BuiltForCloud\User;

return [
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
];
PHP];

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0700, true);
        }
        file_put_contents($root.'/'.$path, $contents);
    }

    return $root;
}

it('finds no app-owned auth or root surface in the exported starter tree', function (): void {
    expect(ReferenceConsumerInventory::inspect(dirname(__DIR__, 2)))
        ->toBe(array_fill_keys(ReferenceConsumerInventory::FAMILIES, []));
});

it('observes every independently introduced inventory control red', function (array $families, array $files): void {
    $root = inventoryControlRoot($files);

    try {
        $inventory = ReferenceConsumerInventory::inspect($root);
        foreach ($families as $family) {
            expect($inventory[$family])->not->toBe([]);
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
})->with(ReferenceConsumerInventoryControls::cases());
