# Set up a Laravel app

Create the app in [Wake admin](/apps). The token appears once, so copy it now. The Wake URL is the base URL of this installation. Do not add an ingest path; the client appends `/api/v1/ingest`.

Install the client.

```bash
composer require artisan-build/wake-client
```

Add the connection values to the client app:

```dotenv
WAKE_URL=https://wake.example.com
WAKE_TOKEN=the-token-shown-by-wake
```

With both values set, the client attaches Wake to an active default log channel backed by Monolog. This covers every built-in Laravel log driver and channels configured with `driver=monolog`. Existing log destinations keep receiving the same records. Set `WAKE_AUTO_ATTACH=false` to opt out and use the explicit `wake` channel instead.

If a custom default channel returns another PSR-3 logger, `wake:doctor` reports `not attached: default channel is not Monolog-backed`. Use a built-in Laravel log driver or configure the default channel with `driver=monolog`.

Keep the token out of source control. Rotate it from the app detail page if it is exposed, then replace the old value in the client app.

Wake sends log payloads without changing them by default, so read the [agent redaction guide](/docs/agent-redaction) before connecting an app that may log secrets, tokens, personal data, or other sensitive values and redact them in the client before delivery.

See [retention and cost](/docs/retention-cost) and the [ingest contract](/docs/ingest-contract) for the server-side behavior.
