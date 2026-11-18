<script setup lang="ts">
import { computed, useId } from 'vue'

interface Props {
    label: string
    hint?: string
    error?: string
    placeholder?: string
    rows?: number
    maxlength?: number
    required?: boolean
    disabled?: boolean
    showCount?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    hint: undefined,
    error: undefined,
    placeholder: undefined,
    rows: 5,
    maxlength: undefined,
    required: false,
    disabled: false,
    showCount: false,
})

const model = defineModel<string>({ required: true })

const uid = useId()
const fieldId = `${uid}-textarea`
const hintId = `${uid}-hint`
const errorId = `${uid}-error`
const countId = `${uid}-count`

const describedBy = computed(() => {
    const ids = [
        props.hint ? hintId : null,
        props.showCount ? countId : null,
        props.error ? errorId : null,
    ].filter(Boolean)

    return ids.length ? ids.join(' ') : undefined
})

const count = computed(() => [...model.value].length)
</script>

<template>
    <div>
        <label :for="fieldId" class="block text-[1.05rem] font-semibold text-white">
            {{ label }}
        </label>

        <textarea
            :id="fieldId"
            v-model="model"
            :rows="rows"
            :placeholder="placeholder"
            :maxlength="maxlength"
            :required="required"
            :disabled="disabled"
            :aria-describedby="describedBy"
            :aria-invalid="error ? true : undefined"
            class="mt-3 block w-full resize-y rounded-xl border border-[#262d39] bg-[#101723] px-4 py-3 text-[1.0625rem] leading-relaxed text-white placeholder:text-[#a5acb7] focus:border-[#8a9ffd] focus:outline-none focus:ring-1 focus:ring-[#8a9ffd] disabled:opacity-60"
        />

        <div class="mt-2 flex items-start justify-between gap-4">
            <p v-if="error" :id="errorId" role="alert" class="text-xs text-fail">
                {{ error }}
            </p>
            <p v-else-if="hint" :id="hintId" class="text-xs text-[#9aa5b4]">
                {{ hint }}
            </p>
            <span v-else />

            <span
                v-if="showCount && maxlength"
                :id="countId"
                class="figure shrink-0 text-xs text-[#9aa5b4]"
            >
                {{ count.toLocaleString() }} / {{ maxlength.toLocaleString() }}
            </span>
        </div>
    </div>
</template>
