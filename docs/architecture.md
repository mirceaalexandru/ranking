# Architecture

Satisfies [requirements.md](requirements.md) NFR-1 – NFR-5.

> **This is the intended design, not a description of the current tree.** It sets out where things belong
> once the application is complete; components arrive as the work reaches them.

---

## Stack

| Layer    | Choice                                                                                  |
| -------- | --------------------------------------------------------------------------------------- |
| Backend  | PHP 8.3, Symfony 7 (`symfony/skeleton` + only what is needed — not the full webapp pack) |
| Domain   | Framework-free plain PHP, unit-tested directly with PHPUnit                             |
| Input    | CSV, uploaded per run — the server keeps nothing                                        |
| Frontend | React + TypeScript (strict), Vite                                                       |
| Serving  | FrankenPHP — one process, no separate web server or FastCGI layer                        |
| Infra    | `docker compose` covering the whole stack                                               |

**No database, and no state at all.** The exercise takes a budget history as input and produces a report;
it asks for nothing to be stored. A budget history is a CSV file the user keeps, and a run is reproducible
from `(history, seed)` — so nothing needs storing to be recoverable.

`/api/simulate` is therefore a pure function: same CSV and same seed, same result, with no hidden state to
reason about. That is what makes the invariant suite meaningful, and it means two people can use the app
at once without interfering. It also sidesteps a PHP-specific trap — PHP-FPM serves each request in a
separate worker process, so a server-side "working copy" would not survive a request without a shared
backing store, which would be real complexity bought for a feature nobody asked for.

Symfony provides HTTP, DI, validation and serialization **around** the domain, never inside it. The
generator has no framework dependency, so its tests construct it directly and run in milliseconds without
booting a kernel. That separation is the main structural decision here.

The skeleton is preferred over `webapp-pack` because the application has two endpoints and no Twig,
Messenger, mailer or security surface.

## Layout

```
apps/ranking/
├── docs/                       these documents
├── api/                        Symfony application
│   ├── fixtures/sample.csv     the example — served for download, and simulated in the tests
│   ├── src/
│   │   ├── Domain/             framework-free — the exercise itself
│   │   │   ├── Money.php                  integer-cents value object
│   │   │   ├── BudgetChange.php
│   │   │   ├── BudgetHistory.php          sorted changes; budgetAt(t); coveringPeriod()
│   │   │   ├── BudgetTimeline.php         per-day maxBudget / maxBudgetSet
│   │   │   ├── Period.php
│   │   │   ├── MonthlyAllowance.php       rule 2, as known at each instant
│   │   │   ├── CostGenerator.php          the chronological sweep
│   │   │   ├── CostEvent.php
│   │   │   ├── SeededRandom.php           Randomizer wrapper
│   │   │   ├── Simulator.php              wires history → report
│   │   │   ├── DailyReport.php · DailyReportRow.php · MonthlySummary.php
│   │   │   ├── DailyReportBuilder.php     folds costs into the report
│   │   │   └── Generation/
│   │   │       ├── CostAlgorithm.php      one method: spend a day
│   │   │       ├── DaySession.php         the guard rail — clamps every request to the caps
│   │   │       ├── GreedyAlgorithm.php
│   │   │       ├── PacedAlgorithm.php
│   │   │       └── Algorithm.php          the enum a request selects by
│   │   ├── Csv/
│   │   │   ├── BudgetHistoryReader.php    parse + validate, errors carry line numbers
│   │   │   ├── CsvError.php
│   │   │   └── ReadResult.php
│   │   ├── Http/
│   │   │   └── ReportPresenter.php        shapes a report for the wire
│   │   ├── Controller/         thin: deserialize → domain → serialize
│   │   │   ├── HealthController.php
│   │   │   ├── SampleController.php
│   │   │   └── SimulateController.php
│   │   └── Kernel.php
│   └── tests/
│       ├── Unit/               domain and CSV parsing, no kernel
│       ├── Invariant/          property-style sweeps across many seeds
│       ├── Functional/         the endpoints
│       └── Support/            the exercise's own history and costs
├── web/                        React + Vite
│   └── src/
│       ├── api/                typed client and response types
│       ├── features/
│       │   ├── upload/         download the example, choose an algorithm, upload
│       │   └── report/         monthly cards, daily table, validation problems
│       └── styles.css
├── .github/workflows/          CI and release
├── Makefile                    the targets CI invokes
└── docker-compose.yml
```

