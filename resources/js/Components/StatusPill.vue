<script setup>
const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, required: true },
})

const tone = {
    pending: 'text-muted border-line-bright',
    queued: 'text-muted border-line-bright',
    processing: 'text-measured border-measured/40',
    succeeded: 'text-ok border-ok/40',
    failed: 'text-fail border-fail/40',
    rejected: 'text-fail border-fail/40',
    unknown: 'text-estimate border-estimate/40',
    unresolved: 'text-estimate border-estimate/40',
}

const isRunning = (s) => ['pending', 'queued', 'processing', 'unknown', 'unresolved'].includes(s)
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs"
        :class="tone[props.status] ?? tone.pending"
    >
        <span
            class="size-1.5 rounded-full bg-current"
            :class="{ 'animate-pulse': isRunning(props.status) }"
            aria-hidden="true"
        />
        {{ props.label }}
    </span>
</template>
