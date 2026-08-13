import { useState } from 'react'

import type { DayRow, MonthSummary, Simulation } from '../../api/types'

export function Report({ simulation }: { simulation: Simulation }): React.JSX.Element {
    return (
        <>
            <section className="panel">
                <h2>Months</h2>
                <div className="months">
                    {simulation.months.map((month) => (
                        <MonthCard key={month.month} month={month} />
                    ))}
                </div>
                <p className="hint">
                    The allowance is shown in full because the Budget column does not sum to it: the
                    column is what the user <em>set</em>, while the monthly rule counts the largest
                    budget <em>in effect</em> each day.
                </p>
            </section>

            <section className="panel">
                <h2>
                    Daily history <span className="muted">· seed {simulation.seed}</span>
                </h2>
                <table className="report">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col" className="numeric">
                                Budget
                            </th>
                            <th scope="col" className="numeric">
                                Costs
                            </th>
                            <th scope="col" aria-label="Detail" />
                        </tr>
                    </thead>
                    <tbody>
                        {simulation.days.map((day) => (
                            <Day key={day.date} day={day} />
                        ))}
                    </tbody>
                </table>
            </section>
        </>
    )
}

function MonthCard({ month }: { month: MonthSummary }): React.JSX.Element {
    const over = month.percentUsed > 100

    return (
        <div className="month">
            <h3>{month.month}</h3>
            <p className="month__figures">
                <strong>{month.spent}</strong> / {month.allowance}
            </p>
            <div className="meter" role="img" aria-label={`${String(month.percentUsed)}% used`}>
                <div
                    className={`meter__fill${over ? ' meter__fill--over' : ''}`}
                    style={{ width: `${String(Math.min(month.percentUsed, 100))}%` }}
                />
            </div>
            <p className="muted">
                {month.percentUsed.toFixed(1)}% used
                {over && ' — above the closing allowance'}
            </p>
        </div>
    )
}

function Day({ day }: { day: DayRow }): React.JSX.Element {
    const [open, setOpen] = useState(false)
    const hasDetail = day.events.length > 0 || day.changes.length > 0

    return (
        <>
            <tr className={day.costs === '0.00' ? 'quiet' : undefined}>
                <td>{day.date}</td>
                <td className="numeric">{day.budgetSet ?? '—'}</td>
                <td className="numeric">{day.costs}</td>
                <td>
                    {hasDetail && (
                        <button
                            type="button"
                            className="link"
                            aria-expanded={open}
                            onClick={() => {
                                setOpen(!open)
                            }}
                        >
                            {open
                                ? 'hide'
                                : day.events.length > 0
                                  ? `${String(day.events.length)} costs`
                                  : 'budget change'}
                        </button>
                    )}
                </td>
            </tr>
            {open && (
                <tr className="detail">
                    <td colSpan={4}>
                        {day.changes.length > 0 && (
                            <p>
                                <strong>Budget changes:</strong>{' '}
                                {day.changes.map((c) => `${c.amount} at ${c.at}`).join(', ')}
                            </p>
                        )}
                        {day.events.length > 0 && (
                            <p>
                                <strong>Costs:</strong>{' '}
                                {day.events.map((e) => `${e.amount} at ${e.at}`).join(', ')}
                            </p>
                        )}
                    </td>
                </tr>
            )}
        </>
    )
}
