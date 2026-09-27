import type { ReactNode } from 'react';
import { cx } from '@/lib/cx';

export interface Column<Row> {
    key: string;
    header: string;
    /** Machine values get mono and right alignment so digits form a column. */
    numeric?: boolean;
    width?: string;
    render: (row: Row) => ReactNode;
}

interface DataTableProps<Row> {
    columns: ReadonlyArray<Column<Row>>;
    rows: readonly Row[];
    rowKey: (row: Row) => string;
    caption: string;
    /** Shown instead of rows. An empty state is an invitation, not an apology. */
    empty?: ReactNode;
    onRowActivate?: (row: Row) => void;
}

/**
 * The console's primary interface.
 *
 * Sticky header, tabular figures, and full keyboard operation: rows are reachable
 * by Tab and activate on Enter or Space, because a supervisor works this for hours
 * and should never have to reach for the mouse.
 */
export function DataTable<Row>({
    columns,
    rows,
    rowKey,
    caption,
    empty,
    onRowActivate,
}: DataTableProps<Row>) {
    if (rows.length === 0 && empty !== undefined) {
        return (
            <div className="rounded-card border border-rule bg-raised">
                <div className="border-b border-rule px-5 py-3.5 text-table font-bold text-muted">
                    {caption}
                </div>
                <div className="px-4 py-10 text-center text-ui text-muted">{empty}</div>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto rounded-card border border-rule bg-raised">
            <table className="w-full border-collapse text-table">
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                style={column.width === undefined ? undefined : { width: column.width }}
                                className={cx(
                                    'sticky top-0 z-10 border-b border-rule bg-raised px-4 py-3.5',
                                    'text-table font-bold text-muted',
                                    column.numeric === true ? 'text-right' : 'text-left',
                                )}
                            >
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={rowKey(row)}
                            {...(onRowActivate === undefined
                                ? {}
                                : {
                                      tabIndex: 0,
                                      onClick: () => {
                                          onRowActivate(row);
                                      },
                                      onKeyDown: (event) => {
                                          if (event.key === 'Enter' || event.key === ' ') {
                                              event.preventDefault();
                                              onRowActivate(row);
                                          }
                                      },
                                  })}
                            className={cx(
                                'border-b border-rule last:border-b-0',
                                onRowActivate !== undefined &&
                                    'cursor-pointer hover:bg-surface focus-visible:bg-surface',
                            )}
                        >
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={cx(
                                        'px-4 py-3 align-middle text-ink',
                                        column.numeric === true && 'text-right numeric-mono',
                                    )}
                                >
                                    {column.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
