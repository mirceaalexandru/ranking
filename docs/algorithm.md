# The algorithm

Implements [requirements.md](requirements.md), under the readings recorded in
[development-log.md](development-log.md).

The simulation is a **single chronological sweep**. There is no backtracking and no reject-and-retry loop:
each cost is drawn from the headroom that remains at that instant, so both caps hold by construction
rather than being checked afterwards.

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
      for each moment t:
          B         = budget in effect at t
          allowance = allowance_at(t)                        ← see below
          room      = min( 2×B − spent_today,
                           allowance − spent_month )
          if room <= 0:
              no cost is generated at t
          else:
              cost = round( uniform(0, room), 2 )
              spent_today += cost ;  spent_month += cost
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

## Choosing the cost amount

The amount is drawn uniformly from the remaining headroom:

```
cost = round( uniform(0, room), 2 )
```

Amounts rounding to zero cents are discarded, so every recorded cost is strictly positive.

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

All arithmetic is in integer cents. `2 × budget` is exact, comparisons are exact, and accumulated totals
never drift. Conversion to decimal happens only at the serialization boundary.

## Complexity

The period is roughly 90 days with at most 10 attempts each. Building the timeline is `O(changes)`, and
each attempt recomputes the allowance in `O(days in month)` — at most 31 additions. The whole sweep is a
few tens of thousands of operations. None of it needs optimising, and the code should stay obvious rather
than clever.
