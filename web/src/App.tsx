import { useCallback, useEffect, useState } from 'react'

import { fetchHealth } from './api/client'

type Health =
    { state: 'checking' } | { state: 'up'; status: string } | { state: 'down'; reason: string }

export function App(): React.JSX.Element {
    const [health, setHealth] = useState<Health>({ state: 'checking' })

    const check = useCallback((signal?: AbortSignal) => {
        fetchHealth(signal)
            .then((result) => {
                setHealth({ state: 'up', status: result.status })
            })
            .catch((error: unknown) => {
                if (signal?.aborted === true) return
                setHealth({
                    state: 'down',
                    reason: error instanceof Error ? error.message : 'Unknown failure.',
                })
            })
    }, [])

    useEffect(() => {
        const controller = new AbortController()
        check(controller.signal)
        return () => {
            controller.abort()
        }
    }, [check])

    return (
        <main className="page">
            <header className="masthead">
                <h1>AdWords Budgets</h1>
                <p>Daily budget history and generated campaign costs.</p>
            </header>

            <section className={`card card--${toneOf(health)}`} aria-live="polite">
                <h2>API</h2>
                <p className="status">{describe(health)}</p>
                <button
                    type="button"
                    onClick={() => {
                        setHealth({ state: 'checking' })
                        check()
                    }}
                    disabled={health.state === 'checking'}
                >
                    Check again
                </button>
            </section>

            <footer className="footnote">
                Nothing is implemented yet — this is the skeleton the rest is built on.
            </footer>
        </main>
    )
}

function toneOf(health: Health): string {
    switch (health.state) {
        case 'up':
            return 'good'
        case 'down':
            return 'bad'
        case 'checking':
            return 'idle'
    }
}

function describe(health: Health): string {
    switch (health.state) {
        case 'up':
            return `Reachable — reported "${health.status}".`
        case 'down':
            return health.reason
        case 'checking':
            return 'Checking…'
    }
}
