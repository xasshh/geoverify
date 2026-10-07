import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/Button';
import { SelectField, TextField } from '@/components/Field';
import type { FeatureAttribute, FeatureClassRow } from '@/lib/campaign';
import { cx } from '@/lib/cx';

export interface ClassVocabulary {
    geometryTypes: Array<{ value: string; label: string }>;
    attributeTypes: Array<{ value: string; label: string; takesOptions: boolean }>;
}

/** An attribute as the editor holds it: options as one comma separated line. */
interface DraftAttribute {
    key: string;
    label: string;
    type: string;
    options: string;
    required: boolean;
    field_only: boolean;
    unit: string;
    help_text: string;
}

function toDraft(attribute: FeatureAttribute): DraftAttribute {
    return {
        key: attribute.key,
        label: attribute.label,
        type: attribute.type,
        options: (attribute.options ?? []).join(', '),
        required: attribute.required,
        field_only: attribute.field_only,
        unit: attribute.unit ?? '',
        help_text: attribute.help_text ?? '',
    };
}

function slug(text: string): string {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '');
}

function Check({
    checked,
    onChange,
    children,
}: {
    checked: boolean;
    onChange: (next: boolean) => void;
    children: React.ReactNode;
}) {
    return (
        <label className="flex min-h-touch items-center gap-2 text-ui text-ink">
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => {
                    onChange(e.target.checked);
                }}
                className="size-5 rounded-[2px] border-rule-strong accent-gold"
            />
            {children}
        </label>
    );
}

/**
 * Adds a feature class or revises one: its name, shape, colour and the form it
 * asks.
 *
 * The shape is fixed once a class exists (the server refuses a change), so the
 * picker is shown only when adding. Saving a changed form writes a new version
 * on the server; the old one stays with every feature captured against it.
 */
