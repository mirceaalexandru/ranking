import { Component, type ErrorInfo, type ReactNode } from 'react'

interface Props {
    children: ReactNode
}

interface State {
    error: Error | null
}

/**
 * A render failure should show something explanatory rather than a blank page.
 */
export class ErrorBoundary extends Component<Props, State> {
    override state: State = { error: null }

    static getDerivedStateFromError(error: Error): State {
        return { error }
    }

    override componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('Unhandled render error', error, info.componentStack)
    }

    override render(): ReactNode {
        const { error } = this.state
        if (error) {
            return (
                <main className="page">
                    <section className="card card--bad">
                        <h1>Something broke</h1>
                        <p>{error.message}</p>
                    </section>
                </main>
            )
        }

        return this.props.children
    }
}
