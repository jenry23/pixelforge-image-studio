<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import ChevronDown from '@/Components/Icons/ChevronDown.vue'

export interface DropdownItem {
    label: string
    href: string
}

const props = withDefaults(
    defineProps<{
        label: string
        items: DropdownItem[]
        note?: string
        disabled?: boolean
    }>(),
    { note: undefined, disabled: false },
)

const uid = useId()
const open = ref(false)
const trigger = ref<HTMLButtonElement | null>(null)
const menu = ref<HTMLDivElement | null>(null)
const itemRefs = ref<HTMLAnchorElement[]>([])

function setItemRef(el: unknown, index: number): void {
    if (el instanceof HTMLAnchorElement) {
        itemRefs.value[index] = el
    }
}

function focusItem(index: number): void {
    const count = props.items.length
    const target = ((index % count) + count) % count

    nextTick(() => itemRefs.value[target]?.focus())
}

function toggle(): void {
    open.value ? close() : openMenu(0)
}

function openMenu(index: number): void {
    if (props.disabled) {
        return
    }

    open.value = true
    focusItem(index)
}

function close(returnFocus = true): void {
    if (! open.value) {
        return
    }

    open.value = false

    if (returnFocus) {
        nextTick(() => trigger.value?.focus())
    }
}

function onTriggerKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault()
        openMenu(0)
    } else if (event.key === 'ArrowUp') {
        event.preventDefault()
        openMenu(props.items.length - 1)
    }
}

function onItemKeydown(event: KeyboardEvent, index: number): void {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault()
            focusItem(index + 1)
            break
        case 'ArrowUp':
            event.preventDefault()
            focusItem(index - 1)
            break
        case 'Home':
            event.preventDefault()
            focusItem(0)
            break
        case 'End':
            event.preventDefault()
            focusItem(props.items.length - 1)
            break
        case 'Escape':
            event.preventDefault()
            close()
            break
        case 'Tab':
            close(false)
            break
    }
}

function onDocumentPointerDown(event: PointerEvent): void {
    const target = event.target as Node

    if (! menu.value?.contains(target) && ! trigger.value?.contains(target)) {
        close(false)
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('pointerdown', onDocumentPointerDown)
    } else {
        document.removeEventListener('pointerdown', onDocumentPointerDown)
    }
})

watch(
    () => props.disabled,
    (isDisabled) => isDisabled && close(false),
)

onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerDown))
</script>

<template>
    <div class="relative">
        <button
            :id="`${uid}-trigger`"
            ref="trigger"
            type="button"
            :disabled="disabled"
            :aria-expanded="open"
            :aria-controls="`${uid}-menu`"
            aria-haspopup="menu"
            class="inline-flex items-center gap-2.5 rounded-lg border border-[#8a9ffd] px-4 py-2 text-sm font-medium text-[#8a9ffd] transition-colors hover:bg-[#8a9ffd]/10 disabled:cursor-not-allowed disabled:border-outline/40 disabled:text-[#5e758c] disabled:hover:bg-transparent"
            @click="toggle"
            @keydown="onTriggerKeydown"
        >
            <slot name="icon" />
            {{ label }}
            <ChevronDown class="size-4 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''" />
        </button>

        <div
            v-if="open"
            :id="`${uid}-menu`"
            ref="menu"
            role="menu"
            :aria-labelledby="`${uid}-trigger`"
            class="absolute right-0 z-20 mt-2 min-w-[15rem] overflow-hidden rounded-xl border border-outline bg-surface shadow-xl shadow-black/40"
        >
            <a
                v-for="(item, index) in items"
                :key="item.href"
                :ref="(el) => setItemRef(el, index)"
                :href="item.href"
                role="menuitem"
                download
                class="block px-4 py-2.5 text-sm text-white transition-colors hover:bg-[#8a9ffd]/15 focus-visible:bg-[#8a9ffd]/15"
                @click="close(false)"
                @keydown="onItemKeydown($event, index)"
            >
                {{ item.label }}
            </a>

            <p v-if="note" class="border-t border-divider-soft px-4 py-2.5 text-xs text-[#9aa5b4]">
                {{ note }}
            </p>
        </div>
    </div>
</template>
