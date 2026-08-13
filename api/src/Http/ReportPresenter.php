<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\BudgetChange;
use App\Domain\CostEvent;
use App\Domain\DailyReport;
use App\Domain\DailyReportRow;
use App\Domain\Generation\Algorithm;
use App\Domain\MonthlySummary;
use App\Domain\Period;

/**
 * Shapes a report for the wire.
 *
 * Money is serialised as a decimal string, never as a JSON number: JSON numbers
 * are doubles, and handing the value to a client as one would give back exactly
 * the floating-point problem integer cents exists to avoid.
 */
final readonly class ReportPresenter
{
    /**
     * @return array{
     *     seed: int,
     *     algorithm: array{value: string, label: string, description: string},
     *     period: array{start: string, end: string, days: int},
     *     months: list<array{month: string, allowance: string, spent: string, remaining: string, percentUsed: float}>,
     *     days: list<array{
     *         date: string,
     *         budgetSet: string|null,
     *         costs: string,
     *         changes: list<array{at: string, amount: string}>,
     *         events: list<array{at: string, amount: string}>
     *     }>
     * }
     */
    public function present(DailyReport $report, Period $period, int $seed, Algorithm $algorithm): array
    {
        return [
            'seed' => $seed,
            // Part of a run's identity: the same file and seed under a different
            // algorithm is a different run.
            'algorithm' => [
                'value' => $algorithm->value,
                'label' => $algorithm->label(),
                'description' => $algorithm->description(),
            ],
            'period' => [
                'start' => $period->start->format('Y-m-d'),
                'end' => $period->end->format('Y-m-d'),
                'days' => $period->dayCount(),
            ],
            'months' => array_map(
                static fn (MonthlySummary $month): array => [
                    'month' => $month->month,
                    'allowance' => $month->allowance->toDecimalString(),
                    'spent' => $month->spent->toDecimalString(),
                    'remaining' => $month->remaining()->toDecimalString(),
                    'percentUsed' => round($month->percentUsed(), 1),
                ],
                $report->months,
            ),
            'days' => array_map(
                static fn (DailyReportRow $day): array => [
                    'date' => $day->date->format('Y-m-d'),
                    'budgetSet' => $day->budgetSet?->toDecimalString(),
                    'costs' => $day->costs->toDecimalString(),
                    // Times only: the date is already on the row.
                    'changes' => array_map(
                        static fn (BudgetChange $change): array => [
                            'at' => $change->at->format('H:i:s'),
                            'amount' => $change->amount->toDecimalString(),
                        ],
                        $day->changes,
                    ),
                    'events' => array_map(
                        static fn (CostEvent $event): array => [
                            'at' => $event->at->format('H:i:s'),
                            'amount' => $event->amount->toDecimalString(),
                        ],
                        $day->events,
                    ),
                ],
                $report->days,
            ),
        ];
    }
}
