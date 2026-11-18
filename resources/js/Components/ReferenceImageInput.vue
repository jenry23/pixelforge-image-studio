<script setup>
import { computed } from 'vue'

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
            <span class="text-[1.05rem] font-semibold text-white">Reference images</span>
            <span class="text-xs text-[#9aa5b4]">
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
                        class="rounded-lg border border-outline px-3 text-sm text-white transition-colors hover:border-fail/60 hover:text-fail"
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
            class="mt-3 inline-flex items-center gap-2 rounded-xl border border-outline px-4 py-2.5 text-sm text-white transition-colors hover:border-[#8a9ffd] hover:text-[#8a9ffd] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd]"
            @click="add"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                class="size-4 shrink-0"
                aria-hidden="true"
            >
                <path d="M12 5v14M5 12h14" />
            </svg>
            Add a reference image
        </button>
    </div>
</template>
