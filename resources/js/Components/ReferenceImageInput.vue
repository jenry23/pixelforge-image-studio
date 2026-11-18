<script setup>
import { computed } from 'vue'

/**
 * Up to four reference image URLs.
 *
 * Each one adds exactly 765 input image tokens, which is knowable up front, so
 * the running cost is shown against the control that causes it. Pixelforge never
 * downloads these, so there is nothing to validate beyond the URL itself.
 */
const props = defineProps({
    modelValue: { type: Array, required: true },
    max: { type: Number, default: 4 },
    errors: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const canAdd = computed(() => props.modelValue.length < props.max)

function update(index, value) {
    const next = [...props.modelValue]
    next[index] = value
    emit('update:modelValue', next)
}

function add() {
    if (canAdd.value) emit('update:modelValue', [...props.modelValue, ''])
}

function remove(index) {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    )
}
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between">
            <span class="text-sm text-muted">Reference images</span>
            <span class="text-xs text-dim">
                {{ modelValue.length }} of {{ max }}, 765 tokens each
            </span>
        </div>

        <div v-if="modelValue.length" class="mt-2 space-y-2">
            <div v-for="(url, index) in modelValue" :key="index">
                <div class="flex gap-2">
                    <input
                        type="url"
                        :value="url"
                        placeholder="https://example.com/style.png"
                        class="field w-full px-3 py-2 text-sm"
                        @input="update(index, $event.target.value)"
                    />
                    <button
                        type="button"
                        class="rounded-md border border-line px-3 text-sm text-muted transition-colors hover:border-fail/50 hover:text-fail"
                        @click="remove(index)"
                    >
                        Remove
                    </button>
                </div>
                <p
                    v-if="errors[`reference_images.${index}`]"
                    class="mt-1 text-xs text-fail"
                >
                    {{ errors[`reference_images.${index}`] }}
                </p>
            </div>
        </div>

        <button
            v-if="canAdd"
            type="button"
            class="mt-2 text-sm text-measured transition-opacity hover:opacity-80"
            @click="add"
        >
            Add a reference image
        </button>
    </div>
</template>
