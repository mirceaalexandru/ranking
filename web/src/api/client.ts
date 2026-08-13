import type { AlgorithmValue, Simulation, ValidationError } from './types'

export class ApiError extends Error {
    constructor(message: string, options?: ErrorOptions) {
        super(message, options)
        this.name = 'ApiError'
    }
}

/** The file was read but rejected. Carries one entry per problem found. */
export class InvalidCsv extends Error {
    constructor(readonly errors: ValidationError[]) {
        super(`The file has ${String(errors.length)} problem(s).`)
        this.name = 'InvalidCsv'
    }
}

export const sampleUrl = '/api/sample'

export async function simulate(
    file: File,
    seed: string,
    algorithm: AlgorithmValue,
): Promise<Simulation> {
    const body = new FormData()
    body.append('file', file)
    body.append('algorithm', algorithm)
    if (seed !== '') {
        body.append('seed', seed)
    }

    let response: Response
    try {
        response = await fetch('/api/simulate', { method: 'POST', body })
    } catch (cause) {
        throw new ApiError('The API is unreachable.', { cause })
    }

    if (response.status === 422 || response.status === 400) {
        const payload = (await response.json()) as { errors?: ValidationError[] }
        throw new InvalidCsv(payload.errors ?? [])
    }

    if (!response.ok) {
        throw new ApiError(`The API answered with status ${String(response.status)}.`)
    }

    return (await response.json()) as Simulation
}
