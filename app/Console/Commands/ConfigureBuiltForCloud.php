<?php

declare(strict_types=1);

namespace App\Console\Commands;

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Commands\Concerns\WritesInstallEnv;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Install\InstallTargetState;
use ArtisanBuild\BuiltForCloud\LandingManifest;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

final class ConfigureBuiltForCloud extends Command
{
    use WritesInstallEnv;

    /** @var list<string> */
    private const array MANIFEST_KEYS = ['name', 'slug', 'description', 'icon', 'product_url'];

    /** @var list<string> */
    private const array UI_KEYS = [
        'landing_page',
        'member_management',
        'personal_credentials',
        'installation_credentials',
        'session_management',
        'managed_transitions',
        'credential_purposes',
    ];

    protected $signature = 'bfc:starter:configure
        {--spec= : Absolute path to the product configuration JSON}
        {--force-operator-credential : Deliberately mint another operator credential}';

    protected $description = 'Configure this generated Built for Cloud product locally';

    public function handle(): int
    {
        try {
            $configuration = $this->configurationFromSpec($this->option('spec'));
            $scaffold = $this->installServerScaffold(
                base_path('.env'),
                base_path('composer.json'),
                [],
                [],
            );

            if (! $scaffold->succeeded()) {
                $this->summarize($scaffold->stages());

                return self::FAILURE;
            }

            $configState = $this->writeConfiguration(
                config_path('built-for-cloud.php'),
                $configuration,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $stages = [...$scaffold->stages(), 'configuration' => $configState->value];
        $mintOutput = new BufferedOutput;

        try {
            $mintResult = $this->runCommand(
                'bfc:install:operator-credential',
                (bool) $this->option('force-operator-credential') ? ['--force' => true] : [],
                $mintOutput,
            );
        } catch (Throwable) {
            $this->error('The operator credential could not be minted.');
            $this->summarize($stages);

            return self::FAILURE;
        }

        if ($mintResult !== self::SUCCESS) {
            $this->error('The operator credential could not be minted.');
            $this->summarize($stages);

            return self::FAILURE;
        }

        $this->output->write($mintOutput->fetch());

        $this->summarize($stages);

        return self::SUCCESS;
    }

    /**
     * @return array{manifest: array<string, string>, credentials: array{guard: string, declaration: null, session_guard: null, app_purposes: array<string, string>}, ui: array<string, bool|list<string>>}
     */
    private function configurationFromSpec(mixed $path): array
    {
        if (! is_string($path)
            || ! str_starts_with($path, DIRECTORY_SEPARATOR)
            || ! is_file($path)
            || is_link($path)) {
            throw new InvalidArgumentException('The --spec path must be an absolute regular file.');
        }

        try {
            $contents = (string) file_get_contents($path);
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            $shape = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The configuration spec must contain valid JSON.', previous: $exception);
        }

        if (! is_array($document) || array_is_list($document) || ! $shape instanceof \stdClass) {
            throw new InvalidArgumentException('The configuration spec must contain an object.');
        }

        if (! ($shape->manifest ?? null) instanceof \stdClass
            || ! ($shape->credentials ?? null) instanceof \stdClass
            || ! ($shape->credentials->app_purposes ?? null) instanceof \stdClass
            || ! ($shape->ui ?? null) instanceof \stdClass
            || ! is_array($shape->ui->credential_purposes ?? null)) {
            throw new InvalidArgumentException('The configuration spec object and list members are invalid.');
        }

        $this->assertExactKeys($document, ['manifest', 'credentials', 'ui'], 'configuration spec');
        $manifest = $this->objectMember($document, 'manifest');
        $credentials = $this->objectMember($document, 'credentials');
        $ui = $this->objectMember($document, 'ui');
        $this->assertExactKeys($manifest, self::MANIFEST_KEYS, 'manifest');
        $this->assertExactKeys($credentials, ['app_purposes'], 'credentials');
        $this->assertExactKeys($ui, self::UI_KEYS, 'ui');

        foreach ($manifest as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Every manifest value must be a string.');
            }
        }

        $appPurposes = $this->objectMember($credentials, 'app_purposes');
        $this->assertPackageConfiguration($manifest, $appPurposes);

        foreach (array_slice(self::UI_KEYS, 0, -1) as $key) {
            if (! is_bool($ui[$key])) {
                throw new InvalidArgumentException('Every UI affordance must be boolean.');
            }
        }

        $displayed = $ui['credential_purposes'];
        if (! is_array($displayed) || ! array_is_list($displayed)) {
            throw new InvalidArgumentException('The UI credential purposes must be an ordered list.');
        }

        if (count($displayed) !== count(array_unique($displayed, SORT_REGULAR))) {
            throw new InvalidArgumentException('The UI credential purposes must be duplicate-free.');
        }

        foreach ($displayed as $appPurpose) {
            if (! is_string($appPurpose) || ! array_key_exists($appPurpose, $appPurposes)) {
                throw new InvalidArgumentException('Every UI credential purpose must name a configured app purpose.');
            }
        }

        /** @var array<string, string> $manifest */
        /** @var array<string, string> $appPurposes */
        /** @var array<string, bool|list<string>> $ui */
        return [
            'manifest' => $manifest,
            'credentials' => [
                'guard' => 'bfc',
                'declaration' => null,
                'session_guard' => null,
                'app_purposes' => $appPurposes,
            ],
            'ui' => $ui,
        ];
    }

    /**
     * @param  array<string, string>  $manifest
     * @param  array<string, mixed>  $appPurposes
     */
    private function assertPackageConfiguration(array $manifest, array $appPurposes): void
    {
        $repository = resolve(Repository::class);
        $manifestKey = implode('.', ['built-for-cloud', 'manifest']);
        $purposesKey = implode('.', ['built-for-cloud', 'credentials', 'app_purposes']);
        $previousManifest = $repository->get($manifestKey);
        $previousPurposes = $repository->get($purposesKey);

        try {
            $repository->set($manifestKey, $manifest);
            $repository->set($purposesKey, $appPurposes);
            LandingManifest::fromConfiguration();
            $registry = resolve(AppPurposeRegistry::class);

            foreach ($appPurposes as $appPurpose => $purpose) {
                if (! is_string($appPurpose)
                    || ! is_string($purpose)
                    || CredentialPurpose::tryFrom($purpose) === CredentialPurpose::SigningRoot
                    || $registry->purpose($appPurpose)->value !== $purpose) {
                    throw new InvalidArgumentException('A credential app-purpose mapping is invalid.');
                }
            }
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The spec does not satisfy the Built for Cloud package schema.', previous: $exception);
        } finally {
            $repository->set($manifestKey, $previousManifest);
            $repository->set($purposesKey, $previousPurposes);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $expected
     */
    private function assertExactKeys(array $values, array $expected, string $member): void
    {
        $keys = array_keys($values);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new InvalidArgumentException("The {$member} keys are invalid.");
        }
    }

    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function objectMember(array $document, string $key): array
    {
        $value = $document[$key] ?? null;

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("The {$key} member must contain an object.");
        }

        return $value;
    }

    /**
     * @param  array{manifest: array<string, string>, credentials: array{guard: string, declaration: null, session_guard: null, app_purposes: array<string, string>}, ui: array<string, bool|list<string>>}  $configuration
     */
    private function writeConfiguration(string $path, array $configuration): InstallTargetState
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('The Built for Cloud configuration target is invalid.');
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($configuration, true).";\n";
        $current = file_get_contents($path);

        if ($current === $contents) {
            return InstallTargetState::Unchanged;
        }

        $temporary = tempnam(dirname($path), '.bfc-starter-');
        if (! is_string($temporary)) {
            throw new RuntimeException('The Built for Cloud configuration could not be staged.');
        }

        try {
            if (! chmod($temporary, fileperms($path) & 0777)
                || file_put_contents($temporary, $contents) === false
                || ! rename($temporary, $path)) {
                throw new RuntimeException('The Built for Cloud configuration could not be written.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return InstallTargetState::Replaced;
    }
}
