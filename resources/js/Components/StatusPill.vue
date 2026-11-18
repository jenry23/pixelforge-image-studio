<script setup lang="ts">
import { computed } from 'vue'
import Spinner from '@/Components/Icons/Spinner.vue'

const props = defineProps<{
    status: string
    label: string
}>()

const tone: Record<string, string> = {
    pending: 'text-[#8a9ffd] border-[#8a9ffd]/60',
    queued: 'text-[#8a9ffd] border-[#8a9ffd]/60',
    processing: 'text-[#8a9ffd] border-[#8a9ffd]/60',
    succeeded: 'text-ok border-ok/40',
    failed: 'text-fail border-fail/40',
    rejected: 'text-fail border-fail/40',
    unknown: 'text-estimate border-estimate/40',
    unresolved: 'text-estimate border-estimate/40',
}

// Pixelforge reports no progress, only a status, so this says that work is
// happening without implying how far along it is.
const running = computed(() => ['pending', 'queued', 'processing'].includes(props.status))
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs"
        :class="tone[props.status] ?? tone.pending"
    >
        <Spinner v-if="running" class="size-3 shrink-0" />
        <span
            v-else
            class="size-1.5 rounded-full bg-current"
            :class="{ 'animate-pulse motion-reduce:animate-none': status === 'unknown' || status === 'unresolved' }"
            aria-hidden="true"
        />
        {{ props.label }}
    </span>
</template>
