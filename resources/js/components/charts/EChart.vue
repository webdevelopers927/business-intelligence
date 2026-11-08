<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, MarkLineComponent, TooltipComponent } from 'echarts/components';
import { type ECharts, type EChartsCoreOption, init, use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { onBeforeUnmount, onMounted, ref, shallowRef, watchEffect } from 'vue';
import { type ChartTheme, readChartTheme } from '@/lib/chart-theme';

// Tree-shaken: only the chart types and components ORBIT uses.
use([LineChart, BarChart, PieChart, GridComponent, TooltipComponent, MarkLineComponent, CanvasRenderer]);

/**
 * Thin ECharts wrapper (D-09). The option is built from the current theme, so
 * dark-mode switches re-theme the chart. Animates once on first data only —
 * filter changes update instantly (DESIGN_SYSTEM §5 motion).
 */
// `aria-label` (a summary of the data) falls through to the role="img" root.
// `fill` grows the chart to the remaining panel height (min `height`) so a
// panel stretched by its grid row has no dead space under the plot.
const props = withDefaults(defineProps<{ option: (theme: ChartTheme) => EChartsCoreOption; height?: number; fill?: boolean }>(), { height: 260, fill: false });
const emit = defineEmits<{ select: [params: { dataIndex: number; seriesIndex: number; name: string }] }>();

const el = ref<HTMLDivElement | null>(null);
const chart = shallowRef<ECharts | null>(null);
let rendered = false;
let observer: MutationObserver | null = null;

function render() {
    if (!chart.value) return;
    const option = props.option(readChartTheme()) as Record<string, unknown>;
    chart.value.setOption({ animation: !rendered, animationDuration: 300, ...option }, { notMerge: true });
    rendered = true;
}

onMounted(() => {
    if (!el.value) return;
    chart.value = init(el.value, null, { renderer: 'canvas' });
    chart.value.on('click', (params) => {
        const p = params as { dataIndex: number; seriesIndex?: number; name: string };
        emit('select', { dataIndex: p.dataIndex, seriesIndex: p.seriesIndex ?? 0, name: p.name });
    });
    // Re-render whenever reactive data read inside the option builder changes
    // (started after init so the first run tracks its dependencies).
    stopEffect = watchEffect(render);
    // Re-theme when the html.dark class changes.
    observer = new MutationObserver(() => render());
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

let stopEffect: (() => void) | null = null;
useResizeObserver(el, () => chart.value?.resize());

onBeforeUnmount(() => {
    stopEffect?.();
    observer?.disconnect();
    chart.value?.dispose();
});
</script>

<template>
    <div ref="el" role="img" :style="fill ? { minHeight: `${height}px` } : { height: `${height}px` }" :class="['w-full', fill && 'flex-1']" />
</template>
