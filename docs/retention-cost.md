# Retention and cost

Wake keeps logs for 30 days by default. Retention uses a whole-UTC-day cutoff, so it removes complete expired day partitions rather than part of a day.

The retention run removes expired lake rows, prunes the corresponding locator, hourly count, and error rows from PostgreSQL, expires superseded DuckLake snapshots, then cleans up the physical files. Cleanup runs last. Deleting a catalog row alone does not remove its object from the bucket.

Parquet storage is about 56 MB per 1 million log lines. Storage is usually not the largest cost in the smallest setup. The minute flush keeps PostgreSQL awake and dominates that setup at about $25 per month.

At the measured 35-second daily rewrite, Wake's 2 GiB Flex managed queue adds about $0.14 per month: about $0.13 for baseline polling and $0.01 for rewrite compute, before object-storage request charges.

Read [setup](/docs/setup), the [agent redaction guide](/docs/agent-redaction), or the [ingest contract](/docs/ingest-contract). Installation administrators can review app ingest health in [Wake admin](/apps).
