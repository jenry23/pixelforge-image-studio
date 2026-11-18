<script setup>
const model = defineModel({ type: [Array, Boolean], required: true })
defineProps({ value: { type: [String, Number, Boolean], default: null } })
</script>

<template>
    <input
        v-model="model"
        :value="value"
        type="checkbox"
        class="rounded border-line bg-ink text-measured focus:ring-measured"
    />
</template>