export function FeatureClassEditor({
    row,
    vocabulary,
    postUrl,
    onDone,
}: {
    row: FeatureClassRow | null;
    vocabulary: ClassVocabulary;
    postUrl: string;
    onDone: () => void;
}) {
    const form = useForm({
        id: row?.id ?? null,
        key: row?.key ?? '',
        label: row?.label ?? '',
        geometry_type: row?.geometryType ?? 'polygon',
        description: row?.description ?? '',
        exclusivity_group: row?.exclusivityGroup ?? '',
        fill: row?.style?.fill ?? '#5B8C5A',
        stroke: row?.style?.stroke ?? '#3A5F39',
        is_active: row?.isActive ?? true,
        attributes: (row?.attributes ?? []).map(toDraft),
    });

    const takesOptions = (type: string): boolean =>
        vocabulary.attributeTypes.find((t) => t.value === type)?.takesOptions ?? false;

    const setAttribute = (index: number, patch: Partial<DraftAttribute>) => {
        form.setData(
            'attributes',
            form.data.attributes.map((a, i) => (i === index ? { ...a, ...patch } : a)),
        );
    };

    const save = () => {
        // Transform returns void in this Inertia version, so it is applied and
        // then posted rather than chained.
        form.transform((data) => ({
            id: data.id,
            key: data.key,
            label: data.label,
            geometry_type: data.geometry_type,
            description: data.description === '' ? null : data.description,
            exclusivity_group: data.exclusivity_group === '' ? null : data.exclusivity_group,
            style: { fill: data.fill, stroke: data.stroke },
            is_active: data.is_active,
            attributes: data.attributes.map((a) => ({
                key: a.key,
                label: a.label,
                type: a.type,
                options: takesOptions(a.type)
                    ? a.options
                          .split(',')
                          .map((o) => o.trim())
                          .filter((o) => o !== '')
                    : undefined,
                required: a.required,
                field_only: a.field_only,
                unit: a.type === 'number' && a.unit !== '' ? a.unit : undefined,
                help_text: a.help_text === '' ? undefined : a.help_text,
            })),
        }));
        form.post(postUrl, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <div className="rounded-card border border-gold/40 bg-sunken p-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <TextField
                    label="Label"
                    value={form.data.label}
                    error={form.errors.label}
                    onChange={(e) => {
                        form.setData('label', e.target.value);
                        if (row === null && (form.data.key === '' || form.data.key === slug(form.data.label))) {
                            form.setData('key', slug(e.target.value));
                        }
                    }}
                />
                <TextField
                    label="Key"
                    machine
                    value={form.data.key}
                    readOnly={row !== null}
                    error={form.errors.key}
                    hint={row === null ? 'Used in exports; fixed once saved' : 'Fixed once saved'}
                    onChange={(e) => {
                        form.setData('key', e.target.value);
                    }}
                />
                {row === null ? (
                    <SelectField
                        label="Shape"
                        value={form.data.geometry_type}
                        error={form.errors.geometry_type}
                        onChange={(e) => {
                            form.setData('geometry_type', e.target.value);
                        }}
                    >
                        {vocabulary.geometryTypes.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </SelectField>
                ) : (
                    <TextField label="Shape" value={row.geometryLabel} readOnly hint="A class keeps its shape" />
                )}
                <TextField
                    label="May not overlap (group)"
                    machine
                    value={form.data.exclusivity_group}
                    error={form.errors.exclusivity_group}
                    hint="Classes in one group cannot overlap, e.g. land_cover"
                    onChange={(e) => {
                        form.setData('exclusivity_group', e.target.value);
                    }}
                />
            </div>

            <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
                <TextField
                    label="Description"
                    value={form.data.description}
                    onChange={(e) => {
                        form.setData('description', e.target.value);
                    }}
                />
                <label className="flex flex-col gap-1 text-label text-muted">
                    Fill
                    <input
                        type="color"
                        value={form.data.fill}
                        onChange={(e) => {
                            form.setData('fill', e.target.value);
                        }}
                        className="h-11 w-16 rounded-sm border border-rule-strong"
                    />
                </label>
                <label className="flex flex-col gap-1 text-label text-muted">
                    Outline
                    <input
                        type="color"
                        value={form.data.stroke}
                        onChange={(e) => {
                            form.setData('stroke', e.target.value);
                        }}
                        className="h-11 w-16 rounded-sm border border-rule-strong"
                    />
                </label>
                <Check
                    checked={form.data.is_active}
                    onChange={(next) => {
                        form.setData('is_active', next);
                    }}
                >
                    In use
                </Check>
            </div>

            <h4 className="mt-5 text-label font-semibold tracking-[0.05em] text-muted uppercase">
                What the officer is asked
            </h4>
            <ul className="mt-2 flex flex-col gap-2">
                {form.data.attributes.map((attribute, index) => (
                    <li
                        key={index}
                        className="grid gap-2 rounded-sm border border-rule bg-raised p-3 sm:grid-cols-[1.2fr_1fr_0.9fr_1.4fr] lg:grid-cols-[1.2fr_1fr_0.9fr_1.4fr_auto_auto_auto]"
                    >
                        <TextField
                            label="Question"
                            value={attribute.label}
                            onChange={(e) => {
                                const wasAuto = attribute.key === '' || attribute.key === slug(attribute.label);
                                setAttribute(index, {
                                    label: e.target.value,
                                    ...(wasAuto ? { key: slug(e.target.value) } : {}),
                                });
                            }}
                        />
                        <TextField
                            label="Key"
                            machine
                            value={attribute.key}
                            onChange={(e) => {
                                setAttribute(index, { key: e.target.value });
                            }}
                        />
                        <SelectField
                            label="Answer"
                            value={attribute.type}
                            onChange={(e) => {
                                setAttribute(index, { type: e.target.value });
                            }}
                        >
                            {vocabulary.attributeTypes.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </SelectField>
                        {takesOptions(attribute.type) ? (
                            <TextField
                                label="Options, comma separated"
                                value={attribute.options}
                                onChange={(e) => {
                                    setAttribute(index, { options: e.target.value });
                                }}
                            />
                        ) : attribute.type === 'number' ? (
                            <TextField
                                label="Unit"
                                value={attribute.unit}
                                onChange={(e) => {
                                    setAttribute(index, { unit: e.target.value });
                                }}
                            />
                        ) : (
                            <span />
                        )}
                        <Check
                            checked={attribute.required}
                            onChange={(next) => {
                                setAttribute(index, { required: next });
                            }}
                        >
                            Required
                        </Check>
                        <Check
                            checked={attribute.field_only}
                            onChange={(next) => {
                                setAttribute(index, { field_only: next });
                            }}
                        >
                            On the ground only
                        </Check>
                        <button
                            type="button"
                            onClick={() => {
                                form.setData(
                                    'attributes',
                                    form.data.attributes.filter((_, i) => i !== index),
                                );
                            }}
                            className="self-center text-label text-gold underline underline-offset-2"
                        >
                            Remove
                        </button>
                    </li>
                ))}
            </ul>
            <button
                type="button"
                onClick={() => {
                    form.setData('attributes', [
                        ...form.data.attributes,
                        {
                            key: '',
                            label: '',
                            type: 'text',
                            options: '',
                            required: false,
                            field_only: false,
                            unit: '',
                            help_text: '',
                        },
                    ]);
                }}
                className="mt-2 text-ui font-semibold text-gold underline underline-offset-2"
            >
                Add a question
            </button>

            {Object.entries(form.errors)
                .filter(([key]) => !['label', 'key', 'geometry_type', 'exclusivity_group'].includes(key))
                .map(([key, error]) => (
                    <p key={key} className="mt-2 text-label text-alert">
                        {error}
                    </p>
                ))}

            <div className="mt-4 flex flex-wrap items-center gap-3">
                <Button
                    variant="primary"
                    onClick={save}
                    busy={form.processing}
                    disabled={form.data.label === '' || form.data.key === ''}
                >
                    {row === null ? 'Add class' : 'Save class'}
                </Button>
                <Button variant="quiet" onClick={onDone}>
                    Cancel
                </Button>
                {row !== null && (
                    <span className="text-label text-faint">
                        Changing the questions saves version {String((row.version ?? 0) + 1)}. Features already
                        captured keep version {String(row.version ?? 1)}.
                    </span>
                )}
            </div>
        </div>
    );
}

/**
 * A catalogue as a list, each class opening into the editor in place.
 */
export function FeatureClassList({
    classes,
    vocabulary,
    postUrl,
    editable = true,
}: {
    classes: FeatureClassRow[];
    vocabulary: ClassVocabulary;
    postUrl: string;
    editable?: boolean;
}) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);

    return (
        <div>
            {editable && editing === 'new' && (
                <FeatureClassEditor
                    row={null}
                    vocabulary={vocabulary}
                    postUrl={postUrl}
                    onDone={() => {
                        setEditing(null);
                    }}
                />
            )}
            {editable && editing !== 'new' && (
                <Button
                    onClick={() => {
                        setEditing('new');
                    }}
                >
                    Add a class
                </Button>
            )}

            <ul className="mt-4 flex flex-col border-t border-rule">
                {classes.map((row) => (
                    <li key={row.id} className="border-b border-rule py-2.5">
                        {editing === row.id ? (
                            <FeatureClassEditor
                                row={row}
                                vocabulary={vocabulary}
                                postUrl={postUrl}
                                onDone={() => {
                                    setEditing(null);
                                }}
                            />
                        ) : (
                            <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                                <span
                                    aria-hidden="true"
                                    className={cx(
                                        'inline-block size-3 shrink-0 self-center border',
                                        row.geometryType === 'point' && 'rounded-full',
                                        row.geometryType === 'line' && 'h-1 w-4 border-0',
                                    )}
                                    style={{
                                        backgroundColor: row.geometryType === 'line' ? row.style?.stroke : row.style?.fill,
                                        borderColor: row.style?.stroke,
                                    }}
                                />
                                <span className={cx('min-w-[180px] flex-1 text-ui', row.isActive ? 'text-ink' : 'text-faint line-through')}>
                                    {row.label}
                                </span>
                                <span className="numeric-mono text-label text-faint">{row.key}</span>
                                <span className="text-label text-muted">{row.geometryLabel}</span>
                                <span className="text-label text-muted">
                                    {row.attributes.length} {row.attributes.length === 1 ? 'question' : 'questions'}
                                </span>
                                <span className="numeric-mono text-label text-faint">v{String(row.version ?? 0)}</span>
                                {row.exclusivityGroup !== null && (
                                    <span className="numeric-mono text-label text-faint">{row.exclusivityGroup}</span>
                                )}
                                {editable && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditing(row.id);
                                        }}
                                        className="text-label text-gold underline underline-offset-2"
                                    >
                                        Edit
                                    </button>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
