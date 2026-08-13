/** Mirrors the API's response shape. Money is always a decimal string. */

export interface Entry {
    at: string
    amount: string
}

export interface DayRow {
    date: string
    /** Null on days before the campaign started. */
    budgetSet: string | null
    costs: string
    changes: Entry[]
    events: Entry[]
}

export interface MonthSummary {
    month: string
    allowance: string
    spent: string
    remaining: string
    /** Can exceed 100 when a late budget cut shrinks the month's closing allowance. */
    percentUsed: number
}

export interface Simulation {
    seed: number
    period: { start: string; end: string; days: number }
    months: MonthSummary[]
    days: DayRow[]
}

export interface ValidationError {
    line: number | null
    column: string | null
    message: string
}
