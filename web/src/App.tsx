import { useState } from 'react'

import { ApiError, InvalidCsv, simulate } from './api/client'
import type { Simulation, ValidationError } from './api/types'
import { Problems } from './features/report/Problems'
import { Report } from './features/report/Report'
import { UploadPanel } from './features/upload/UploadPanel'

type State =
    | { status: 'idle' }
    | { status: 'running' }
    | { status: 'done'; simulation: Simulation }
    | { status: 'invalid'; errors: ValidationError[] }
    | { status: 'failed'; reason: string }

export function App(): React.JSX.Element {
    const [state, setState] = useState<State>({ status: 'idle' })
    const [lastSeed, setLastSeed] = useState<number | null>(null)

    const run = (file: File, seed: string): void => {
        setState({ status: 'running' })

        simulate(file, seed)
            .then((simulation) => {
                setLastSeed(simulation.seed)
                setState({ status: 'done', simulation })
            })
            .catch((error: unknown) => {
                if (error instanceof InvalidCsv) {
                    setState({ status: 'invalid', errors: error.errors })
                    return
                }

                setState({
                    status: 'failed',
                    reason: error instanceof ApiError ? error.message : 'Something went wrong.',
                })
            })
    }

    return (
        <main className="page">
            <header className="masthead">
                <h1>AdWords Budgets</h1>
                <p>
                    A daily history of the budget set and the costs the campaign generated against
                    it.
                </p>
            </header>

            <UploadPanel onRun={run} busy={state.status === 'running'} lastSeed={lastSeed} />

            {state.status === 'running' && <p className="muted">Simulating…</p>}
            {state.status === 'invalid' && <Problems errors={state.errors} />}
            {state.status === 'failed' && (
                <section className="panel panel--bad">
                    <h2>The simulation could not run</h2>
                    <p>{state.reason}</p>
                </section>
            )}
            {state.status === 'done' && <Report simulation={state.simulation} />}
        </main>
    )
}
