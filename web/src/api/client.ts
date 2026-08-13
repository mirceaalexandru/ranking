export interface HealthStatus {
    status: string
}

export class ApiError extends Error {
    constructor(message: string, options?: ErrorOptions) {
        super(message, options)
        this.name = 'ApiError'
    }
}

/**
 * The single place API calls are defined. One endpoint hardly justifies a module,
 * but the pattern is cheaper to set now than to retrofit once there are several.
 */
export async function fetchHealth(signal?: AbortSignal): Promise<HealthStatus> {
    let response: Response
    try {
        response = await fetch('/api/health', signal ? { signal } : {})
    } catch (cause) {
        throw new ApiError('The API is unreachable.', { cause })
    }

    if (!response.ok) {
        throw new ApiError(`The API answered with status ${String(response.status)}.`)
    }

    return (await response.json()) as HealthStatus
}
