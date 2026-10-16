# REMEDIATION.md

Read this first.

Level: draft-pr

- Start with a failing test that reproduces the problem, then revert the fix and confirm that the same test fails again for the expected reason.
- Run `composer ready` before opening a pull request. If it fails, stop there.
- Open every pull request as a draft. Include `Fixes #N` and assign it to `edgrosvenor`. Never merge it or mark it ready for review.
- Never change `database/migrations`, `config/`, `composer.json`, `composer.lock`, `.github/`, `app/Mcp/**`, or anything involving authentication, tokens, ingest credentials, or the relay. Never add or upgrade a dependency. Stop and comment on the issue instead.
