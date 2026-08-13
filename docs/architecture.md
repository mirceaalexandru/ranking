# Architecture

Satisfies [requirements.md](requirements.md) NFR-1 – NFR-4.

---

## Stack

| Layer    | Choice                                                                                   |
| -------- | ---------------------------------------------------------------------------------------- |
| Backend  | PHP 8.3, Symfony 7 (`symfony/skeleton` + only what is needed — not the full webapp pack) |
| Domain   | Framework-free plain PHP, unit-tested directly with PHPUnit                              |
| Database | MySQL 8 via Doctrine ORM                                                                 |
| Frontend | React + TypeScript (strict), Vite                                                        |
| Infra    | `docker compose` covering the whole stack                                                |

Symfony provides HTTP, DI, validation and serialization **around** the domain, never inside it. The
generator has no framework dependency, so its tests construct it directly and run in milliseconds without
booting a kernel. That separation is the main structural decision here.

The skeleton is preferred over `webapp-pack` because the application has a handful of endpoints and no
Twig, Messenger, mailer or security surface.

## Layout

```
apps/ranking/
├── docs/                       these documents
├── api/                        Symfony application
│   ├── src/
│   │   ├── Domain/             framework-free — the exercise itself
│   │   │   ├── Money.php                  integer-cents value object
│   │   │   ├── BudgetChange.php
│   │   │   ├── BudgetHistory.php          sorted changes; budgetAt(t)
│   │   │   ├── BudgetTimeline.php         budget at an instant; per-day max_budget / max_budget_set
│   │   │   ├── Period.php
│   │   │   ├── MonthlyAllowance.php       rule 2
│   │   │   ├── CostGenerator.php          the chronological sweep, rule 1
│   │   │   ├── SeededRandom.php           Randomizer wrapper
│   │   │   └── DailyReportBuilder.php
│   │   ├── Controller/         thin: deserialize → domain → serialize
│   │   └── Dto/                request/response shapes, validated
│   ├── tests/
│   │   ├── Unit/               domain, no kernel
│   │   ├── Invariant/          property-style, many seeds (INV-1 … INV-8)
│   │   └── Functional/         API endpoints
│   └── migrations/
├── web/                        React + Vite
│   └── src/
│       ├── api/                typed client
│       ├── features/
│       │   ├── editor/         budget history editing + raw-format paste parser
│       │   └── report/         daily report table
│       └── lib/                money formatting, date handling
└── docker-compose.yml
```

## Money

Integer cents everywhere — in the domain, in the API payloads, and in the database as `INT`. Never
`FLOAT`, and never `DECIMAL` doing arithmetic in SQL. `2 × budget` is then exact, comparisons are exact,
and running totals cannot drift. Conversion to a decimal string happens once, at the display boundary.

## API

| Method | Path            | Purpose                                                                     |
| ------ | --------------- | --------------------------------------------------------------------------- |
| `POST` | `/api/simulate` | stateless: budget history and seed in, generated costs and daily report out |

`/api/simulate` is the endpoint that most directly answers the exercise: the algorithm can be exercised by
the frontend, by curl, or by a reviewer, without any stored state.

## Testing

| Suite      | Covers                                                                                                                                                |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unit       | timeline construction, carry-over across boundaries, `budgetAt`, daily maxima, allowance arithmetic, the paste parser                                 |
| Invariant  | INV-1 … INV-8 asserted across many seeds and randomly generated histories                                                                             |
| Fixture    | the exercise's sample: allowances `31 / 20 / 31`, Budget column `7, 6, 6, 6, 2, 0, 0, 0`, and validation of the sample's own cost stream (AC-1, AC-2) |
| Functional | the endpoint, including validation failures                                                                                                           |

The invariant suite is the one that matters. A random algorithm cannot be tested by comparing output to a
golden file; it is tested by asserting that properties hold whatever the seed produced — and, because the
seed is recorded, any failure is exactly reproducible.

## Running it

```
docker compose up
```

Brings up MySQL, the API (php-fpm + nginx) and the Vite dev server, and runs migrations. No local PHP or
Node installation required.
