import { useId, useRef, useState } from 'react'

import { sampleUrl } from '../../api/client'
import type { AlgorithmValue } from '../../api/types'

const CHOICES: { value: AlgorithmValue; label: string; hint: string }[] = [
    {
        value: 'paced',
        label: 'Paced',
        hint: "Spreads each month's allowance across its days, weighted by each day's budget.",
    },
    {
        value: 'greedy',
        label: 'Greedy',
        hint: 'Spends whatever the rules permit, so a month empties around its halfway point.',
    },
]

interface Props {
    onRun: (file: File, seed: string, algorithm: AlgorithmValue) => void
    busy: boolean
    /** The seed the last run used, offered back so it can be pinned. */
    lastSeed: number | null
}

export function UploadPanel({ onRun, busy, lastSeed }: Props): React.JSX.Element {
    const [file, setFile] = useState<File | null>(null)
    const [seed, setSeed] = useState('')
    const [algorithm, setAlgorithm] = useState<AlgorithmValue>('paced')
    const [dragging, setDragging] = useState(false)
    const inputRef = useRef<HTMLInputElement>(null)
    const fileId = useId()
    const seedId = useId()
    const algorithmId = useId()

    const chosen = CHOICES.find((choice) => choice.value === algorithm)

    const submit = (): void => {
        if (file) onRun(file, seed, algorithm)
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
                <label htmlFor={algorithmId}>Algorithm</label>
                <select
                    id={algorithmId}
                    value={algorithm}
                    onChange={(event) => {
                        setAlgorithm(event.target.value as AlgorithmValue)
                    }}
                >
                    {CHOICES.map((choice) => (
                        <option key={choice.value} value={choice.value}>
                            {choice.label}
                        </option>
                    ))}
                </select>

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

            {chosen && <p className="hint hint--tight">{chosen.hint}</p>}
        </section>
    )
}
