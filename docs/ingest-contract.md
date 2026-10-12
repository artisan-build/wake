# Wake ingest contract v1

This document defines the HTTP and staging interfaces between a Wake client and server. It is the normative contract
for envelope version 1. The PHP classes in `artisan-build/wake-contracts` implement the same rules, but another
implementation does not need PHP or Laravel.

The words MUST, MUST NOT, SHOULD, and MAY have their usual requirements-language meanings.

## HTTP request

Send a `POST` request to `/api/v1/ingest` with these headers:

```http
Authorization: Bearer <app credential>
Content-Type: application/json
Idempotency-Key: 01ARZ3NDEKTSV4RRFFQ69G5FAX
```

The decoded request body MUST be no larger than 1,048,576 bytes. App identity comes from the bearer credential. Never
from JSON. The body MUST NOT contain `app`, `app_id`, `application_id`, or any equivalent identity field; a server
rejects an unknown field instead of ignoring it, so a caller cannot smuggle identity or accidentally rely on data the
server does not trust.

Minting an installation-owned Wake ingest credential also creates its connected-app record. Both writes use the same
database transaction, so a credential is not issued when the app record cannot be stored. Wake keeps the credential's
`subject_ref` as an external label and assigns its own app ID. Staging rows, health queries, and lake rows use that Wake
ID. Provisioning happens at mint so a successful credential response guarantees the app mapping exists before the
client can send its first batch. An app created through the Wake admin keeps the supplied slug as its ID and credential
label.

The `Idempotency-Key` header and body `idempotency_key` MUST match exactly. The key is 1–128 bytes and contains only
ASCII letters, digits, `.`, `_`, `:`, or `-`.

This is a complete request body:

```json
{
  "envelope_version": 1,
  "client_version": "1.2.3",
  "dropped_count": 7,
  "idempotency_key": "01ARZ3NDEKTSV4RRFFQ69G5FAX",
  "rows": [
    {
      "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
      "occurred_at": "2026-10-11T12:34:56.123456+00:00",
      "level": "error",
      "channel": "production",
      "message": "Payment failed",
      "context": {"order_id": 42},
      "extra": {"memory": 1024},
      "exception": {
        "class": "RuntimeException",
        "message": "Gateway unavailable",
        "file": "/var/www/app/Service.php",
        "line": 42,
        "trace": "#0 /var/www/app/Job.php(10): fail()\n#1 {main}"
      },
      "environment": "production",
      "hostname": "web-1",
      "correlation_id": "req-1"
    }
  ]
}
```

### Envelope fields

| Field | JSON type | Required | Rule |
| --- | --- | --- | --- |
| `envelope_version` | integer | yes | Positive. Version 1 is current. |
| `client_version` | string | yes | 1–64 UTF-8 bytes. |
| `dropped_count` | integer | yes | Zero or greater. See drop accounting below. |
| `idempotency_key` | string | yes | 1–128 bytes, restricted ASCII alphabet described above. |
| `rows` | array | yes | Between 1 and 500 event objects. |

`dropped_count` is the number of events the client has dropped since its last successfully acknowledged batch. A
client resets this count only after any 2xx response. A retry sends the same count and the same idempotency key. The
server records that count once when it first accepts the key.

### Event fields

| Field | JSON type | Required | Rule |
| --- | --- | --- | --- |
| `event_id` | string | yes | Canonical uppercase 26-character ULID. The first character is `0`–`7`. |
| `occurred_at` | string | yes | RFC 3339 timestamp with exactly six fractional digits and `Z` or a numeric offset. |
| `level` | string | yes | One of `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug`. |
| `channel` | string | yes | 1–255 UTF-8 bytes. |
| `message` | string | yes | 0–65,535 UTF-8 bytes. |
| `context` | object | yes | JSON object whose canonical encoding is at most 65,536 bytes. |
| `extra` | object | yes | JSON object whose canonical encoding is at most 65,536 bytes. |
| `exception` | object or null | no | Shape and limits are below. |
| `environment` | string | yes | 1–255 UTF-8 bytes. |
| `hostname` | string | yes | 1–255 UTF-8 bytes. |
| `correlation_id` | string or null | no | When present, 1–255 UTF-8 bytes. |

`context` and `extra` may contain strings, finite numbers, booleans, nulls, arrays, and objects. They MUST NOT contain
values outside the JSON data model. Object keys are strings.

An `exception` has exactly these fields:

| Field | JSON type | Required | Rule |
| --- | --- | --- | --- |
| `class` | string | yes | 1–255 UTF-8 bytes. |
| `message` | string | yes | 0–65,535 UTF-8 bytes. |
| `file` | string | yes | 1–4,096 UTF-8 bytes. |
| `line` | integer | yes | Positive. |
| `trace` | string | yes | 0–65,535 UTF-8 bytes. The client truncates before sending. |

Unknown fields are invalid at every defined request level: envelope, event, and exception. An additive protocol change
therefore requires a new supported envelope version instead of silently turning an unknown value into trusted data.

## Version negotiation

The server has a current version and an explicit set of supported versions. It accepts a version only when the version
is positive, is no newer than the server, and appears in that supported set. Version 1 servers have the supported set
`[1]`. A later server may keep older versions in that set while adding a newer parser.

A request newer than the server returns 422 with `unsupported_envelope_version`. Its message names both the received
version and the server's supported maximum. Clients do not downgrade automatically unless they implement the older
contract themselves.

## Acceptance and idempotency

Idempotency is scoped to `(credential-derived app_id, idempotency_key)`. Different apps may use the same key. Once a
key has been accepted, replays MUST return its original `batch_id` and `accepted_count`; a replay never creates another
staging batch.

