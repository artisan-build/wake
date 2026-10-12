# Wake

Wake is a self-hosted central log sink for organisations that run many Laravel applications.

## Who It Is For

Wake is for teams that need one place where an agent can investigate logs across their Laravel
applications without sending the log corpus to a third-party service.

## What It Does

This repository contains the Built for Cloud application and the Wake contracts, Laravel client, and
server packages. Wake ingests logs into DuckLake, provides an administration surface, and exposes MCP
tools for agents to search and inspect the stored logs.

## Built For Cloud

Wake is installed into a customer's Laravel Cloud account as a Built for Cloud product. Its planned
Scalpels product page is [scalpels.app/products/wake](https://scalpels.app/products/wake).

## Setting the manifest

Before the first deploy, replace every placeholder in the `manifest` block of
`config/built-for-cloud.php`:

| Field | Set it to |
| --- | --- |
| `name` | The product's display name as Scalpels lists it. |
| `slug` | The product's lower-kebab-case Scalpels catalog slug. |
| `description` | One sentence saying what the product does, as on its Scalpels product page. |
| `icon` | `https://scalpels.app/img/products/transparent/{slug}.svg` |
| `product_url` | `https://scalpels.app/products/{slug}` |

The `slug` must exactly match the product's Scalpels catalog slug; its artwork loads from
`https://scalpels.app/img/products/transparent/{slug}.svg`.

## Local Development

```bash
composer setup
composer install:duckdb
composer dev
```

This app is nodeless by design. Do not add Node, npm, Vite, or a frontend build step. Static assets
are committed under `public/build`.

## Quality Gate

```bash
composer ready
```

Focused application tests can be run with `php artisan test --filter=<test-name>`. Each package has
its own `composer test`, `composer lint:test`, and `composer stan` commands. Run every package check
from the root with `composer packages:check`.

## Deployment

Artisan Build agents handling Laravel Cloud work must follow
`brain/skills/laravel-cloud-deploy/SKILL.md`. Start with read-only discovery. Credentials come from
the authenticated Laravel Cloud CLI; discover application and environment identifiers rather than
guessing or committing them. Wake keeps `.cloud/config.json` committed as its Cloud CLI project
configuration. Verify the application and its attached resources after every deployment.

## Repository

[github.com/artisan-build/wake](https://github.com/artisan-build/wake)
