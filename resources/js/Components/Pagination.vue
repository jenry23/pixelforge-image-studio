<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Spinner from '@/Components/Icons/Spinner.vue'
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

const emit = defineEmits<{ loading: [boolean] }>()

const loadingPage = ref<number | null>(null)
const failedPage = ref<number | null>(null)
const arrived = ref(false)

let lastAttempt: number | null = null
let arrivedTimer: ReturnType<typeof setTimeout> | undefined

const isLoading = computed(() => loadingPage.value !== null)

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
    if (page < 1 || page > last.value || page === current.value || isLoading.value) {
        return
    }

    lastAttempt = page

    let settled = false

    router.get(
        window.location.pathname,
        { ...currentQuery(), [props.pageName]: page },
        {
            preserveState: true,
            preserveScroll: true,
            only: props.only.length ? props.only : undefined,
            onStart: () => {
                loadingPage.value = page
                failedPage.value = null
                emit('loading', true)
            },
            onSuccess: () => {
                settled = true
                flashArrival()
            },
            onCancel: () => {
                settled = true
            },
            onFinish: () => {
                loadingPage.value = null

                if (! settled) {
                    failedPage.value = page
                }

                emit('loading', false)
            },
        },
    )
}

function retry(): void {
    if (lastAttempt !== null) {
        go(lastAttempt)
    }
}

function flashArrival(): void {
    arrived.value = true
    clearTimeout(arrivedTimer)
    arrivedTimer = setTimeout(() => (arrived.value = false), 900)
}

function currentQuery(): Record<string, string> {
    return Object.fromEntries(new URLSearchParams(window.location.search))
}

onBeforeUnmount(() => clearTimeout(arrivedTimer))
</script>

<template>
    <nav
        v-if="total > 0"
        class="flex flex-wrap items-center justify-between gap-4 px-5 py-4"
        :aria-label="`${nounPlural} pagination`"
        :aria-busy="isLoading"
    >
        <p v-if="isLoading" class="flex items-center gap-2.5 text-sm text-[#c7d1e0]">
            <Spinner class="size-4 shrink-0 text-[#8a9ffd]" />
            Loading page {{ loadingPage }}…
        </p>

        <p v-else-if="failedPage !== null" class="flex items-center gap-3 text-sm text-fail">
            Couldn't load page {{ failedPage }}.
            <button
                type="button"
                class="rounded-lg border border-fail/60 px-3 py-1.5 text-sm text-fail transition-colors hover:border-fail"
                @click="retry"
            >
                Try again
            </button>
        </p>

        <p v-else class="text-sm text-[#c7d1e0]">{{ rangeLabel }}</p>

        <span class="sr-only" role="status">
            {{ isLoading ? `Loading page ${loadingPage} of ${last}` : '' }}
        </span>

        <div v-if="last > 1" class="flex items-center gap-2">
            <button
                type="button"
                :disabled="current === 1 || isLoading"
                class="rounded-lg border border-outline px-3.5 py-2 text-sm text-white transition-colors hover:border-[#8a9ffd]/60 disabled:cursor-not-allowed disabled:border-outline/40 disabled:text-[#5e758c]"
                @click="go(current - 1)"
            >
                Previous
            </button>

            <button
                v-for="page in pages"
                :key="page"
                type="button"
                :aria-current="page === current ? 'page' : undefined"
                :disabled="isLoading"
                class="min-w-[2.5rem] rounded-lg border px-3 py-2 text-sm transition-all disabled:cursor-not-allowed"
                :class="[
                    page === current
                        ? 'border-[#8a9ffd] bg-[#8a9ffd] font-semibold text-[#0b1020]'
                        : 'border-outline text-white hover:border-[#8a9ffd] disabled:border-outline/40 disabled:text-[#5e758c]',
                    page === current && arrived
                        ? 'ring-2 ring-[#8a9ffd]/70 ring-offset-2 ring-offset-surface'
                        : '',
                ]"
                @click="go(page)"
            >
                {{ page }}
            </button>

            <button
                type="button"
                :disabled="current === last || isLoading"
                class="rounded-lg border border-outline px-3.5 py-2 text-sm text-white transition-colors hover:border-[#8a9ffd]/60 disabled:cursor-not-allowed disabled:border-outline/40 disabled:text-[#5e758c]"
                @click="go(current + 1)"
            >
                Next
            </button>
        </div>
    </nav>
</template>
