<script setup lang="ts">
/**
 * The shared shell for a form: the `<form>` element, consistent field spacing,
 * and a typed submit event.
 *
 * Deliberately owns no state. Inertia's `useForm`, the route, and what to do on
 * success stay in the page, because those differ every time; only the markup
 * and the submit guard are the same everywhere.
 *
 * `processing` is that guard: while Inertia has a request in flight the submit
 * event is simply not emitted, so a double click, a second Enter press, or an
 * impatient user cannot fire two requests. Disabling the button alone does not
 * cover Enter-in-a-field.
 */
interface Props {
    /** True while Inertia is submitting. Blocks further submits. */
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
