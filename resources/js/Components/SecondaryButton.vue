<script setup>
defineProps({ type: { type: String, default: 'button' } })
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-line bg-panel px-4 py-2 text-sm text-body transition-colors hover:border-line-bright"
    >
        <slot />
    </button>
</template>
