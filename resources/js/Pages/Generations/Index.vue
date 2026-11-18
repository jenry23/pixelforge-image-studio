<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import GenerationModal from '@/Components/GenerationModal.vue'
import Pagination from '@/Components/Pagination.vue'
import TableSkeleton from '@/Components/TableSkeleton.vue'
import ImagePlaceholder from '@/Components/Icons/ImagePlaceholder.vue'
import StatusPill from '@/Components/StatusPill.vue'
import StudioLayout from '@/Layouts/StudioLayout.vue'
import { useAdaptivePolling } from '@/Composables/useAdaptivePolling'
import { useSteadyColumns } from '@/Composables/useSteadyColumns'
import type { BalanceSummary, GenerationRow } from '@/types/generations'
import type { PaginationMeta } from '@/types/pagination'

interface Props {
    generations: { data: GenerationRow[]; meta: PaginationMeta }
    balance: BalanceSummary
    hasPending: boolean
    creditPacks: string[]
}

defineOptions({ layout: StudioLayout })

const paging = ref(false)

const historyTable = ref<HTMLTableElement | null>(null)
const historyColumns = useSteadyColumns(historyTable)

function onPaging(loading: boolean): void {
    historyColumns.onLoading(loading)
    paging.value = loading
}

const historySkeleton = [
    {
        cellClass: 'px-5 py-2',
        boxClass: 'h-[7.2rem]',
        pillClass: 'size-[7.2rem] rounded-xl',
    },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[27rem]' },
    { cellClass: 'px-3 py-2', pillClass: 'h-[1.6rem] rounded-full w-[6.25rem]' },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[6rem]' },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[4.4rem]' },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[6rem]' },
    { cellClass: 'px-5 py-2', pillClass: 'h-2.5 rounded-full w-[9.25rem]', align: 'right' as const },
]

const props = defineProps<Props>()

const rows = computed(() => props.generations.data)

const { polling } = useAdaptivePolling({
    only: ['generations', 'balance', 'hasPending'],
    isActive: () => props.hasPending && ! paging.value,
})

const selected = ref<GenerationRow | null>(null)
const lastTrigger = ref<HTMLElement | null>(null)

function open(generation: GenerationRow, event: MouseEvent): void {
    lastTrigger.value = event.currentTarget as HTMLElement
    selected.value = generation
}

async function close(): Promise<void> {
    selected.value = null
    await nextTick()
    lastTrigger.value?.focus()
}

function hasImage(generation: GenerationRow): boolean {
    return Boolean(generation.image_url) && !generation.image_expired
}

function isRunning(generation: GenerationRow): boolean {
    return ['pending', 'queued', 'processing'].includes(generation.status)
}

function placeholderLabel(generation: GenerationRow): string {
    if (generation.image_expired) return 'Link expired'

    return generation.is_settled ? 'No image' : 'Drawing'
}

function buyCredits(amount: string): void {
    router.post(route('credits.store'), { amount_usd: amount }, { preserveScroll: true })
}
</script>

