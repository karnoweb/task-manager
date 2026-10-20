# Phase-three scale smoke evidence

This is a bounded smoke measurement, not the full phase-seven load gate. PostgreSQL 18.4, PHP 8.4.22, Windows, Intel Core i7-1355U (10 cores / 12 logical processors). The user's EnvKit MariaDB suite ran concurrently, so these are observed shared-machine timings, not isolated capacity guarantees.

The dedicated database tm_scale_p3_2c6b9e contains synthetic 10,000- and 100,000-member cohorts, real process rows, visits, assignment intervals/active slots, group membership and host access-control rows. No application database was used. Synthetic bulk fixture import bypasses per-record creation audit; measured closure commands use real services/guards/audit/outbox.

| Members | Preview seconds / SQL queries | Materialization seconds | First 250 items seconds | PHP peak |
|---:|---:|---:|---:|---:|
| 10,000 | 17.85 / 47 | 6.16 | 114.39 | 48 MiB |
| 100,000 | 63.73 / 407 | 68.82 | 103.22 | 48 MiB |

Both cohorts were fully previewed and their exact item versions materialized. Only the first 250 items of each were processed. Runs correctly remain processing, with 9,750 / 99,750 pending. No full 10k/100k completion or reliable percentile claim is made. The JSON's single-chunk p95 is one sample, not a latency distribution. Measured processing issues 13,768 SQL queries per 250 items; this is a material optimization concern for the phase-seven full load gate. The package's default worker limit is 25; a 250-item chunk approaches the 120-second lease on this machine, so production chunk sizing must be measured with a real advancing clock.

The local pre-execution smoke budgets were bounded preview queries (ceil(N/250)+20) and PHP peak <=128 MiB; both passed. These engineering smoke budgets do not replace the user-approved release performance thresholds required by phase seven.

Evidence: phase3-scale-postgres.json and phase3-scale-postgres.xml (one test, 26 assertions). Reproduce explicitly with the benchmark file, TM_TEST_DRIVER=pgsql, on the named dedicated database. TM_SCALE_FULL=1 requests complete processing; that mode has not been run and must not be inferred from this report.

The scale smoke preceded the MariaDB-specific identifier hardening. The PostgreSQL schema and claim behavior measured there are unchanged by that correction; the later complete PostgreSQL/SQLite regression runs are recorded separately. This remains checkpoint evidence rather than a final frozen release benchmark.
