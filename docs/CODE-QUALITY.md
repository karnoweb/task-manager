# Code quality before phase seven

Installed development tools: Laravel Pint 1.32.1, PHPStan 2.3.1, Rector 2.7.0.

- `composer format`: format owned PHP with the Laravel preset (including tests and benchmarks).
- `composer format:check`: verify formatting without changing files.
- `composer analyse`: analyze all `src` at PHPStan level 3, without a baseline or ignored errors.
- `composer refactor:check`: check the conservative Rector rule for splitting grouped class constants.
- `composer refactor`: apply that rule, targeting the supported PHP 8.3 language version.

PHPStan needs the genuine optional Kanban, Calendar and CRM dependencies. Prepare the phase-six all host with `tools/prepare_phase6_hosts.py`, or set `TM_PHASE6_HOST_AUTOLOAD` to an equivalent host Composer autoloader. The analysis bootstrap fails explicitly if it is missing. These packages remain optional runtime dependencies.

Pint expands compressed statements, normalizes indentation and imports, and separates class members. Rector's dry run required no additional changes. No broad automatic behavioral refactoring was enabled.

Verification evidence: `docs/quality-sqlite.xml` records the post-format full SQLite run with all optional host dependencies. Historical phase-six test evidence remains unchanged. The updated candidate is recorded in `PHASE-6-RC-FREEZE.json`. Phase seven has not started.

Results: Pint check passed after formatting 516 PHP files; PHPStan level 3 passed with zero errors; Rector dry run passed with no proposed changes; Composer strict validation passed. Full SQLite regression: 564 tests, 4,960 assertions, zero failures/errors, 22 existing engine-specific skips (5m25s). No MySQL/PostgreSQL run was performed.
