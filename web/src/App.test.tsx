import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { App } from './App'

function mockFetch(implementation: () => Promise<Response>): void {
    vi.stubGlobal('fetch', vi.fn(implementation))
}

afterEach(() => {
    vi.unstubAllGlobals()
})

describe('App', () => {
    it('reports the API as reachable when the health endpoint answers', async () => {
        mockFetch(() => Promise.resolve(new Response('{"status":"ok"}', { status: 200 })))

        render(<App />)

        await waitFor(() => {
            expect(screen.getByText(/Reachable/)).toBeInTheDocument()
        })
        expect(screen.getByText(/"ok"/)).toBeInTheDocument()
    })

    it('shows a failure state rather than a blank page when the API is down', async () => {
        mockFetch(() => Promise.reject(new TypeError('connection refused')))

        render(<App />)

        await waitFor(() => {
            expect(screen.getByText(/unreachable/i)).toBeInTheDocument()
        })
    })

    it('surfaces a non-200 answer as a failure', async () => {
        mockFetch(() => Promise.resolve(new Response('nope', { status: 503 })))

        render(<App />)

        await waitFor(() => {
            expect(screen.getByText(/status 503/)).toBeInTheDocument()
        })
    })
})
