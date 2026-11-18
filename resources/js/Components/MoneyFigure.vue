<script setup>
/**
 * A money amount.
 *
 * `certainty` is the whole point: an amber figure is one we do not know yet, a
 * cyan figure is one Pixelforge has settled and we hold exactly. Those two hues
 * are used nowhere else in the interface, so the colour alone tells you whether
 * a number is a guess.
 *
 * The exact nine-decimal value is always available on hover, even when a
 * rounded one is displayed, so nothing is ever only approximately inspectable.
 */
const props = defineProps({
    /** Pre-rounded display string from the server, e.g. "0.014634". */
    value: { type: String, required: true },
    /** Exact nine-decimal string, shown on hover. */
    exact: { type: String, default: null },
    certainty: {
        type: String,
        default: 'measured',
        validator: (v) => ['measured', 'estimate', 'muted', 'free'].includes(v),
    },
    size: { type: String, default: 'base' },
})

const tone = {
    measured: 'text-measured',
    estimate: 'text-estimate',
    muted: 'text-dim',
    free: 'text-ok',
}

const scale = {
    sm: 'text-xs',
    base: 'text-sm',
    lg: 'text-2xl',
    xl: 'text-4xl',
}
</script>

<template>
    <span
        class="figure whitespace-nowrap tabular-nums"
        :class="[tone[props.certainty], scale[props.size]]"
        :title="props.exact ? `$${props.exact}` : null"
    >
        <span class="text-dim">$</span>{{ props.value }}
    </span>
</template>