## The CSV

```csv
date,time,budget
2019-01-01,10:00,7
2019-01-01,11:00,0
2019-01-01,12:00,1
2019-01-01,23:00,6
2019-01-05,10:00,2
```

ISO dates in the files we control. The ambiguity resolved in FR-1 is a property of the exercise's own
notation and there is no reason to inherit it — but the reader also accepts `MM.DD.YYYY`, so the
exercise's dates can be used unchanged.

Validation errors carry the line that caused them: a malformed time, a negative amount, a duplicate
timestamp. The reader reports every problem in one pass rather than failing on the first.

It also checks **plausibility**, not only format. A daily budget above 1,000,000 is refused as a typo
rather than honoured, and a file spanning more than five years is refused rather than reported a day at a
time. Both were found by review: without them an extra zero overflowed the pacing arithmetic into a 500,
and two rows a century apart exhausted memory and returned a truncated fatal error instead of JSON.

## API

| Method | Path            | Purpose                                                                                                          |
| ------ | --------------- | ------------------------------------------------------------------------------------------------------------------ |
| `GET`  | `/api/sample`   | download the example CSV — the same file the tests use as a fixture, so the two cannot drift                     |
| `POST` | `/api/simulate` | upload a CSV (`multipart/form-data`, optional `seed`); returns the generated costs, the daily report and the seed |

The workflow is: download the example, edit it in a spreadsheet, upload it. There is no in-app editing of
budget changes — the exercise does not ask for it, and Excel is a better editor than anything we would
build in the time. The seed comes back in the response so a run can be pinned and repeated.

## Money

Integer cents everywhere, in the domain and in the API payloads. Never floats. `2 × budget` is then exact,
comparisons are exact, and running totals cannot drift. Conversion to a decimal string happens once, at
the display boundary.

## Testing

| Suite      | Covers                                                                                                                                                    |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unit       | timeline construction, carry-over across boundaries, `budgetAt`, daily maxima, allowance arithmetic, CSV parsing and its error reporting                   |
| Invariant  | INV-1 … INV-8 asserted across many seeds and randomly generated histories                                                                                 |
| Fixture    | `sample.csv`: allowances `31 / 20 / 31`, Budget column `7, 6, 6, 6, 2, 0, 0, 0`, and validation of the exercise's own cost stream (AC-1, AC-2)             |
| Functional | both endpoints, including malformed CSV                                                                                                              |

The invariant suite is the one that matters. A random algorithm cannot be tested by comparing output to a
golden file; it is tested by asserting that properties hold whatever the seed produced — and, because the
seed is recorded, any failure is exactly reproducible.

## Running it

```
docker compose up
```

Brings up two services: the API and the Vite dev server. The frontend is on `http://localhost:5174` and
the API on `http://localhost:8081`; both host ports are overridable via `WEB_PORT` and `API_PORT`, since
5173 and 8080 are commonly taken by something else. Vite proxies `/api` to the API service, so the browser
only ever talks to one origin and there is no CORS configuration to get wrong.

**Why not php-fpm behind nginx.** php-fpm does not speak HTTP, so choosing it forces a second service and
a FastCGI configuration in front. FrankenPHP serves `/app/public` itself, which removes a container, a
config file and a class of misconfiguration — for an application with two endpoints and no static assets,
nginx would be doing nothing that is needed here.

## Quality gates

| | |
| --- | --- |
| `composer check` | PHP-CS-Fixer, PHPStan at **level max with no baseline**, PHPUnit |
| `npm run check` | `tsc --noEmit`, ESLint (strict type-checked, `no-explicit-any` as an error), Prettier, Vitest |

Both are green from the first commit. Retrofitting PHPStan at max onto a finished codebase is a miserable
afternoon; starting there costs nothing.
