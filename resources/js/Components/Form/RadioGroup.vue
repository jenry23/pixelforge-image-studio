<script setup lang="ts">
import { useId } from 'vue'

export interface RadioOption {
    value: string
    label: string
}

interface Props {
    legend: string
    name: string
    options: RadioOption[]
    disabled?: boolean
}

withDefaults(defineProps<Props>(), {
    disabled: false,
})

const model = defineModel<string>({ required: true })

const uid = useId()
</script>

<template>
    <fieldset :disabled="disabled">
        <legend class="text-[1.05rem] font-semibold text-white">{{ legend }}</legend>

        <div class="mt-3 flex gap-1 rounded-xl border border-outline bg-[#101723] p-1">
            <label
                v-for="option in options"
                :key="option.value"
                :for="`${uid}-${option.value}`"
                class="flex-1 cursor-pointer rounded-lg px-3 py-2 text-center text-[0.95rem] transition-colors has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-[#8a9ffd]"
                :class="
                    model === option.value
                        ? 'bg-[#8a9ffd] font-semibold text-[#0b1020]'
                        : 'text-[#c3ccd8] hover:text-white'
                "
            >
                <input
                    :id="`${uid}-${option.value}`"
                    v-model="model"
                    type="radio"
                    :name="name"
                    :value="option.value"
                    class="sr-only"
                />
                {{ option.label }}
            </label>
        </div>
    </fieldset>
</template>
