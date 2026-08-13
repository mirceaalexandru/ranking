# Decision log

One entry per working session. What was decided lives in [requirements.md](requirements.md) and
[algorithm.md](algorithm.md); this is the record of how it got there.

| Session | Focus | Decided | Changed course on |
| ------- | ----- | ------- | ----------------- |
| 1 | Reading the specification | Dates are `MM.DD.YYYY`. Budgets carry forward until the next change. The daily cap is evaluated at the moment of generation and never rolls back. The report shows the budget the user *set*; rule 2 sums the budget *in effect* — two requirements, two quantities. The allowance is recomputed at each attempt from what is known then, never from future budgets. One refusal rule covers pause, daily overspend and monthly exhaustion. 1–10 attempts per day at uniform moments. Stack: Symfony 7 + MySQL 8 + React, algorithm as framework-free PHP. | Read the example as self-contradictory on 01.05 — it is not; the contradiction only appears if budgets reset daily, which the example disproves. Which maximum rule 2 sums, twice: first on a wording argument that was too thin, then back on example evidence that turned out to be about the report rather than the allowance. Computing the allowance up-front from the full history, before recognising that generation must only ever see the past. |
