<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\Auth\Authenticatable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ReferenceConsumerInventory
{
    /** @var list<string> */
    public const array FAMILIES = [
        'app_human_identity',
        'auth_migrations',
        'fortify',
        'app_auth_surface',
        'foreign_human_guards',
        'starter_root_collision',
    ];

    /** @return array<string, list<string>> */
    public static function inspect(string $root): array
    {
        $found = array_fill_keys(self::FAMILIES, []);

        foreach (self::files($root) as $relative => $path) {
            $normalized = str_replace('\\', '/', $relative);
            $lower = strtolower($normalized);
            $contents = str_ends_with($lower, '.php') ? (string) file_get_contents($path) : '';

            if (str_starts_with($lower, 'app/')
                && str_ends_with($lower, '.php')
                && self::declaresHumanIdentity($contents)) {
                $found['app_human_identity'][] = $normalized;
            }

            if (self::isAuthMigration($lower, $contents)) {
                $found['auth_migrations'][] = $normalized;
            }

            if ($lower === 'config/fortify.php'
                || str_starts_with($lower, 'app/actions/fortify/')
                || str_contains($lower, 'fortifyserviceprovider.php')) {
                $found['fortify'][] = $normalized;
            }

            if (self::isAppAuthSurface($lower, $contents)) {
                $found['app_auth_surface'][] = $normalized;
            }

            if ($lower === 'resources/views/welcome.blade.php'
                || (str_starts_with($lower, 'routes/') && preg_match("#Route::[a-z_][a-z0-9_]*\s*\(\s*['\"]/['\"]#i", $contents) === 1)) {
                $found['starter_root_collision'][] = $normalized;
            }
        }

        $authPath = rtrim($root, DIRECTORY_SEPARATOR).'/config/auth.php';
        if (is_file($authPath)) {
            $auth = (static fn (string $path): mixed => require $path)($authPath);
            $expectedGuards = ['web' => ['driver' => 'session', 'provider' => 'users']];
            $expectedProviders = ['users' => ['driver' => 'eloquent', 'model' => User::class]];

            if (! is_array($auth)
                || ($auth['guards'] ?? null) !== $expectedGuards
                || ($auth['providers'] ?? null) !== $expectedProviders) {
                $found['foreign_human_guards'][] = 'config/auth.php';
            }
        } else {
            $found['foreign_human_guards'][] = 'config/auth.php:missing';
        }

        foreach ($found as &$members) {
            $members = array_values(array_unique($members));
            sort($members);
        }

        return $found;
    }

    private static function declaresHumanIdentity(string $contents): bool
    {
        if (preg_match('/\bclass\s+User\b/i', $contents) === 1) {
            return true;
        }

        $identityNames = ['Authenticatable'];
        $identityTypes = implode('|', array_map(
            static fn (string $type): string => preg_quote($type, '/'),
            [Authenticatable::class, \Illuminate\Foundation\Auth\User::class],
        ));
        preg_match_all(
            '/\buse\s+\\\\?(?:'.$identityTypes.')(?:\s+as\s+([a-z_][a-z0-9_]*))?\s*;/i',
            $contents,
            $imports,
            PREG_SET_ORDER,
        );

        foreach ($imports as $import) {
            if (($import[1] ?? '') !== '') {
                $identityNames[] = $import[1];
            } elseif (str_contains(strtolower($import[0]), 'foundation\\auth\\user')) {
                $identityNames[] = 'User';
            }
        }

        preg_match_all('/\bclass\s+[a-z_][a-z0-9_]*\s+([^\{;]*)\{/is', $contents, $declarations);
        foreach ($declarations[1] ?? [] as $declaration) {
            if (preg_match('/\b(?:extends|implements)\b/i', $declaration) !== 1) {
                continue;
            }

            if (preg_match('/\\\\?(?:'.$identityTypes.')\b/i', $declaration) === 1) {
                return true;
            }

            foreach (array_unique($identityNames) as $identityName) {
                if (preg_match('/(?<![a-z0-9_\\\\])'.preg_quote($identityName, '/').'(?![a-z0-9_])/i', $declaration) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isAuthMigration(string $path, string $contents): bool
    {
        if (! str_starts_with($path, 'database/migrations/') || ! str_ends_with($path, '.php')) {
            return false;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);

        return preg_match('/(?:^|_)(?:users?|auth(?:entication)?|passwords?|sessions?|passkeys?|webauthn|two_factor)(?:_|$)/', $name) === 1
            || preg_match("#Schema::(?:create|table)\s*\(\s*['\"](?:users|password_reset_tokens|sessions|passkeys)['\"]#i", $contents) === 1;
    }

    private static function isAppAuthSurface(string $path, string $contents): bool
    {
        $authPath = '#(?:^|[/_.-])(?:auth(?:entication)?|login|logout|register|registration|password|reset|forgot|confirm|verify|verification|two[-_]?factor|passkeys?|webauthn|security)(?:[/_.-]|$)#';

        if (str_starts_with($path, 'app/http/controllers/')) {
            return preg_match($authPath, substr($path, strlen('app/http/controllers/'))) === 1;
        }

        if (str_starts_with($path, 'app/livewire/')) {
            return preg_match($authPath, substr($path, strlen('app/livewire/'))) === 1
                || preg_match('/\b(?:Auth|Password)::|\bauth\s*\(/', $contents) === 1;
        }

        if (str_starts_with($path, 'resources/views/')) {
            return preg_match($authPath, substr($path, strlen('resources/views/'))) === 1;
        }

        if (! str_starts_with($path, 'routes/') || ! str_ends_with($path, '.php')) {
            return false;
        }

        return preg_match($authPath, pathinfo($path, PATHINFO_FILENAME)) === 1
            || preg_match("#Route::[a-z_][a-z0-9_]*\s*\(\s*['\"]/?(?:auth|login|logout|register|registration|forgot-password|reset-password|password|confirm-password|verify-email|email/verification)(?:[/.'\"]|$)#i", $contents) === 1;
    }

    /** @return array<string, string> */
    private static function files(string $root): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $files[substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1)] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }
}
