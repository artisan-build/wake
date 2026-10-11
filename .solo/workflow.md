# Workflow - {{FILL: app name}}

Project profile for the `multi-agent-build` skill and every agent working in this Built for Cloud
app. The coordinator reads this first. Replace every `{{FILL: ...}}` marker before dispatching work,
then keep the profile truthful as the app evolves.

This repository is an app scaffolded from `artisan-build/built-for-cloud-starter`; it is not the
starter kit. Never open app pull requests against the starter kit or its nodeless upstream.

## What This Is

{{FILL: Describe the product, its users, the problem it solves, and its explicit non-goals.}}

## Phase And Mode

- phase: {{FILL: greenfield | building | launched}}
- default mode: {{FILL: A-autonomous | B-human-merges}}
- merge policy: {{FILL: State when changes may merge and whether human review is required.}}
- merge method: {{FILL: State the exact gh command and branch deletion policy.}}

## Hard Gate

- command: `composer ready` (ide-helper regen + rector + pint + phpstan + pest + composer audit)
- extra suites: {{FILL: none, or list them}}
- monorepo: {{FILL: no, or describe the package layout}}

The coordinator verifies the hard gate on the committed SHA with a clean tree.

## CI

- status: {{FILL: verified | unverified}}
- required jobs: {{FILL: Name the testing and static-analysis jobs that gate merges.}}
- workflows: `.github/workflows/tests.yml` and `.github/workflows/lint.yml`

Do not use CI as an autonomous merge gate until both testing and static analysis are verified.

## Dependency Install

- command: `composer install --no-interaction --prefer-dist`
- post-install: `cp .env.example .env && php artisan key:generate`
- tests use in-memory SQLite through `phpunit.xml`

## Harness Map

- implementer: {{FILL: runtime and Solo agent_tool_id}}
- quality reviewer: {{FILL: different model lineage and Solo agent_tool_id}}
- acceptance judge: {{FILL: another model lineage and Solo agent_tool_id}}

## Ship Details

- branch naming: `feat/<slug>` (`fix/<slug>` for fixes, `chore/<slug>` for maintenance)
- PR target repo: {{FILL: owner/repo - REQUIRED}}
- release or split steps: {{FILL: none, or describe them}}

## Plan And Coordination

- plan location: {{FILL: Solo scratchpad or standing PRD path}}
- Solo project: {{FILL: project name and ID - REQUIRED}}
- run log: per-build scratchpad named `<branch>-run-log`

## Built For Cloud App

- app role: {{FILL: Describe how this app participates in the Built for Cloud ecosystem.}}
- manifest: {{FILL: Record the manifest path and ownership once configured.}}
- deployment: {{FILL: Record the Laravel Cloud environment conventions without secrets or app IDs.}}

## Stack Notes

- Laravel 13, Livewire 4, Flux 2, and PHP 8.3 or newer.
- Nodeless by design: no Node, npm, Vite, or frontend build step.
- Tailwind is served from `public/build/assets/app.css`; regenerate it only with
  `php artisan tailwind:optimize` and commit the output.
- `composer ready` regenerates committed IDE helper files. `.phpstorm.meta.php` remains ignored.
- Keep `phpstan-baseline.neon` shrinking.
- {{FILL: Add app-specific quirks and conventions, or delete this line if there are none.}}
