<script setup>
defineProps({ message: { type: String, default: null } })
</script>

<template>
    <p v-show="message" class="text-xs text-fail">{{ message }}</p>
</template>
