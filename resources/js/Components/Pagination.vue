<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import type { PaginationMeta } from '@/types/pagination'

interface Props {
    meta: PaginationMeta
    pageName?: string
    only?: string[]
    noun?: string
    nounPlural?: string
}

const props = withDefaults(defineProps<Props>(), {
    pageName: 'page',
    only: () => [],
    noun: 'row',
    nounPlural: 'rows',
})

const from = computed(() => props.meta.from ?? 0)
const to = computed(() => props.meta.to ?? 0)
const total = computed(() => props.meta.total)
const current = computed(() => props.meta.current_page)
const last = computed(() => props.meta.last_page)

const rangeLabel = computed(() => {
    if (total.value === 0) {
        return `No ${props.nounPlural}`
    }

    const noun = total.value === 1 ? props.noun : props.nounPlural

    return `Showing ${from.value}–${to.value} of ${total.value.toLocaleString()} ${noun}`
})

const pages = computed<number[]>(() => {
    const window = 2
    const start = Math.max(1, current.value - window)
    const end = Math.min(last.value, current.value + window)

    return Array.from({ length: end - start + 1 }, (_, i) => start + i)
})

function go(page: number): void {
    if (page < 1 || page > last.value || page === current.value) {
        return
    }

    router.get(
        window.location.pathname,
        { ...currentQuery(), [props.pageName]: page },
        {
            preserveState: true,
            preserveScroll: true,
            only: props.only.length ? props.only : undefined,
        },
    )
}

function currentQuery(): Record<string, string> {
    return Object.fromEntries(new URLSearchParams(window.location.search))
}
</script>

<template>
    <nav
        v-if="total > 0"
        class="flex flex-wrap items-center justify-between gap-4 px-5 py-4"
        :aria-label="`${nounPlural} pagination`"
    >
        <p class="text-sm text-[#c7d1e0]">{{ rangeLabel }}</p>

        <div v-if="last > 1" class="flex items-center gap-2">
            <button
                type="button"
                :disabled="current === 1"
                class="rounded-lg border border-outline px-3.5 py-2 text-sm text-white transition-colors hover:border-[#8a9ffd]/60 disabled:cursor-not-allowed disabled:text-[#5e758c] disabled:hover:border-outline"
                @click="go(current - 1)"
            >
                Previous
            </button>

            <button
                v-for="page in pages"
                :key="page"
                type="button"
                :aria-current="page === current ? 'page' : undefined"
                class="min-w-[2.5rem] rounded-lg border px-3 py-2 text-sm transition-colors"
                :class="
                    page === current
                        ? 'border-[#8a9ffd] bg-[#8a9ffd] font-semibold text-[#0b1020]'
                        : 'border-outline text-white hover:border-[#8a9ffd]'
                "
                @click="go(page)"
            >
                {{ page }}
            </button>

            <button
                type="button"
                :disabled="current === last"
                class="rounded-lg border border-outline px-3.5 py-2 text-sm text-white transition-colors hover:border-[#8a9ffd]/60 disabled:cursor-not-allowed disabled:text-[#5e758c] disabled:hover:border-outline"
                @click="go(current + 1)"
            >
                Next
            </button>
        </div>
    </nav>
</template>
