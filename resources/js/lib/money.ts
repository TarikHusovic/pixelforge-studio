import type { Nanos } from '@/types';

export const NANOS_PER_DOLLAR = 1_000_000_000;

const rounded = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
});

/** For reading: 46_125_000 => "$0.0461". */
export function formatUsd(nanos: Nanos): string {
    return rounded.format(nanos / NANOS_PER_DOLLAR);
}

/** Exact, with nine decimals like Pixelforge reports: 46_125_000 => "$0.046125000". */
export function formatUsdExact(nanos: Nanos): string {
    const sign = nanos < 0 ? '-' : '';
    const digits = String(Math.abs(nanos)).padStart(10, '0');

    return `${sign}$${digits.slice(0, -9)}.${digits.slice(-9)}`;
}
