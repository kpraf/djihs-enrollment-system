/**
 * Dates typed the way they appear on a PSA birth certificate or report card.
 * Accepts 04182010, 4/18/2010, 04-18-2010, 2010-04-18 and "April 18, 2010",
 * and returns the date as YYYY-MM-DD, or null if it isn't a real date.
 */
const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

export function parseTypedDate(text: string): string | null {
    const t = text.trim();
    if (!t) return null;

    let y: number, m: number, d: number;
    let match: RegExpMatchArray | null;

    if ((match = t.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/))) {
        [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])];
    } else if ((match = t.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/))) {
        [m, d, y] = [Number(match[1]), Number(match[2]), Number(match[3])];
    } else if ((match = t.match(/^(\d{2})(\d{2})(\d{4})$/))) {
        [m, d, y] = [Number(match[1]), Number(match[2]), Number(match[3])];
    } else if ((match = t.match(/^([A-Za-z]+)\.?\s+(\d{1,2}),?\s+(\d{4})$/))) {
        const i = MONTHS.findIndex((name) => name.startsWith(match![1].toLowerCase()) && match![1].length >= 3);
        if (i < 0) return null;
        [m, d, y] = [i + 1, Number(match[2]), Number(match[3])];
    } else {
        return null;
    }

    const date = new Date(Date.UTC(y, m - 1, d));
    if (date.getUTCFullYear() !== y || date.getUTCMonth() !== m - 1 || date.getUTCDate() !== d) return null;

    return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

/** 2010-04-18 → 04/18/2010, the way the school writes dates. */
export function toTypedDate(iso: string): string {
    const match = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? `${match[2]}/${match[3]}/${match[1]}` : iso;
}

/**
 * Adds the slashes while someone types digits only: 0418 → 04/18, so the
 * box always looks like MM/DD/YYYY. Anything else is left as typed.
 */
export function formatWhileTyping(text: string): string {
    // Only reshape text that looks like our own MM/DD/ pattern, so a date
    // typed as 4/18/2010 is left alone.
    if (!/^(\d{2}\/){0,2}\d*$/.test(text)) return text;
    const digits = text.replace(/\//g, '').slice(0, 8);
    // A year typed first (2010-04-18) starts with digits that can't be a month.
    if (digits.length >= 2 && (Number(digits.slice(0, 2)) > 12 || digits.startsWith('00'))) return text;
    if (digits.length <= 2) return digits;
    if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`;
    return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
}

export function describeDate(iso: string): string {
    const [y, m, d] = iso.split('-').map(Number);
    return `${MONTHS[m - 1][0].toUpperCase()}${MONTHS[m - 1].slice(1)} ${d}, ${y}`;
}
