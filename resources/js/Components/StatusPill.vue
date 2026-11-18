<script setup>
/**
 * A generation's current state.
 *
 * Seven states, because three of them are ours rather than Pixelforge's:
 * `submitting` before we have a create response, `unconfirmed` when a
 * submission ended ambiguously and we are still establishing what happened, and
 * `rejected` when the provider refused outright. Collapsing those into "failed"
 * would hide the one case where the user's money is still reserved.
 */
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
}

const isRunning = (s) => ['pending', 'queued', 'processing', 'unknown'].includes(s)
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs"
        :class="tone[props.status] ?? tone.pending"
    >
        <!-- The dot pulses only while we are genuinely waiting on the provider,
             so motion means "something is still happening" and nothing else. -->
        <span
            class="size-1.5 rounded-full bg-current"
            :class="{ 'animate-pulse': isRunning(props.status) }"
            aria-hidden="true"
        />
        {{ props.label }}
    </span>
</template>
