/**
 * Chart theme derived from CSS tokens (DESIGN_SYSTEM §3, §8), so charts
 * follow light/dark mode exactly like the rest of the UI. Read at render time.
 */
export interface ChartTheme {
    dark: boolean;
    series: string[];
    other: string;
    text: string;
    secondary: string;
    muted: string;
    border: string;
    borderStrong: string;
    surface: string;
    raised: string;
    positive: string;
    negative: string;
    font: string;
}

export function readChartTheme(): ChartTheme {
    const css = getComputedStyle(document.documentElement);
    const v = (name: string) => css.getPropertyValue(name).trim();

    return {
        dark: document.documentElement.classList.contains('dark'),
        series: [1, 2, 3, 4, 5, 6, 7].map((i) => v(`--series-${i}`)),
        other: v('--series-other'),
        text: v('--text'),
        secondary: v('--text-secondary'),
        muted: v('--text-muted'),
        border: v('--border'),
        borderStrong: v('--border-strong'),
        surface: v('--surface'),
        raised: v('--surface-raised'),
        positive: v('--positive'),
        negative: v('--negative'),
        font: "'Inter Variable', ui-sans-serif, system-ui, sans-serif",
    };
}

/** Fixed-order categorical color; entities beyond slot 7 fold into "Other" gray. */
export function seriesColor(theme: ChartTheme, index: number | null | undefined): string {
    if (index === null || index === undefined || index < 0 || index >= theme.series.length) {
        return theme.other;
    }
    return theme.series[index]!;
}

/** Shared axis/grid/tooltip styling: recessive chrome, data first. */
export function baseOption(theme: ChartTheme) {
    return {
        textStyle: { fontFamily: theme.font, color: theme.secondary, fontSize: 12 },
        grid: { left: 8, right: 16, top: 12, bottom: 4, containLabel: true },
        tooltip: {
            backgroundColor: theme.raised,
            borderColor: theme.border,
            borderWidth: 1,
            padding: [8, 10],
            textStyle: { color: theme.text, fontFamily: theme.font, fontSize: 12 },
            extraCssText: 'box-shadow: var(--elevation-2); border-radius: 6px;',
        },
        categoryAxis: {
            axisLine: { lineStyle: { color: theme.borderStrong } },
            axisTick: { show: false },
            axisLabel: { color: theme.muted, fontSize: 11, hideOverlap: true },
            splitLine: { show: false },
        },
        valueAxis: {
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: theme.muted, fontSize: 11 },
            splitLine: { lineStyle: { color: theme.border, width: 1 } },
        },
    };
}

/** Tooltip row: 8px swatch + name + right-aligned tabular value. */
export function tooltipRow(color: string, name: string, value: string, emphasis = false): string {
    return `<div style="display:flex;align-items:center;gap:8px;min-width:180px;line-height:20px">
        <span style="width:8px;height:8px;border-radius:2px;background:${color};flex:none"></span>
        <span style="flex:1;color:var(--text-secondary)">${escapeHtml(name)}</span>
        <span style="font-variant-numeric:tabular-nums;${emphasis ? 'font-weight:600;' : ''}color:var(--text)">${escapeHtml(value)}</span>
    </div>`;
}

export function tooltipHeader(text: string): string {
    return `<div style="font-size:11px;color:var(--text-secondary);margin-bottom:4px">${escapeHtml(text)}</div>`;
}

export function escapeHtml(value: string): string {
    return value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}
