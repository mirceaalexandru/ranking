# The algorithms

Implements [requirements.md](requirements.md), under the readings recorded in
[development-log.md](development-log.md).

The simulation is a **single chronological sweep**. There is no backtracking and no reject-and-retry loop:
each cost is drawn from the headroom that remains at that instant, so both caps hold by construction
rather than being checked afterwards.

Two algorithms decide *how much* to spend — [greedy](#greedy) and [paced](#paced) — over a shared engine
that decides how much they *may*. Which one produced a run is part of that run's identity: the same file
and seed under a different algorithm is a different run, not a variation of the same one.

The governing principle is that **generation only ever sees the past**. At the moment a cost is initiated,
the algorithm knows the budget history up to that instant and nothing beyond it. Both rules are evaluated
against that knowledge, and neither is applied retroactively.

---

## Outline

```
1. Build the budget timeline from the change list, carrying each value
   forward until the next change, across day and month boundaries.

2. Sweep the period chronologically. For each day:
      n = random integer in [1, 10]
      moments = n random instants within the day, sorted ascending
      hand the day to the chosen algorithm, which for each moment t may ask
      to spend some amount. The engine then decides what it may have:
          B         = budget in effect at t
          allowance = allowance_at(t)                        ← see below
          room      = min( 2×B − spent_today,
                           allowance − spent_month )
          amount    = min( what was asked for, room )
          if amount <= 0:  nothing is generated at t
          else:            record it; spent_today += amount; spent_month += amount
```

## The monthly allowance is a moving quantity

Rule 2 bounds a month's costs by "the sum of the maximum budget for each days within the month". At the
instant a cost is generated, most of those days have not happened. Their budgets are unknown — the user
has not made those decisions yet.

So the allowance is computed from what is known at *t*, projecting the current budget across the days
still to come:

```
allowance_at(t) = Σ over the days d of month(t):

     d < today   →  max_budget(d)                          settled; the day is over
     d = today   →  max( B(u) for u in [start of today, t] )
     d > today   →  B(t)                                   projected from the budget now in effect
```

The allowance therefore moves as the budget moves — rising when the user raises the budget, falling when
they lower it — in exactly the way the daily cap does.

**Today counts the maximum reached so far, not merely the current budget.** A budget spiked to 100 at
09:00 and dropped back at 09:05 contributes 100 to today's term for the rest of the day. It was the
maximum budget that day, and rule 2 says maximum. Discarding it the moment the budget came down would make
the allowance fall below what has already been legitimately spent against it.

### The allowance converges

By the final instant of a month there are no days left to project, so `allowance_at(t)` equals
`Σ max_budget(d)` over the whole month — the figure a reader would compute from the finished history.
That closing value is what the report shows as the month's allowance.

### The guarantee this gives, and the one it does not

Costs are always within the allowance *as known at the time*:

```
for every cost e:   spent_month(through e)  ≤  allowance_at(e.time)
```

This does **not** guarantee that a month's final total stays within the sum of its actual daily maxima. A
campaign running at 7 for five days spends against a projection of `7 × 31`; if the user then pauses for
the rest of the month, the closing sum of daily maxima is small and the month's costs exceed it.

That is the same behaviour rule 1 already has, and which the exercise's own example demonstrates: on
01.01 the budget drops to 0 at 11:00 while 4.12 has already been spent, leaving the day over its cap for
eleven hours. Costs already incurred are not refunded. A month that overshoots after a late budget cut is
that same phenomenon at a larger scale — the consequence of a rule evaluated at the moment of spending
against a decision the user had not yet made.

## One blocking rule, not three

Everything that prevents a cost reduces to a single expression:

```
room = min( 2 × B(t) − spent_today,  allowance_at(t) − spent_month )
room <= 0  →  no cost
```

A paused campaign has `B = 0`, so the daily cap is 0, so `room ≤ 0`. Time before the first budget was ever
set behaves identically — the campaign has not started. There is no special case for pause, and no
filtering of the random moments to "active" windows: one expression covers pause, daily overspend and
monthly exhaustion alike.

Moments are drawn uniformly across the whole 24 hours, including intervals where the budget is zero. An
attempt landing in a paused interval is refused for exactly the same reason an attempt landing on an
already-overspent day is.

## Why `n ≥ 1`

The exercise says "at most 10 times per day", which permits zero. Drawing from `[1, 10]` rather than
`[0, 10]` means every day makes at least one attempt, so a day that produces nothing produces nothing
*because the rules refused it* — not because the generator declined to try. It makes `01.06.2019: 0` in
the sample an outcome rather than an absence.

## The seam: wanting versus being allowed

An algorithm decides how much a campaign **wants** to spend. What it **may** spend is decided in one
place, `DaySession::spend()`, which clamps every request to the room both caps leave and refuses anything
that leaves none.

That division is deliberate. It means a badly written algorithm produces poor *spending patterns*, never
illegal *runs* — the invariants are structural rather than something each implementation has to remember.
A test asserts this directly, using an algorithm that asks for `PHP_INT_MAX` at every moment and checking
the daily cap still holds on all ninety days.

Each algorithm implements a single method and can be read end to end without reference to the other:

```php
interface CostAlgorithm
{
    public function spendDay(DaySession $day, SeededRandom $random): void;
}
```

The engine owns the shape of the simulation — the period, how many attempts a day makes, when they fall —
and the guarantee. The algorithms own only the decision.

---

## Greedy

Spend whatever the rules currently permit, drawn uniformly from the headroom. That is the whole algorithm:

```
for each moment t in the day:
    room = what both caps still allow at t
    if room > 0:
        want uniform(0.01, room)
```

This is the literal reading of "generate costs in a random way", and it produces a consequence worth
seeing rather than hiding.

**The two caps are asymmetric.** The daily rule permits `2 × B`; the month permits only `Σ B` — one budget
per day, not two. A day drawing freely from its headroom takes roughly `2 × B` against a monthly allowance
of about `30 × B`:

```
30 days at budget B      allowance = 30B      daily cap = 2B
spending ≈2B/day    →    allowance gone on day 15; days 16–30 generate nothing
```

Measured over twenty seeds on the example history, greedy leaves an average of **6.2 trailing days** with
a live budget and no costs, and spends **83%** of each month before the 15th. Every month finishes above
its closing allowance.

Greedy is kept, unchanged, because that behaviour is what the rules as written produce. It is also the
contrast that makes the second algorithm's reason for existing visible rather than merely asserted.

---

## Paced

Spread each month's allowance across its days, in proportion to each day's budget.

The starting point is that **the 2× is overdelivery headroom for a good day, not a spending target**. That
is how the real product behaves: a campaign may overspend a given day while the monthly charge stays
within the daily budget × ~30.4. Costs should therefore average to the budget and vary up to twice it.

Each day is given a target — its share of what the month has left, weighted by its own budget, capped
at `2 × B` so it can never aim above what rule 1 allows:

```
share = remaining allowance × maxBudget(today) / Σ maxBudget(today … end of month)
```

**Worked through, at a constant budget of 10 over 31 days.**

Day 1 — remaining allowance 310, today's budget 10, remaining weight 31 × 10 = 310:

```
share = 310 × 10 / 310 = 10.00        ← exactly the day's own budget
```

Say the day spends 14. Day 2 then has 296 left over a weight of 300:

```
share = 296 × 10 / 300 =  9.87        ← every later day shrinks slightly
```

Had day 1 spent only 5, day 2's share would have risen instead. **It self-corrects continuously**, so an
overspend is absorbed gradually rather than discovered as a wall halfway through the month.

**The weighting matters.** A day at budget 6 beside a day at budget 2 receives three times the share, not
an equal slice — which would starve the expensive day and overfeed the cheap one.

Within the day, the target is divided across the remaining attempts, each drawn from
`uniform(0.01, 2 × even share)` so a day's costs differ in size rather than arriving as *n* identical
amounts. Once the day has spent its target, its remaining attempts pass without generating anything.

---

## The two compared

Twenty seeds, over the example history:

| | Greedy | Paced |
| --- | --- | --- |
| Trailing days with a live budget and no costs | 6.2 (max 9) | **0** |
| Share of each month spent before the 15th | 83% | **52%** |
| Days spending above one budget — the 2× headroom in use | 56% | 44% |
| Months finishing above their closing allowance | all of them | **none** |

Both satisfy every invariant on every seed. They differ only in the shape of the month, which is the point:
the rules permit both, and choosing between them is a judgement about what a campaign is supposed to look
like rather than about what is legal.

## Three quantities, not one

The exercise names a daily maximum twice, in two different places, for two different purposes. They are
separate requirements and they are not the same number:

| | Where the exercise says it | Definition | Used for |
| --- | --- | --- | --- |
| `B(t)` | rule 1 — "the budget … in the given moment" | the budget in effect at instant *t* | the daily cap |
| `max_budget(day)` | rule 2 — "the maximum budget for each days" | `max` of `B(t)` across that day, carry-over included | the monthly allowance |
| `max_budget_set(day)` | requirement 2 — "the max budget **set**" | the largest budget the user set that day; the carried value if they set none | the report |

The first two are constraints on generation, stated in the block that defines how costs are produced. The
third is in the deliverables block and describes what to display. Nothing requires them to agree, and on
01.05 they do not:

```
01.05    B(t) = 6 until 10:00, then 2
         max_budget         = 6      ← the day's ceiling; the allowance counts 6
         max_budget_set     = 2      ← the report shows 2
```

Both are correct simultaneously. The budget really was 6 for ten hours — which is why costs of 5 that day
are legal under rule 1, where a cap derived from 2 would forbid them — and the user really did set 2, which
is what a history of their decisions should show.

`max_budget_set` plays no part in generation whatsoever. It exists only for the report.

One consequence: the report's Budget column does not sum to the monthly allowance, because it is not the
column the allowance sums. The allowance is therefore displayed explicitly per month rather than left to
be derived by adding up rows.

## Randomness and reproducibility

Randomness comes from a seeded `\Random\Randomizer` backed by `Random\Engine\Xoshiro256StarStar`, never
from global `mt_rand` state. The seed is an explicit constructor argument and is recorded with the run, so
`(budget history, seed)` reproduces an output exactly.

This is the difference between an algorithm that *is* random and one that is *testably* random. The suite
asserts invariants across many seeds, and any failure is reproducible from the seed alone.

## Money

All arithmetic is in integer cents — including the draws, which pick a whole number of cents rather than
rounding a decimal. `2 × budget` is exact, comparisons are exact, and totals accumulated across hundreds
of events never drift. Conversion to a decimal string happens only at the serialization boundary, and
money crosses the wire as a string: JSON numbers are doubles, and sending one would hand the problem
straight back.

## Complexity

The period is roughly 90 days with at most 10 attempts each. Building the timeline is `O(changes)`; each
attempt recomputes the allowance in `O(days in month)`, at most 31 additions; and pacing sums the
remaining days' maxima once per day, again at most 31. The whole sweep is a few tens of thousands of
operations. None of it needs optimising, and the code should stay obvious rather than clever.
