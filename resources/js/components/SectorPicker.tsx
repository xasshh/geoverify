import { useEffect, useRef, useState } from 'react';
import { cx } from '@/lib/cx';

interface Sector {
    code: string;
    name: string;
    matchedOn: string;
    isAlias: boolean;
}

interface SectorPickerProps {
    value: Sector | null;
    onChange: (sector: Sector | null) => void;
    error?: string;
}

/**
 * The sector picker an officer actually uses.
 *
 * They type "vulcanizer", not "4520". The trade term they matched on is shown
 * above the ISIC wording, because that is the word they recognise, and the code
 * is shown small because it is a machine value they never need to read.
 */
export function SectorPicker({ value, onChange, error }: SectorPickerProps) {
    const [term, setTerm] = useState('');
    const [matches, setMatches] = useState<Sector[]>([]);
    const [open, setOpen] = useState(false);
    const [searching, setSearching] = useState(false);
    const debounce = useRef<number | null>(null);

    useEffect(() => {
        if (debounce.current !== null) {
            window.clearTimeout(debounce.current);
        }

        if (term.trim().length < 2) {
            return;
        }

        debounce.current = window.setTimeout(() => {
            // Set here rather than in the effect body: during the debounce wait
            // nothing is in flight yet, so nothing is searching.
            setSearching(true);

            void fetch(`/api/field/sectors?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json() as Promise<{ results: Sector[] }>)
                .then((body) => {
                    setMatches(body.results);
                    setOpen(true);
                })
                .catch(() => {
                    setMatches([]);
                })
                .finally(() => {
                    setSearching(false);
                });
        }, 180);

        return () => {
            if (debounce.current !== null) {
                window.clearTimeout(debounce.current);
            }
        };
    }, [term]);

    // Derived, not stored: below two characters there is simply nothing to show.
    const results = term.trim().length < 2 ? [] : matches;

    if (value !== null) {
        return (
            <div className="flex flex-col gap-1.5">
                <span className="text-label font-semibold tracking-[0.05em] text-muted uppercase">
                    Sector
                </span>
                <div className="flex items-center justify-between gap-3 rounded-sm border border-gold bg-raised px-3 py-2.5">
                    <span className="min-w-0">
                        <span className="block truncate text-body text-ink">{value.matchedOn}</span>
                        <span className="block truncate text-label text-faint">
                            <span className="numeric-mono">{value.code}</span> {value.name}
                        </span>
                    </span>
                    <button
                        type="button"
                        onClick={() => {
                            onChange(null);
                            setTerm('');
                        }}
                        className="min-h-touch shrink-0 px-2 text-ui text-muted underline underline-offset-2 hover:text-ink"
                    >
                        Change
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="relative flex flex-col gap-1.5">
            <label
                htmlFor="sector-search"
                className="text-label font-semibold tracking-[0.05em] text-muted uppercase"
            >
                Sector
            </label>
            <input
                id="sector-search"
                type="search"
                autoComplete="off"
                value={term}
                onChange={(e) => {
                    setTerm(e.target.value);
                }}
                onFocus={() => {
                    setOpen(results.length > 0);
                }}
                placeholder="What do they do? Try: vulcanizer, POS, buka"
                className={cx(
                    'min-h-touch-lg w-full rounded-sm border bg-surface px-3 text-body text-ink',
                    'placeholder:text-faint focus:border-gold',
                    error === undefined ? 'border-rule-strong' : 'border-alert',
                )}
            />

            {error !== undefined && <p className="text-ui text-alert">{error}</p>}

            {open && results.length > 0 && (
                <ul className="absolute top-full right-0 left-0 z-20 mt-1 max-h-72 overflow-y-auto rounded-card border border-rule bg-raised">
                    {results.map((sector) => (
                        <li key={`${sector.code}-${sector.matchedOn}`}>
                            <button
                                type="button"
                                onClick={() => {
                                    onChange(sector);
                                    setOpen(false);
                                }}
                                className="min-h-touch-lg w-full border-b border-rule px-3 py-2 text-left last:border-b-0 hover:bg-raised"
                            >
                                <span className="block text-body text-ink">{sector.matchedOn}</span>
                                <span className="block truncate text-label text-faint">
                                    <span className="numeric-mono">{sector.code}</span> {sector.name}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {searching && term.trim().length >= 2 && results.length === 0 && (
                <p className="text-ui text-faint">Looking...</p>
            )}

            {!searching && term.trim().length >= 2 && results.length === 0 && (
                <p className="text-ui text-muted">
                    Nothing matches that. Try a plainer word, or the closest trade you know.
                </p>
            )}
        </div>
    );
}
