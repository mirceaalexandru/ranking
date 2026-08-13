# AdWords Budgets

A React + PHP application: given a history of daily
budget adjustments over three months, generate plausible campaign costs that respect the exercise's two spending rules, and report the daily budget and spend.

---

## The problem in one paragraph

A user sets a daily budget for an AdWords campaign and may change it at any moment, including several
times a day, or pause it by setting it to zero. The campaign then generates costs at random moments
throughout each day, subject to two limits: the cumulated daily cost may not exceed **twice the budget in
effect at that moment**, and the cumulated cost for a month may not exceed **the sum of each day's maximum
budget**. The task is to generate those costs and produce a daily history of budget against spend.

## Documentation

| Document                                                   | Contents                                                                                                                                                                                                 |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [docs/exercise.md](docs/exercise.md)         | The exercise as given, transcribed verbatim, with its worked example.                                                                                                                                                        |
| [docs/development-log.md](docs/development-log.md) | One line per working session: what was decided, and what was later changed. |
| [docs/requirements.md](docs/requirements.md) | Functional and non-functional requirements, run invariants, acceptance criteria.                                                                                                                                             |
| [docs/algorithm.md](docs/algorithm.md)       | How costs are generated: a single chronological sweep, one blocking rule, seeded randomness.                                                                                                                                 |
| [docs/architecture.md](docs/architecture.md) | Stack, project layout, API surface, testing strategy.                                                                                                                                                                        |

## The interesting part

The specification leaves several things open, and the worked example in the PDF looks at first glance as
though it violates its own rules. It does not. Day 01.05 shows a budget of 2 against costs of 5 — which
breaks rule 1 only if you assume the budget resets each day, and the example itself disproves that
assumption. Exactly one reading makes all eight rows and every generated cost consistent.

That reading, and every other decision the exercise forced, is recorded with its evidence in
[development-log.md](docs/development-log.md).

## Stack

PHP 8.3 · Symfony 7 · React · TypeScript · Vite · Docker

The generation algorithm is framework-free plain PHP in `api/src/Domain`, unit-tested in isolation.
Symfony provides HTTP, validation and serialization around it.

The budget history is a CSV file you upload. The server is stateless — no database, nothing kept between
requests. The exercise asks for nothing to be stored, and a run is reproducible from its history and seed.

## Running

```bash
docker compose up
```

Brings up the API and frontend; no local PHP or Node installation required.

Download the example CSV from the UI, edit it in a spreadsheet, upload it. The example is the exercise's
own budget history, so there is something to run immediately.
