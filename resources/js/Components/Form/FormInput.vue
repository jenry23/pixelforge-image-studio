<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import Eye from '@/Components/Icons/Eye.vue'
import EyeSlash from '@/Components/Icons/EyeSlash.vue'

/**
 * A labelled text field: label, input, optional hint, and validation error.
 *
 * The field owns the wiring that is easy to forget and invisible when missing —
 * the label's `for`, `aria-describedby` pointing at the hint and the error, and
 * `aria-invalid` — so a screen reader hears the error with the field rather
 * than as loose text somewhere on the page. IDs come from Vue's `useId()`, so
 * they are stable across renders and unique even with two of these on a page.
 *
 * Password fields can be revealed. The toggle is a real button with
 * `aria-pressed`, and it is never a tab-stop trap: it sits after the input in
 * DOM order, so tabbing goes field, toggle, next field.
 */
export type FormInputType = 'text' | 'email' | 'password' | 'url'

interface Props {
    label: string
    /** Native input type. `password` gains a reveal toggle unless disabled. */
    type?: FormInputType
    /** Help text rendered under the field and announced with it. */
    hint?: string
    /** Validation message from the server; also sets `aria-invalid`. */
    error?: string
    placeholder?: string
    autocomplete?: string
    required?: boolean
    disabled?: boolean
    readonly?: boolean
    autofocus?: boolean
    maxlength?: number
    /** Allow revealing a password field. Ignored for other types. */
    revealable?: boolean
}

const props = withDefaults(defineProps<Props>(), {
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

/** Typed two-way binding; always a string, including for password fields. */
const model = defineModel<string>({ required: true })

const uid = useId()
const inputId = `${uid}-input`
const hintId = `${uid}-hint`
const errorId = `${uid}-error`

const revealed = ref(false)

const isPassword = computed(() => props.type === 'password')
const canReveal = computed(() => isPassword.value && props.revealable && !props.disabled)

/** Swapping the type is what actually reveals the characters. */
const resolvedType = computed<FormInputType>(() =>
    isPassword.value && revealed.value ? 'text' : props.type,
)

/**
 * Only reference ids that are actually rendered — pointing at a missing element
 * makes some screen readers announce nothing at all.
 */
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

        <!-- `role="alert"` so the message is announced when it appears after a
             failed submit, not only when the field is next focused. -->
        <p v-if="error" :id="errorId" role="alert" class="mt-2 text-xs text-fail">
            {{ error }}
        </p>
    </div>
</template>
