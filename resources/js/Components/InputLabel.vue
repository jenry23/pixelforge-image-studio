<script setup>
defineProps({ value: { type: String, default: null } })
</script>

<template>
    <label class="block text-sm text-muted">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
