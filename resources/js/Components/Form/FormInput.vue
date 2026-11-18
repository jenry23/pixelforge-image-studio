<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import Eye from '@/Components/Icons/Eye.vue'
import EyeSlash from '@/Components/Icons/EyeSlash.vue'

export type FormInputType = 'text' | 'email' | 'password' | 'url'

interface Props {
    label: string
    name: string
    id?: string

    type?: FormInputType

    hint?: string

    error?: string
    placeholder?: string
    autocomplete?: string
    required?: boolean
    disabled?: boolean
    readonly?: boolean
    autofocus?: boolean
    maxlength?: number

    revealable?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    id: undefined,
    type: 'text',
    hint: undefined,
    error: undefined,
    placeholder: undefined,
    autocomplete: undefined,
    required: false,
    disabled: false,
    readonly: false,
    autofocus: false,
    maxlength: undefined,
    revealable: true,
})

const model = defineModel<string>({ required: true })

const uid = useId()
const inputId = computed(() => props.id ?? props.name ?? `${uid}-input`)
const hintId = `${uid}-hint`
const errorId = `${uid}-error`

const revealed = ref(false)

const isPassword = computed(() => props.type === 'password')
const canReveal = computed(() => isPassword.value && props.revealable && !props.disabled)

const resolvedType = computed<FormInputType>(() =>
    isPassword.value && revealed.value ? 'text' : props.type,
)

const describedBy = computed(() => {
    const ids = [props.hint ? hintId : null, props.error ? errorId : null].filter(Boolean)

    return ids.length ? ids.join(' ') : undefined
})

const inputRef = ref<HTMLInputElement | null>(null)

defineExpose({ focus: () => inputRef.value?.focus() })
</script>

<template>
    <div>
        <label :for="inputId" class="block text-[1.05rem] font-semibold leading-none text-white sm:text-[1.25rem]">
            {{ label }}
        </label>

        <div class="relative mt-[0.7rem] [@media(max-height:800px)]:mt-[0.45rem]">
            <input
                :id="inputId"
                ref="inputRef"
                :name="name"
                v-model="model"
                :type="resolvedType"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :required="required"
                :disabled="disabled"
                :readonly="readonly"
                :autofocus="autofocus"
                :maxlength="maxlength"
                :aria-describedby="describedBy"
                :aria-invalid="error ? true : undefined"
                class="block h-[3.4rem] w-full rounded-xl border border-[#262d39] bg-[#101723] text-[1.0625rem] text-white placeholder:text-[#a5acb7] focus:border-[#8a9ffd] focus:outline-none focus:ring-1 focus:ring-[#8a9ffd] disabled:opacity-60 sm:h-[3.75rem] sm:text-[1.2rem] [@media(max-height:800px)]:sm:h-[3.1rem]"
                :class="canReveal ? 'pl-4 pr-14' : 'px-4'"
            />

            <button
                v-if="canReveal"
                type="button"
                :aria-label="revealed ? 'Hide password' : 'Show password'"
                :aria-pressed="revealed"
                :aria-controls="inputId"
                class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-[#eef1f6] transition-colors hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd]"
                @click="revealed = !revealed"
            >
                <component :is="revealed ? EyeSlash : Eye" class="size-5" />
            </button>
        </div>

        <p v-if="hint" :id="hintId" class="mt-2 text-xs text-[#9aa5b4]">
            {{ hint }}
        </p>

        <p v-if="error" :id="errorId" role="alert" class="mt-2 text-xs text-fail">
            {{ error }}
        </p>
    </div>
</template>
