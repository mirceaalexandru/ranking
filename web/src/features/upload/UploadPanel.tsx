import { useId, useRef, useState } from 'react'

import { sampleUrl } from '../../api/client'

interface Props {
    onRun: (file: File, seed: string) => void
    busy: boolean
    /** The seed the last run used, offered back so it can be pinned. */
    lastSeed: number | null
}

export function UploadPanel({ onRun, busy, lastSeed }: Props): React.JSX.Element {
    const [file, setFile] = useState<File | null>(null)
    const [seed, setSeed] = useState('')
    const [dragging, setDragging] = useState(false)
    const inputRef = useRef<HTMLInputElement>(null)
    const fileId = useId()
    const seedId = useId()

    const submit = (): void => {
        if (file) onRun(file, seed)
    }

    return (
        <section className="panel">
            <h2>Budget history</h2>
            <p className="hint">
                <a href={sampleUrl} download>
                    Download the example
                </a>
                , edit it in a spreadsheet, and upload it. Columns are <code>date,time,budget</code>
                .
            </p>

            <div
                className={`dropzone${dragging ? ' dropzone--over' : ''}`}
                onDragOver={(event) => {
                    event.preventDefault()
                    setDragging(true)
                }}
                onDragLeave={() => {
                    setDragging(false)
                }}
                onDrop={(event) => {
                    event.preventDefault()
                    setDragging(false)
                    const dropped = event.dataTransfer.files[0]
                    if (dropped) setFile(dropped)
                }}
            >
                <label htmlFor={fileId} className="dropzone__label">
                    {file ? file.name : 'Choose a CSV file, or drop one here'}
                </label>
                <input
                    id={fileId}
                    ref={inputRef}
                    type="file"
                    accept=".csv,text/csv"
                    onChange={(event) => {
                        setFile(event.target.files?.[0] ?? null)
                    }}
                />
            </div>

            <div className="controls">
                <label htmlFor={seedId}>Seed</label>
                <input
                    id={seedId}
                    type="text"
                    inputMode="numeric"
                    placeholder="random"
                    value={seed}
                    onChange={(event) => {
                        setSeed(event.target.value)
                    }}
                />
                {lastSeed !== null && (
                    <button
                        type="button"
                        className="link"
                        onClick={() => {
                            setSeed(String(lastSeed))
                        }}
                    >
                        reuse {lastSeed}
                    </button>
                )}
                <button type="button" onClick={submit} disabled={busy || !file}>
                    {busy ? 'Running…' : 'Run simulation'}
                </button>
            </div>
        </section>
    )
}
