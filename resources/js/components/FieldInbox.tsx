import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { cx } from '@/lib/cx';
import { clock } from '@/lib/fieldDay';
import type { LocalMessage } from '@/lib/offline/db';
import { markInboxRead, sendMessage } from '@/lib/offline/messages';

const QUICK_REPLIES = ['On my way', 'Site closed', 'Access denied', 'Need help'] as const;

const LABEL: Record<LocalMessage['kind'], string> = {
    returned_record: 'Returned record',
    cell_assigned: 'Cell reassigned',
    broadcast: 'Team broadcast',
    text: 'Message',
};

/** One structured message as a card, the mockup's inbox panel. */
function Card({ message }: { message: LocalMessage }) {
    const tone =
        message.kind === 'returned_record'
            ? 'border-alert/30 bg-alert-soft'
            : message.kind === 'cell_assigned'
              ? 'border-held/30 bg-held-soft'
              : 'border-rule bg-sunken';
    const ink = message.kind === 'returned_record' ? 'text-alert-ink' : message.kind === 'cell_assigned' ? 'text-held-ink' : 'text-muted';

    return (
        <article className={cx('rounded-card border px-4 py-3.5', tone)}>
            <p className="flex items-center justify-between gap-3 text-label font-extrabold tracking-[0.05em] uppercase">
                <span className={ink}>{LABEL[message.kind]}</span>
                <span className="font-semibold tracking-normal text-muted normal-case">{clock(message.sentAt)}</span>
            </p>
            <p className="mt-1.5 text-ui text-ink">
                {message.record !== null && <span className="font-bold">{message.record.ref} </span>}
                {message.body}
            </p>
            {message.kind === 'returned_record' && message.record?.assignmentId != null && (
                <Link
                    href={`/field/assignments/${String(message.record.assignmentId)}/capture`}
                    className="mt-3 inline-flex min-h-[40px] items-center rounded-sm bg-alert px-4 text-ui font-extrabold text-on-accent"
                >
                    Re-capture {message.record.ref}
                </Link>
            )}
            {message.kind === 'cell_assigned' && (
                <Link href="/field/map" className="mt-3 inline-flex min-h-[40px] items-center rounded-sm bg-raised px-4 text-ui font-bold text-held-ink">
                    Show on map
                </Link>
            )}
        </article>
    );
}

function Bubble({ message }: { message: LocalMessage }) {
    const mine = message.direction === 'from_officer';

    return (
        <div className={cx('flex', mine ? 'justify-end' : 'justify-start')}>
            <div className={cx('max-w-[85%] rounded-card px-4 py-2.5 text-ui', mine ? 'bg-gold text-on-accent' : 'bg-raised text-ink shadow-card')}>
                <p className="whitespace-pre-wrap">{message.body}</p>
                <p className={cx('mt-1 text-table', mine ? 'text-on-accent/80' : 'text-muted')}>
                    {clock(message.sentAt)}
                    {mine && (message.pending === 1 ? ' · Waiting for signal' : ' · Sent')}
                </p>
            </div>
        </div>
    );
}

export function MessageItem({ message }: { message: LocalMessage }) {
    return message.kind === 'text' ? <Bubble message={message} /> : <Card message={message} />;
}

/** The pinned note, most recent first, as the strip under the chat header. */
export function PinnedNote({ messages }: { messages: LocalMessage[] }) {
    const pinned = [...messages].reverse().find((m) => m.pinned);

    if (pinned === undefined) {
        return null;
    }

    return (
        <p className="flex gap-2 border-b border-amber/30 bg-amber-soft px-4 py-2.5 text-table text-amber-ink max-w-none">
            <span className="font-extrabold">Pinned:</span>
            <span className="text-ink">{pinned.body}</span>
        </p>
    );
}

/** Quick replies and a message box. Written locally first, sent when there is signal. */
export function Composer({ to }: { to: string }) {
    const [text, setText] = useState('');

    const send = (body: string) => {
        void sendMessage(body);
        setText('');
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap gap-2">
                {QUICK_REPLIES.map((reply) => (
                    <button
                        key={reply}
                        type="button"
                        onClick={() => {
                            send(reply);
                        }}
                        className="min-h-[36px] rounded-full border border-rule-strong bg-raised px-3.5 text-table font-bold text-ink hover:bg-sunken"
                    >
                        {reply}
                    </button>
                ))}
            </div>
            <form
                className="flex gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    send(text);
                }}
            >
                <label htmlFor="reply" className="sr-only">
                    Reply to {to}
                </label>
                <input
                    id="reply"
                    value={text}
                    onChange={(e) => {
                        setText(e.target.value);
                    }}
                    maxLength={1000}
                    placeholder={`Reply to ${to}…`}
                    className="h-12 min-w-0 flex-1 rounded-sm border border-rule-strong bg-raised px-4 text-ui text-ink focus:border-gold focus:outline-2 focus:outline-gold"
                />
                <button type="submit" aria-label="Send" disabled={text.trim() === ''} className="flex size-12 shrink-0 items-center justify-center rounded-sm bg-gold text-on-accent disabled:bg-sunken disabled:text-muted">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <path d="M4 12 20 4l-6 16-2.5-6.5z" />
                    </svg>
                </button>
            </form>
        </div>
    );
}

/** The thread, scrolled to the latest, marked read once seen. */
export function Thread({ messages, className }: { messages: LocalMessage[]; className?: string }) {
    const end = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
        void markInboxRead();
    }, [messages.length]);

    return (
        <div className={cx('flex flex-col gap-3 overflow-y-auto', className)}>
            {messages.length === 0 ? (
                <p className="py-8 text-center text-ui text-muted max-w-none">Nothing from your supervisor yet.</p>
            ) : (
                messages.map((m) => <MessageItem key={m.uuid} message={m} />)
            )}
            <div ref={end} />
        </div>
    );
}
