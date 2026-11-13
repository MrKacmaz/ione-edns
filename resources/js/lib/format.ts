/**
 * Format a duration in milliseconds, switching to seconds from one second on.
 */
export function formatMilliseconds(milliseconds: number | null): string {
    if (milliseconds === null) {
        return '–';
    }

    return milliseconds < 1000
        ? `${Math.round(milliseconds)} ms`
        : `${(milliseconds / 1000).toFixed(1)} s`;
}

/**
 * Format an age in seconds as seconds, minutes or hours.
 */
export function formatAge(seconds: number | null): string {
    if (seconds === null) {
        return '–';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    }

    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

/**
 * Format a ratio between zero and one as a percentage.
 */
export function formatRatio(ratio: number | null): string {
    return ratio === null ? '–' : `${(ratio * 100).toFixed(1)}%`;
}

/**
 * Format a count with thousands separators.
 */
export function formatCount(count: number): string {
    return new Intl.NumberFormat('en').format(count);
}

/**
 * Describe how long ago a moment was, relative to now.
 */
export function formatSince(moment: string, now: number): string {
    const seconds = Math.max(0, Math.round((now - Date.parse(moment)) / 1000));

    return `${formatAge(seconds)} ago`;
}
