import { useSyncExternalStore } from 'react';

/**
 * The buyer's cart, in their own browser.
 *
 * A cart is a note to self, so it needs no account and never reaches the
 * server until checkout, and then only as product ids and quantities: every
 * price is read again from the catalogue by PlacePurchase. What is kept here is
 * enough to draw the header count and the checkout lines, nothing a merchant
 * could rely on.
 *
 * One cart per business, because one order is one merchant. Storage can be
 * missing or refuse (a private window, blocked site data), and then the cart
 * simply lasts as long as the page does.
 */
export interface CartLine {
    id: number;
    name: string;
    unit: string | null;
    priceNaira: number;
    quantity: number;
}

export interface BusinessCart {
    businessId: number;
    businessName: string;
    lines: CartLine[];
}

type Carts = Record<string, BusinessCart>;

const KEY = 'geoverify.cart.v1';
const EVENT = 'geoverify:cart';

let memory: Carts = {};
let snapshot: Carts | null = null;

function read(): Carts {
    try {
        const raw = window.localStorage.getItem(KEY);

        return raw === null ? memory : (JSON.parse(raw) as Carts);
    } catch {
        return memory;
    }
}

function write(carts: Carts): void {
    memory = carts;
    snapshot = null;

    try {
        window.localStorage.setItem(KEY, JSON.stringify(carts));
    } catch {
        // Kept in memory for this page only.
    }

    window.dispatchEvent(new Event(EVENT));
}

function subscribe(onChange: () => void): () => void {
    const handler = () => {
        snapshot = null;
        onChange();
    };

    window.addEventListener(EVENT, handler);
    window.addEventListener('storage', handler);

    return () => {
        window.removeEventListener(EVENT, handler);
        window.removeEventListener('storage', handler);
    };
}

function getSnapshot(): Carts {
    snapshot ??= read();

    return snapshot;
}

const EMPTY: Carts = {};

export function useCarts(): Carts {
    return useSyncExternalStore(subscribe, getSnapshot, () => EMPTY);
}

export function useCart(businessId: number): BusinessCart | null {
    return useCarts()[String(businessId)] ?? null;
}

/** Items across every cart, for the header. */
export function countItems(carts: Carts): number {
    return Object.values(carts).reduce(
        (sum, cart) => sum + cart.lines.reduce((n, line) => n + line.quantity, 0),
        0,
    );
}

export function addToCart(business: { id: number; name: string }, product: Omit<CartLine, 'quantity'>): void {
    const carts = { ...read() };
    const key = String(business.id);
    const cart = carts[key] ?? { businessId: business.id, businessName: business.name, lines: [] };
    const existing = cart.lines.find((line) => line.id === product.id);

    carts[key] = {
        ...cart,
        lines: existing
            ? cart.lines.map((line) => (line.id === product.id ? { ...line, quantity: Math.min(999, line.quantity + 1) } : line))
            : [...cart.lines, { ...product, quantity: 1 }],
    };

    write(carts);
}

export function setQuantity(businessId: number, productId: number, quantity: number): void {
    const carts = { ...read() };
    const key = String(businessId);
    const cart = carts[key];

    if (cart === undefined) {
        return;
    }

    const lines =
        quantity <= 0
            ? cart.lines.filter((line) => line.id !== productId)
            : cart.lines.map((line) => (line.id === productId ? { ...line, quantity: Math.min(999, Math.floor(quantity)) } : line));

    if (lines.length === 0) {
        // eslint-disable-next-line @typescript-eslint/no-dynamic-delete
        delete carts[key];
    } else {
        carts[key] = { ...cart, lines };
    }

    write(carts);
}

export function clearCart(businessId: number): void {
    const carts = { ...read() };
    // eslint-disable-next-line @typescript-eslint/no-dynamic-delete
    delete carts[String(businessId)];
    write(carts);
}
