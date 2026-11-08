import { describe, expect, it } from 'vitest';
import { formatCompact, formatCurrency, formatNumber, formatPercentChange, formatRelative } from './format';
import { initials } from './utils';

describe('formatPercentChange', () => {
    it('signs positive and negative changes', () => {
        expect(formatPercentChange(6.24)).toBe('+6.2%');
        expect(formatPercentChange(-3.05)).toBe('−3.0%');
        expect(formatPercentChange(0)).toBe('0.0%');
    });

    it('renders non-computable changes as an em dash, never Infinity/NaN', () => {
        expect(formatPercentChange(null)).toBe('—');
        expect(formatPercentChange(Number.POSITIVE_INFINITY)).toBe('—');
        expect(formatPercentChange(Number.NaN)).toBe('—');
    });
});

describe('number formatting', () => {
    it('formats with thousands separators', () => {
        expect(formatNumber(1284503)).toBe('1,284,503');
        expect(formatNumber(12.5, 1)).toBe('12.5');
    });

    it('formats currency with a narrow symbol', () => {
        // ICU separates the PKR symbol with a no-break space.
        expect(formatCurrency(18_400_000, 'PKR').replace(/\s/g, ' ')).toBe('Rs 18,400,000');
        expect(formatCurrency(null, 'PKR')).toBe('—');
    });

    it('compacts large values for axes', () => {
        expect(formatCompact(1_240_000)).toBe('1.2M');
        expect(formatCompact(45_000)).toBe('45K');
        expect(formatCompact(800)).toBe('800');
    });
});

describe('formatRelative', () => {
    const now = new Date('2026-11-08T12:00:00Z');

    it('describes recent and past times', () => {
        expect(formatRelative('2026-11-08T11:59:30Z', now)).toBe('just now');
        expect(formatRelative('2026-11-08T11:57:00Z', now)).toBe('3 minutes ago');
        expect(formatRelative('2026-11-06T12:00:00Z', now)).toBe('2 days ago');
    });
});

describe('initials', () => {
    it('uses first and last word', () => {
        expect(initials('Nexora Group')).toBe('NG');
        expect(initials('Ayesha Khan Malik')).toBe('AM');
        expect(initials('Orbit')).toBe('OR');
        expect(initials('  ')).toBe('?');
    });
});
