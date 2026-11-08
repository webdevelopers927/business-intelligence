import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Merge class lists, letting later Tailwind utilities override earlier ones. */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}

/** Two-letter initials for avatars and organization monograms. */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) {
        return '?';
    }
    const first = parts[0]!.charAt(0);
    const second = parts.length > 1 ? parts[parts.length - 1]!.charAt(0) : (parts[0]!.charAt(1) ?? '');
    return (first + second).toUpperCase();
}