<template>
    <Head title="History" />

    <div class="flex flex-wrap items-end justify-between gap-x-10 gap-y-4">
        <div>
            <h1 class="text-[2.2rem] font-bold tracking-tight text-white sm:text-[2.6rem]">
                Generation history
            </h1>
            <p class="mt-2 text-[1.05rem] text-[#c3ccd8]">
                Your past image generations, costs, and status.
            </p>

            <div class="mt-3">
                <div class="text-sm text-[#9aa5b4]">Available to spend</div>
                <div class="figure text-[1.75rem] text-measured">${{ props.balance.available }}</div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <span class="text-sm text-[#9aa5b4]">Buy credits</span>
            <button
                v-for="pack in props.creditPacks"
                :key="pack"
                type="button"
                class="figure rounded-lg border border-outline bg-[#101723] px-3.5 py-2 text-sm text-white transition-colors hover:border-[#8a9ffd]/60"
                @click="buyCredits(pack)"
            >
                ${{ pack }}
            </button>
        </div>
    </div>

    <div class="sr-only">
        <span v-if="polling" class="flex items-center gap-1.5 text-xs text-measured">
            <span class="size-1.5 animate-pulse rounded-full bg-current" aria-hidden="true" />
            Watching for updates
        </span>
        <span v-else-if="rows.length" class="text-xs text-[#5e758c]">Up to date</span>
    </div>

    <div class="mt-4 overflow-hidden rounded-2xl border border-outline bg-surface">
        <div v-if="rows.length" class="overflow-x-auto">
            <table
                ref="historyTable"
                class="w-full min-w-[56rem] text-sm"
                :style="historyColumns.widths.value.length ? { tableLayout: 'fixed' } : undefined"
            >
                <colgroup v-if="historyColumns.widths.value.length">
                    <col
                        v-for="(width, index) in historyColumns.widths.value"
                        :key="index"
                        :style="{ width: `${width}px` }"
                    />
                </colgroup>
                <thead>
                    <tr class="border-b border-outline text-white">
                        <th scope="col" class="px-5 py-3.5 text-left font-medium">Image</th>
                        <th scope="col" class="px-3 py-3.5 text-left font-medium">Prompt</th>
                        <th scope="col" class="px-3 py-3.5 text-left font-medium">Status</th>
                        <th scope="col" class="px-3 py-3.5 text-left font-medium">Dimensions</th>
                        <th scope="col" class="px-3 py-3.5 text-left font-medium">Quality</th>
                        <th scope="col" class="px-3 py-3.5 text-right font-medium">Tokens</th>
                        <th scope="col" class="px-5 py-3.5 text-right font-medium">Charged</th>
                    </tr>
                </thead>
                <TableSkeleton
                    v-if="paging"
                    :rows="rows.length"
                    :columns="historySkeleton"
                    row-class="border-b border-divider-soft last:border-b-0"
                />

                <tbody v-else>
                    <tr
                        v-for="generation in rows"
                        :key="generation.id"
                        class="border-b border-divider-soft last:border-b-0"
                    >
                        <td class="px-5 py-2 align-middle">
                            <button
                                v-if="hasImage(generation)"
                                type="button"
                                class="block size-[7.2rem] overflow-hidden rounded-xl border border-outline transition-colors hover:border-[#8a9ffd] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd]"
                                :aria-label="`View details for: ${generation.prompt}`"
                                @click="open(generation, $event)"
                            >
                                <img
                                    :src="generation.image_url ?? ''"
                                    :alt="generation.prompt"
                                    class="size-full object-cover"
                                    loading="lazy"
                                />
                            </button>

                            <div
                                v-else-if="isRunning(generation)"
                                class="flex size-[7.2rem] items-center justify-center rounded-xl border border-outline bg-[#0b111b] text-[#56637a]"
                                :aria-label="placeholderLabel(generation)"
                                role="img"
                            >
                                <ImagePlaceholder class="size-9" />
                            </div>

                            <div
                                v-else
                                class="flex size-[7.2rem] items-center justify-center rounded-xl border border-outline bg-[#0b111b] px-2 text-center text-[0.85rem] leading-tight text-[#8e9bb0]"
                            >
                                {{ placeholderLabel(generation) }}
                            </div>
                        </td>

                        <td class="max-w-[26rem] px-3 py-2 align-middle">
                            <p class="leading-relaxed text-white">{{ generation.prompt }}</p>
                            <p v-if="generation.error_message" class="mt-1.5 leading-relaxed text-fail">
                                {{ generation.error_message }}
                            </p>
                        </td>

                        <td class="px-3 py-2 align-middle">
                            <StatusPill :status="generation.status" :label="generation.status_label" />
                        </td>

                        <td class="figure px-3 py-2 align-middle text-[#c3ccd8]">
                            {{ generation.dimensions }}
                        </td>

                        <td class="px-3 py-2 align-middle text-[#c3ccd8]">{{ generation.quality }}</td>

                        <td class="figure px-3 py-2 text-right align-middle text-[#c3ccd8]">
                            {{ generation.total_tokens ? generation.total_tokens.toLocaleString() : '—' }}
                        </td>

                        <td class="px-5 py-2 text-right align-middle">
                            <template v-if="generation.actual_charge && generation.actual_charge.nanodollars > 0">
                                <div class="text-xs text-[#9aa5b4]">Charged</div>
                                <div class="figure text-[1.35rem] text-measured">
                                    ${{ generation.actual_charge.usd }}
                                </div>
                                <div class="mt-1 text-xs text-[#9aa5b4]">
                                    estimated
                                    <span class="figure line-through">
                                        ${{ generation.estimated_charge.expected }}
                                    </span>
                                </div>
                                <div v-if="generation.provider_cost" class="text-xs text-[#9aa5b4]">
                                    provider cost
                                    <span class="figure">${{ generation.provider_cost.usd }}</span>
                                </div>
                            </template>

                            <template v-else-if="generation.is_settled">
                                <div class="text-[1.15rem] font-semibold text-ok">No charge</div>
                                <p class="mt-1 text-xs leading-relaxed text-[#9aa5b4]">
                                    This one didn't work, so we didn't charge you.
                                </p>
                            </template>

                            <template v-else>
                                <div class="text-[0.95rem] text-[#c3ccd8]">Awaiting final charge</div>
                                <div class="mt-0.5 text-xs text-[#9aa5b4]">
                                    Estimated
                                    <span class="figure text-estimate">
                                        ${{ generation.estimated_charge.expected }}
                                    </span>
                                </div>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="px-6 py-16 text-center">
            <p class="text-white">Nothing generated yet.</p>
            <p class="mx-auto mt-2 max-w-sm leading-relaxed text-[#9aa5b4]">
                Write a prompt and you'll see the cost before you commit to it.
            </p>
        </div>

        <div v-if="rows.length" class="border-t border-outline">
            <Pagination
                :meta="props.generations.meta"
                page-name="page"
                :only="['generations', 'balance', 'hasPending']"
                noun="generation"
                noun-plural="generations"
                @loading="onPaging"
            />
        </div>
    </div>

    <GenerationModal :generation="selected" @close="close" />
</template>
