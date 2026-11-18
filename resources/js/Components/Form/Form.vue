<script setup lang="ts">
interface Props {
    processing?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    processing: false,
})

const emit = defineEmits<{
    submit: []
}>()

function handleSubmit(): void {
    if (props.processing) {
        return
    }

    emit('submit')
}
</script>

<template>
    <form :aria-busy="props.processing || undefined" @submit.prevent="handleSubmit">
        <div class="space-y-[1.55rem] [@media(max-height:800px)]:space-y-[1rem]">
            <slot />
        </div>

        <slot name="actions" />
        <slot name="footer" />
    </form>
</template>
