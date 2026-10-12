# Workflow — Wake

Project profile for the `multi-agent-build` skill. Wake is a monorepo with the Laravel application at
the root and `packages/wake-contracts`, `packages/wake-client`, and `packages/wake-server` as path
repositories.

## Phase and mode

- phase: pre-launch
- default mode: A-autonomous
- merge policy: merge on green CI; no human PR code review (Ed, 2026-10-11)
- merge method: `gh pr merge --squash`
- independent review: every PR that changes authentication, credentials, secrets, authorization,
  signing, or durable storage/relay of outbound content the system did not author requires an
  independent quality reviewer and acceptance judge before merge.

## Hard gate

- command: `composer ready`
- root ladder: IDE helper generation, Rector, Pint, Larastan/PHPStan, the complete app test suite,
  Composer audit, then package checks.
- package checks: each package receives an independent Composer install followed by Pint, PHPStan,
  focused Pest tests, and Composer audit.
- monorepo installs: install dependencies at the root and independently in every touched package.

The coordinator runs the complete gate on a committed SHA with a clean tree. Implementers run focused
tests plus static analysis and lint only.

## CI

- workflow: `.github/workflows/tests.yml`
- status: unverified until the first PR run
- required contexts:
  - `root / PHP 8.5 / PostgreSQL 16`
  - `package / wake-contracts / PHP 8.5`
  - `package / wake-client / PHP 8.5`
  - `package / wake-server / PHP 8.5`
- release workflow: `.github/workflows/release.yml` splits all three packages on `v*` tags.

## Roles

Resolve implementer, quality reviewer, and acceptance judge roles from
`~/Herd/brain/agents.json` using `~/Herd/brain/playbooks/resolve-agent-role.md`. This profile does not
override Brain's role map.

## Ship details

- branch naming: `feat/<slug>` (`fix/<slug>` for fixes and `chore/<slug>` for maintenance)
- PR target repo: `artisan-build/wake`
- package mirrors: future repositories `artisan-build/wake-contracts`, `artisan-build/wake-client`,
  and `artisan-build/wake-server`; creating them is a release prerequisite, not part of a feature PR.
- release command: the tag workflow runs `php artisan kibble:split --tag="${RELEASE_TAG}"`.

## Plan and coordination

- plan location: `/Users/edgrosvenor/Herd/brain/projects/wake/PRD.md`, §13
- Solo project: `wake` (id 85)
- run log: a separate append-only scratchpad for each build

## Built for Cloud

- app role: a self-hosted central log sink installed in the customer's Laravel Cloud account.
- manifest: `config/built-for-cloud.php`, owned by the root application.
- deployment: use the `laravel-cloud-deploy` skill; never set variables supplied by Cloud resources.

## Migration immutability

- **Until the first release tag, the Wake Cloud environment is disposable.** When a candidate changes an existing
  migration, reset that environment's database and lake state before the smoke instead of writing upgrade migrations:
  `migrate:fresh` on the Wake environment (never any other app), drop the DuckLake catalog tables, and delete the lake's
  data path in the `wake-production` bucket. Confirm the environment ID is `env-a2f52d4e-a4e6-4a6a-96b4-a04ac506e881`
  before running anything destructive. Synthetic data only; nothing else may be touched.
- **From the first release tag on, migrations are immutable** and every schema change is a new additive migration.
  The immutability guard belongs then, not now; note it as a release prerequisite.

## Stack notes

- PHP 8.5 in CI, Laravel 13, Livewire 4, and Flux 2.
- PostgreSQL 16 is the application test database in CI.
- The app is nodeless. Do not add Node, npm, Vite, or a frontend build step.
- `composer ready` regenerates committed IDE helper files; `.phpstorm.meta.php` stays ignored.
- Keep `phpstan-baseline.neon` shrinking and never baseline new errors to make a gate pass.
