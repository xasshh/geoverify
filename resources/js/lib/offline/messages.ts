import { useCallback, useEffect, useState } from 'react';
import { liveQuery } from 'dexie';
import { csrfToken, uuid7 } from '@/lib/capture';
import { db, type LocalMessage } from '@/lib/offline/db';

/**
 * The officer's inbox, readable with no signal.
 *
 * The thread lives in IndexedDB. Online, it is refreshed from the server; a
 * reply is written locally first (`pending`) and sent from there, so typing
 * "on my way" in a dead zone is not lost, and the same uuid sent twice is one
 * message on the server. This is its own channel and never touches the
 * capture queue or the sync contract.
 */
type ServerMessage = Omit<LocalMessage, 'pending'> & { id: number };

async function refresh(): Promise<void> {
    const latest = await db.messages.where('id').above(0).last();
    const after = latest?.id ?? 0;
    const response = await fetch(`/api/field/messages?after=${String(after)}`, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        return;
    }

    const body = (await response.json()) as { messages: ServerMessage[] };

    await db.messages.bulkPut(body.messages.map((m) => ({ ...m, pending: 0 as const })));
}

async function flush(): Promise<void> {
    const pending = await db.messages.where('pending').equals(1).toArray();

    for (const message of pending) {
        const response = await fetch('/api/field/messages', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ client_uuid: message.uuid, body: message.body, sent_at: message.sentAt }),
        });

        if (!response.ok) {
            // Left pending. A 4xx here is a message the server refuses, and the
            // officer sees it stay unsent rather than vanish.
            return;
        }

        const saved = ((await response.json()) as { message: ServerMessage }).message;
        await db.messages.put({ ...saved, pending: 0 });
    }
}

export async function syncInbox(): Promise<void> {
    if (!navigator.onLine) {
        return;
    }

    try {
        await flush();
        await refresh();
    } catch {
        // No signal after all. Everything stays where it is.
    }
}

export async function markInboxRead(): Promise<void> {
    const unread = await db.messages.filter((m) => m.direction === 'to_officer' && !m.read && m.id !== null).toArray();
    const upTo = Math.max(0, ...unread.map((m) => m.id ?? 0));

    if (upTo === 0) {
        return;
    }

    await db.messages.bulkPut(unread.map((m) => ({ ...m, read: true })));

    if (navigator.onLine) {
        await fetch('/api/field/messages/read', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ up_to: upTo }),
        }).catch(() => undefined);
    }
}

export async function sendMessage(body: string): Promise<void> {
    const text = body.trim();

    if (text === '') {
        return;
    }

    await db.messages.put({
        uuid: uuid7(),
        id: null,
        direction: 'from_officer',
        kind: 'text',
        body: text,
        sender: null,
        senderRef: null,
        sentAt: new Date().toISOString(),
        pinned: false,
        read: false,
        record: null,
        pending: 1,
    });

    await syncInbox();
}

/** The thread in order, the unread count, and whether it is refreshing. */
export function useInbox(): { messages: LocalMessage[]; unread: number; syncing: boolean; sync: () => Promise<void> } {
    const [messages, setMessages] = useState<LocalMessage[]>([]);
    const [syncing, setSyncing] = useState(false);

    // Dexie's own observable: redraws whenever the table changes, whichever
    // screen wrote to it.
    useEffect(() => {
        const subscription = liveQuery(() => db.messages.orderBy('sentAt').toArray()).subscribe({
            next: setMessages,
            error: () => undefined,
        });

        return () => {
            subscription.unsubscribe();
        };
    }, []);

    const sync = useCallback(async () => {
        setSyncing(true);
        await syncInbox();
        setSyncing(false);
    }, []);

    // The background refresh does not show a spinner, so it calls the plain
    // sync; only the "Sync now" button goes through `sync` and its flag.
    useEffect(() => {
        void syncInbox();

        const online = () => {
            void syncInbox();
        };
        const timer = window.setInterval(online, 30_000);
        window.addEventListener('online', online);

        return () => {
            window.clearInterval(timer);
            window.removeEventListener('online', online);
        };
    }, []);

    return {
        messages,
        unread: messages.filter((m) => m.direction === 'to_officer' && !m.read).length,
        syncing,
        sync,
    };
}
