<script setup>
/**
 * A small set of mutually exclusive choices, shown all at once.
 *
 * A segmented control rather than a select, because size and quality both move
 * the price and the user should be able to see every option and its effect
 * without opening anything.
 */
defineProps({
    options: { type: Array, required: true },
    modelValue: { type: String, required: true },
    label: { type: String, required: true },
    name: { type: String, required: true },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <fieldset>
        <legend class="text-sm text-muted">{{ label }}</legend>

        <div class="mt-2 flex flex-wrap gap-1 rounded-md border border-line bg-ink p-1">
            <label
                v-for="option in options"
                :key="option.value"
                class="flex-1 cursor-pointer rounded px-3 py-1.5 text-center text-sm transition-colors"
                :class="
                    modelValue === option.value
                        ? 'bg-panel-raised text-body shadow-sm'
                        : 'text-muted hover:text-body'
                "
            >
                <input
                    type="radio"
                    :name="name"
                    :value="option.value"
                    :checked="modelValue === option.value"
                    class="sr-only"
                    @change="$emit('update:modelValue', option.value)"
                />
                {{ option.label }}
            </label>
        </div>
    </fieldset>
</template>