The server returns 202 only after the complete batch is durably committed to Postgres staging. A 202 does not mean the
events are in DuckLake yet. It means a later process can flush them after the ingest process exits or crashes.

First acceptance returns HTTP 202 and:

```json
{
  "batch_id": "01ARZ3NDEKTSV4RRFFQ69G5FAW",
  "accepted_count": 1,
  "duplicate": false
}
```

A replay of the accepted key also returns HTTP 202 with the original ID and count:

```json
{
  "batch_id": "01ARZ3NDEKTSV4RRFFQ69G5FAW",
  "accepted_count": 1,
  "duplicate": true
}
```

Receipt batch IDs are canonical uppercase ULIDs. `accepted_count` is 1–500. Responses use
`Content-Type: application/json`.

## Error responses

Error bodies use this shape:

```json
{
  "status": 422,
  "error": "validation_failed",
  "message": "$.rows must contain between 1 and 500 events",
  "details": {"path": "$.rows"}
}
```

`details` is optional. When present, it is a JSON object whose canonical encoding is at most 16,384 bytes. `message`
is 1–1,024 UTF-8 bytes. The status and code combinations are fixed:

| HTTP | `error` | Meaning | Retry |
| ---: | --- | --- | --- |
| 401 | `unauthorized` | Credential is absent, invalid, expired, or revoked. | Obtain a valid credential first. |
| 403 | `forbidden` | Credential is valid but not allowed to ingest. | Do not retry unchanged. |
| 413 | `payload_too_large` | Decoded body exceeds 1,048,576 bytes. | Split or reduce the batch. |
| 422 | `validation_failed` | Body, headers, or field values violate this contract. | Correct the request first. |
| 422 | `unsupported_envelope_version` | Client envelope is newer than this server. | Upgrade the server or send a supported version. |
| 429 | `rate_limited` | The app's configured rate or volume cap was reached. | Wait for `Retry-After`. |

A 429 response MUST have a `Retry-After` HTTP header containing a positive integer number of seconds. Its body also
has `"retry_after": <same positive integer>`. Other error bodies MUST NOT contain `retry_after`.

Network failures and 5xx responses may be retried with backoff. Every retry keeps the original idempotency key,
request body, and `dropped_count`. A 202 ends the retry and allows the client to reset its drop counter. Clients SHOULD
honour a longer local backoff when it exceeds a server's `Retry-After` value.

## Durable staging row

The staging boundary is one Postgres row per accepted batch. It is not one staging row per log event. This shape is
part of the compatibility contract because PHP and a future Rust ingest must produce interchangeable rows.

| Column | PostgreSQL type | Null | Source or rule |
| --- | --- | --- | --- |
| `batch_id` | `char(26)` | no | Server-generated canonical ULID; primary key. |
| `app_id` | `varchar(255)` | no | Derived from the authenticated credential, never the request body. |
| `idempotency_key` | `varchar(128)` | no | Validated request key. Unique with `app_id`. |
| `envelope_version` | `smallint` | no | Validated envelope version. |
| `client_version` | `varchar(64)` | no | Validated client version. |
| `dropped_count` | `bigint` | no | Validated non-negative drop count. |
| `payload` | `jsonb` | no | The complete validated envelope, including all event rows. |
| `row_count` | `smallint` | no | Number of payload rows, from 1 to 500. |
| `status` | `varchar(16)` | no | One of `pending`, `flushing`, `flushed`, `failed`. Use a check constraint, not a native database enum. |
| `created_at` | `timestamptz(6)` | no | Time the batch became durable. |
| `updated_at` | `timestamptz(6)` | no | Last lifecycle update. |
| `claimed_at` | `timestamptz(6)` | yes | Time a flusher claimed the batch. |
| `flushed_at` | `timestamptz(6)` | yes | Time lake and query-plane work completed. |

The unique key is `(app_id, idempotency_key)`. New rows start as `pending`. A worker may claim one as `flushing`.
Successful lake and query-plane completion sets `flushed`; a retriable failed attempt sets `failed`. Recovery may move
`failed` back to `pending`. The flush manifest added with the storage implementation must make those transitions
converge after a crash without duplicate or lost events.

All staging timestamps use the same six-digit RFC 3339 representation at the contract boundary. Database drivers may
bind them as native timestamp values. The serialized staging object uses the column names above and represents
`payload` as the envelope object, not as a quoted JSON string.

## Deterministic encoding

Contract serializers emit fields in the order shown in the examples. Keys inside `context`, `extra`, `details`, and
nested JSON objects are sorted lexicographically; array order is retained. JSON is UTF-8, uses unescaped Unicode and
slashes, preserves a decimal `.0`, and has no insignificant whitespace. Object order is irrelevant. Consumers MUST use
field names rather than depending on it.

Parsers reject malformed JSON, missing required fields, unknown contract fields, wrong JSON types, non-finite numbers,
and values outside the stated limits.

## DuckLake ownership and the Rust boundary

The lake partitions log events by UTC day only. It MUST NOT create app-by-day partitions. Files are sorted by
`(app, occurred_at)`, where `app` is the credential-derived identity stored as `app_id` in staging. DuckLake owns every
Parquet filename and its catalog metadata. Writers compact into large files rather than choosing one object per batch.

A future Rust service may implement this HTTP contract and insert the same durable Postgres staging rows. Connected
apps then switch only their Wake base URL; their request and retry behavior stays unchanged. Rust MUST NOT write
Parquet directly outside the DuckLake catalog transaction. The shared flusher remains responsible for lake writes,
derived query-plane updates, manifest convergence, compaction, and retention. That is the URL-swap boundary.
