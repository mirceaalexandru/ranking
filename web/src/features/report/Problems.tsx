import type { ValidationError } from '../../api/types'

export function Problems({ errors }: { errors: ValidationError[] }): React.JSX.Element {
    return (
        <section className="panel panel--bad">
            <h2>The file was not accepted</h2>
            <p className="hint">
                Every problem found is listed, so the file can be fixed in one pass.
            </p>
            <ul className="problems">
                {errors.map((error, index) => (
                    <li key={`${String(error.line)}-${String(index)}`}>
                        {error.line !== null && (
                            <span className="problems__line">line {error.line}</span>
                        )}
                        {error.column !== null && <code>{error.column}</code>}
                        <span>{error.message}</span>
                    </li>
                ))}
            </ul>
        </section>
    )
}
