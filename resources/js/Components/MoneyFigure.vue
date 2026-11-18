<script setup>
const props = defineProps({
    value: { type: String, required: true },

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
