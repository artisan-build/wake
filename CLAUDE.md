# Wake

Wake is a self-hosted central log sink for organisations with many Laravel applications. A Laravel
client sends logs to one Wake installation, where agents investigate them through MCP. Wake is
installed in the customer's Laravel Cloud account as a Built for Cloud product. The v1 scope excludes
a human log viewer, alerts, in-app inference, a Rust ingest service, non-Laravel sources, and Scalpels
catalog changes.

## Workflow

See `.solo/workflow.md` for this app's repository, merge policy, hard gate, CI, and coordination
details. Complete that file before delegating work.

Hard gate before any PR: `composer ready` (ide-helper + rector + pint + phpstan + pest + audit).

## No Frontend Build Step

Non-negotiable: **no Node, no npm, no Vite, no frontend build step.**
Do not introduce one.

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. A class absent
from that bundle silently does nothing. To use new classes, delete the existing bundle, run
`php artisan tailwind:optimize`, verify the classes landed, and commit the regenerated file. The
optimizer uses a standalone Tailwind binary and does not introduce Node tooling.

## Built For Cloud

Wake uses Built for Cloud for authentication, teams, product UI, MCP admission, and installation
credentials. Its app manifest is maintained in `config/built-for-cloud.php`.

### Setting the manifest

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

Laravel Cloud provisions resource configuration. Do not commit secrets, app IDs, personal config,
machine-specific paths, or hand-written values that shadow Cloud-managed resource variables.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose because PHPStan scans the
model helper. `.phpstorm.meta.php` is gitignored because it embeds absolute local paths. Do not make
these files consistent in either direction.

## Static Analysis

The PHPStan baseline lives in `phpstan-baseline.neon`. Keep it shrinking; never grow it silently.
