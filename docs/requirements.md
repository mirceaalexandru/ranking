# Requirements

Derived from [exercise.md](exercise.md), with every ambiguity resolved per
[decision-log.md](decision-log.md). Each requirement is written to be verifiable; the
invariants and acceptance criteria are what the test suite asserts.

Legend: **FR** functional · **NFR** non-functional · **INV** invariant that must hold for every generated
run.

---

## Domain

**FR-1 — Budget history as input.** The system accepts a sparse list of budget changes, each a
`(timestamp, amount)` pair, covering a three-month period. Dates are `MM.DD.YYYY` — the only reading under
which the exercise's input is monotonic and spans three months. Amounts are non-negative decimals with two
decimal places. Zero means the campaign is paused.

**FR-2 — Carry-over.** A budget change stays in effect until the next change, including across day and
month boundaries. Days with no change inherit the last value set. *(Ref: [decision log #2](decision-log.md).)*

**FR-3 — No budget before the first change.** Time preceding the first budget change has no budget: the
cap is zero and no costs can be generated. *(Ref: [decision log #8](decision-log.md).)*

**FR-4 — Cost generation.** For each day in the period the system makes between **1 and 10** attempts, at
random instants drawn uniformly across the day and processed in chronological order.

**FR-5 — Daily cap (rule 1).** At the instant a cost is generated,
`spent_today + cost <= 2 × budget_in_effect_at_that_instant`. The cap is re-evaluated at every attempt and
never retroactively invalidates costs already generated. *(Ref: [decision log #4](decision-log.md).)*

**FR-6 — Monthly cap (rule 2).** At the instant a cost is generated,
`spent_month + cost <= allowance_at(t)`, where the allowance is computed from what is known at that
moment — elapsed days contribute their `max_budget`, today contributes the greatest budget reached so far
today, and days still to come are projected at the current budget. Generation never consults budgets the
user has not yet set. *(Ref: [decision log #7](decision-log.md).)*

**FR-6a — A day's maximum.** `max_budget(day)` is the largest budget **in effect** at any instant of that
day, carry-over included — regardless of whether the user set it that day or it was carried in from
earlier. This is the figure rule 2 sums, and it is a separate requirement from the report's column
(FR-9). *(Ref: [decision log #6](decision-log.md).)*

**FR-6b — Spikes count.** A budget held only briefly contributes its full value to that day's maximum, and
remains in the allowance for the rest of the day once reached.
*(Ref: [decision log #21](decision-log.md).)*

**FR-7 — Refused attempts generate nothing.** When an attempt has no headroom under FR-5 or FR-6, no cost
is recorded for that instant. A paused campaign and an exhausted budget are refused by the same rule, not
by special cases.

**FR-8 — Reproducibility.** A run is fully determined by its `(budget history, seed)` pair. Re-running with
the same pair produces identical output.

## Reporting

**FR-9 — Daily history report.** For every day in the period, in date order: the date,
`max_budget_set(day)` — the largest budget the user set that day, or the carried value where they set
none — and the total costs generated.

This is a **different quantity** from the `max_budget` of FR-6a, and deliberately so: rule 2 constrains
generation, requirement 2 describes the report. They coincide except on days where the user lowers the
budget below a higher carried value. The report's Budget column therefore does not sum to the monthly
allowance, which is shown explicitly instead. *(Ref: [decision log #5](decision-log.md).)*

**FR-10 — Days with no activity appear.** Every calendar day in the period has a row, including days with
no budget change and no costs.

**FR-11 — Generated costs are listed.** The individual cost events per day are available alongside the
summary, as in the exercise's own output.

**FR-11a — Budget changes are listed.** The budget changes and their timestamps are shown alongside the
daily rows, so a reader can see what the budget actually did during a day rather than only its daily
maximum. *(Ref: [decision log #5](decision-log.md).)*

## Application

**FR-12 — Budget history editor.** The UI allows adding, editing and removing budget changes, and
importing a history pasted in the format used by the exercise.

**FR-13 — Run and display.** The UI submits a history, runs a simulation and renders the daily report.

## Non-functional

**NFR-1 — Exact money arithmetic.** All monetary values are handled as integer cents internally. No
floating-point accumulation. Display formatting is the only place conversion happens.

**NFR-2 — Framework-independent domain.** The generation algorithm and report builder are plain PHP with
no Symfony or Doctrine dependency, unit-testable in isolation.

**NFR-3 — Strict typing.** PHP with `declare(strict_types=1)` throughout; TypeScript in strict mode with
no `any`.

**NFR-4 — One-command startup.** `docker compose up` brings up database, API and frontend with no local
PHP or Node installation required.

---

## Invariants

These must hold for **every** run, under any seed and any budget history. They are asserted as
property-style tests over randomly generated inputs, not only against the sample.

**INV-1** — For every cost event *e*: `spent_today(up to and including e) <= 2 × budget_at(e.time)`.

**INV-2** — For every cost event *e*: `spent_month(up to and including e) <= allowance_at(e.time)`.

Note what INV-2 deliberately does **not** claim: a month's final total may exceed the sum of that month's
actual daily maxima, if the user lowered the budget after costs had already been generated against a
higher projection. That mirrors INV-1, where a day may sit above `2 × budget` once the budget is lowered.
Neither rule is applied retroactively; costs are never refunded.

**INV-3** — No day contains more than 10 cost events.

**INV-4** — Every cost amount is strictly positive.

**INV-5** — Events within a day are strictly ordered by timestamp.

**INV-6** — No cost event exists at an instant where the effective budget is zero or undefined.

**INV-7** — The same `(history, seed)` produces an identical result.

**INV-8** — The report's Costs column for a day equals the sum of that day's cost events.

---

## Acceptance criteria

**AC-1 — The sample input is reproduced structurally.** Given the exercise's budget history, the report's
Budget column yields `7, 6, 6, 6, 2, 0, 0, 0` for 01.01–01.08, and the closing monthly allowances are
`Jan 31 / Feb 20 / Mar 31`. *(Cost amounts are random and are not expected to match the sample's.)*

**AC-1a — The allowance converges.** At the final instant of each month, `allowance_at(t)` equals the sum
of that month's `max_budget` values — nothing is left to project.

**AC-1b — The two daily maxima diverge only where expected.** For the sample history, `max_budget` and
`max_budget_set` agree on every day except 01.05, where they are 6 and 2 respectively.

**AC-2 — The sample's own cost stream validates.** Fed the exercise's generated costs as a fixture, the
validator confirms every one satisfies FR-5 and FR-6 — demonstrating that our interpretation is the one
under which the exercise's example is self-consistent.

**AC-3 — A mid-day budget drop stops further spend.** Given the 01.01 history, with costs already at 4.12
and the budget dropped to 0 at 11:00, no cost is generated between 11:00 and 23:00, and generation becomes
possible again once the budget rises to 6.

**AC-4 — A paused campaign generates nothing.** A day whose effective budget is 0 throughout produces zero
cost events, despite making between 1 and 10 attempts.

**AC-5 — The monthly cap binds.** A history whose daily caps would permit more than the allowance in force
produces attempts that are refused by rule 2, and no cost exceeds the allowance known at its instant.

**AC-5a — A late budget cut may leave a month above its closing sum.** Given a month spent at a high
budget and then paused, the total costs may exceed the sum of that month's actual daily maxima, while
every individual cost still satisfies INV-2. This is asserted, not merely tolerated: it is the direct
consequence of evaluating rule 2 at the moment of spending.

**AC-6 — Determinism.** Two runs with the same seed produce identical output; two runs with different
seeds produce different output.

**AC-7 — Report totals reconcile.** For every day and every month, the report's figures equal the
aggregates of the underlying cost events.
