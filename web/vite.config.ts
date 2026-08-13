import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// In Docker the API is reachable as the nginx service; locally it is on 8080.
const apiTarget = process.env['VITE_API_TARGET'] ?? 'http://localhost:8081'

export default defineConfig({
    plugins: [react()],
    server: {
        host: true,
        port: 5173,
        // Proxying means the browser only ever talks to one origin,
        // so there is no CORS configuration to explain or get wrong.
        proxy: {
            '/api': { target: apiTarget, changeOrigin: true },
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./src/setupTests.ts'],
        css: false,
    },
})
