import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { App } from './App'
import { ApiError, InvalidCsv } from './api/client'
import type { Simulation } from './api/types'

vi.mock('./api/client', async () => {
    const actual = await vi.importActual<typeof import('./api/client')>('./api/client')
    return { ...actual, simulate: vi.fn() }
})

const { simulate } = await import('./api/client')
const simulateMock = vi.mocked(simulate)

afterEach(() => {
    vi.resetAllMocks()
})

function aSimulation(overrides: Partial<Simulation> = {}): Simulation {
    return {
        seed: 4242,
        period: { start: '2019-01-01', end: '2019-03-31', days: 90 },
        months: [
            {
                month: '2019-01',
                allowance: '473.00',
                spent: '479.00',
                remaining: '-6.00',
                percentUsed: 101.3,
            },
        ],
        days: [
            {
                date: '2019-01-01',
                budgetSet: '8.00',
                costs: '12.77',
                changes: [{ at: '09:00:00', amount: '5.00' }],
                events: [{ at: '09:14:22', amount: '1.23' }],
            },
            { date: '2019-01-02', budgetSet: '8.00', costs: '0.00', changes: [], events: [] },
        ],
        ...overrides,
    }
}

function upload(name = 'history.csv'): void {
    const input = screen.getByLabelText(/choose a csv file/i)
    fireEvent.change(input, { target: { files: [new File(['date,time,budget'], name)] } })
}

describe('App', () => {
    it('offers the example for download before anything is uploaded', () => {
        render(<App />)

        const link = screen.getByRole('link', { name: /download the example/i })
        expect(link).toHaveAttribute('href', '/api/sample')
        expect(screen.queryByRole('table')).not.toBeInTheDocument()
    })

    it('cannot run until a file is chosen', () => {
        render(<App />)

        expect(screen.getByRole('button', { name: /run simulation/i })).toBeDisabled()
        upload()
        expect(screen.getByRole('button', { name: /run simulation/i })).toBeEnabled()
    })

    it('renders the report once a file has been simulated', async () => {
        simulateMock.mockResolvedValue(aSimulation())
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))

        await waitFor(() => {
            expect(screen.getByRole('table')).toBeInTheDocument()
        })

        expect(screen.getByText('2019-01-01')).toBeInTheDocument()
        expect(screen.getByText('12.77')).toBeInTheDocument()
        expect(screen.getByText(/seed 4242/)).toBeInTheDocument()
    })

    it('shows every validation problem with its line, and no report', async () => {
        simulateMock.mockRejectedValue(
            new InvalidCsv([
                { line: 3, column: 'date', message: 'Expected a date as YYYY-MM-DD.' },
                { line: 5, column: 'budget', message: 'A budget cannot be negative.' },
            ]),
        )
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))

        await waitFor(() => {
            expect(screen.getByText(/was not accepted/i)).toBeInTheDocument()
        })

        expect(screen.getByText('line 3')).toBeInTheDocument()
        expect(screen.getByText('line 5')).toBeInTheDocument()
        expect(screen.getByText(/cannot be negative/)).toBeInTheDocument()
        expect(screen.queryByRole('table')).not.toBeInTheDocument()
    })

    it('reports an unreachable API rather than failing silently', async () => {
        simulateMock.mockRejectedValue(new ApiError('The API is unreachable.'))
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))

        await waitFor(() => {
            expect(screen.getByText(/could not run/i)).toBeInTheDocument()
        })
        expect(screen.getByText(/unreachable/i)).toBeInTheDocument()
    })

    it('offers the seed back so a run can be repeated exactly', async () => {
        simulateMock.mockResolvedValue(aSimulation())
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))
        await waitFor(() => {
            expect(screen.getByRole('table')).toBeInTheDocument()
        })

        fireEvent.click(screen.getByRole('button', { name: /reuse 4242/i }))
        expect(screen.getByLabelText(/seed/i)).toHaveValue('4242')

        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))
        await waitFor(() => {
            expect(simulateMock).toHaveBeenLastCalledWith(expect.any(File), '4242')
        })
    })

    it('expands a day to show its costs and budget changes', async () => {
        simulateMock.mockResolvedValue(aSimulation())
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))
        await waitFor(() => {
            expect(screen.getByRole('table')).toBeInTheDocument()
        })

        const expand = screen.getByRole('button', { name: /1 costs/i })
        expect(expand).toHaveAttribute('aria-expanded', 'false')

        fireEvent.click(expand)

        expect(screen.getByText(/1.23 at 09:14:22/)).toBeInTheDocument()
        expect(screen.getByText(/5.00 at 09:00:00/)).toBeInTheDocument()
    })

    it('shows the allowance alongside the spend, flagging a month that went over', async () => {
        simulateMock.mockResolvedValue(aSimulation())
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))
        await waitFor(() => {
            expect(screen.getByRole('table')).toBeInTheDocument()
        })

        const january = screen.getByRole('heading', { name: '2019-01' }).parentElement
        expect(january).not.toBeNull()
        expect(within(january as HTMLElement).getByText('479.00')).toBeInTheDocument()
        expect(within(january as HTMLElement).getByText(/473.00/)).toBeInTheDocument()
        expect(
            within(january as HTMLElement).getByText(/above the closing allowance/),
        ).toBeInTheDocument()
    })

    it('labels a day that only has a budget change, rather than saying "0 costs"', async () => {
        simulateMock.mockResolvedValue(
            aSimulation({
                days: [
                    {
                        date: '2019-01-12',
                        budgetSet: '0.00',
                        costs: '0.00',
                        changes: [{ at: '18:00:00', amount: '0.00' }],
                        events: [],
                    },
                ],
            }),
        )
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))

        await waitFor(() => {
            expect(screen.getByRole('table')).toBeInTheDocument()
        })
        expect(screen.getByRole('button', { name: /budget change/i })).toBeInTheDocument()
        expect(screen.queryByRole('button', { name: /0 costs/i })).not.toBeInTheDocument()
    })

    it('shows a day with no activity as a dash rather than a blank', async () => {
        simulateMock.mockResolvedValue(
            aSimulation({
                days: [
                    { date: '2019-01-01', budgetSet: null, costs: '0.00', changes: [], events: [] },
                ],
            }),
        )
        render(<App />)

        upload()
        fireEvent.click(screen.getByRole('button', { name: /run simulation/i }))

        await waitFor(() => {
            expect(screen.getByRole('table')).toBeInTheDocument()
        })
        expect(screen.getByText('—')).toBeInTheDocument()
    })
})
