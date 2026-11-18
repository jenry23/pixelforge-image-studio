<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import GenerationModal from '@/Components/GenerationModal.vue'
import Pagination from '@/Components/Pagination.vue'
import StatusPill from '@/Components/StatusPill.vue'
import StudioLayout from '@/Layouts/StudioLayout.vue'
import { useAdaptivePolling } from '@/Composables/useAdaptivePolling'
import type { BalanceSummary, GenerationRow } from '@/types/generations'
import type { PaginationMeta } from '@/types/pagination'

interface Props {
    generations: { data: GenerationRow[]; meta: PaginationMeta }
    balance: BalanceSummary
    hasPending: boolean
    creditPacks: string[]
}

defineOptions({ layout: StudioLayout })

const props = defineProps<Props>()

const rows = computed(() => props.generations.data)

const { polling } = useAdaptivePolling({
    only: ['generations', 'balance', 'hasPending'],
    isActive: () => props.hasPending,
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

    <div class="flex flex-wrap items-end justify-between gap-x-10 gap-y-6">
        <div>
            <h1 class="text-[2.2rem] font-bold tracking-tight text-white sm:text-[2.6rem]">
                Generation history
            </h1>
            <p class="mt-2 text-[1.05rem] text-[#c3ccd8]">
                Your past image generations, costs, and status.
            </p>

            <div class="mt-6">
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
                class="figure rounded-lg border border-[#262d39] bg-[#101723] px-3.5 py-2 text-sm text-white transition-colors hover:border-[#8a9ffd]/60"
                @click="buyCredits(pack)"
            >
                ${{ pack }}
            </button>
        </div>
    </div>

    <div class="mt-7 flex items-center justify-end">
        <span v-if="polling" class="flex items-center gap-1.5 text-xs text-measured">
            <span class="size-1.5 animate-pulse rounded-full bg-current" aria-hidden="true" />
            Watching for updates
        </span>
        <span v-else-if="rows.length" class="text-xs text-[#5e758c]">Up to date</span>
    </div>

    <div class="mt-3 overflow-hidden rounded-2xl border border-[#262d39] bg-[#0f1621]">
        <div v-if="rows.length" class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead>
                    <tr class="border-b border-[#262d39] text-[#9aa5b4]">
                        <th scope="col" class="px-5 py-4 text-left font-medium">Image</th>
                        <th scope="col" class="px-3 py-4 text-left font-medium">Prompt</th>
                        <th scope="col" class="px-3 py-4 text-left font-medium">Status</th>
                        <th scope="col" class="px-3 py-4 text-left font-medium">Dimensions</th>
                        <th scope="col" class="px-3 py-4 text-left font-medium">Quality</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Tokens</th>
                        <th scope="col" class="px-5 py-4 text-right font-medium">Charged</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="generation in rows"
                        :key="generation.id"
                        class="border-b border-[#262d39]/70 last:border-b-0"
                    >
                        <td class="px-5 py-4 align-top">
                            <button
                                v-if="hasImage(generation)"
                                type="button"
                                class="block size-[4.5rem] overflow-hidden rounded-lg border border-[#2b3647] transition-colors hover:border-[#8a9ffd] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd]"
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
                                v-else
                                class="flex size-[4.5rem] items-center justify-center rounded-lg border border-[#2b3647] bg-[#0b111b] px-1 text-center text-[0.7rem] leading-tight text-[#5e758c]"
                            >
                                {{ placeholderLabel(generation) }}
                            </div>
                        </td>

                        <td class="max-w-[22rem] px-3 py-4 align-top">
                            <p class="leading-relaxed text-white">{{ generation.prompt }}</p>
                            <p v-if="generation.error_message" class="mt-1.5 leading-relaxed text-fail">
                                {{ generation.error_message }}
                            </p>
                        </td>

                        <td class="px-3 py-4 align-top">
                            <StatusPill :status="generation.status" :label="generation.status_label" />
                        </td>

                        <td class="figure px-3 py-4 align-top text-[#c3ccd8]">
                            {{ generation.dimensions }}
                        </td>

                        <td class="px-3 py-4 align-top text-[#c3ccd8]">{{ generation.quality }}</td>

                        <td class="figure px-3 py-4 text-right align-top text-[#c3ccd8]">
                            {{ generation.total_tokens ? generation.total_tokens.toLocaleString() : '—' }}
                        </td>

                        <td class="px-5 py-4 text-right align-top">
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
                                <div class="text-xs text-[#9aa5b4]">Estimated</div>
                                <div class="figure text-[1.35rem] text-estimate">
                                    ${{ generation.estimated_charge.expected }}
                                </div>
                                <div class="mt-1 text-xs text-estimate/80">reserved until it finishes</div>
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

        <div v-if="rows.length" class="border-t border-[#262d39]">
            <Pagination
                :meta="props.generations.meta"
                page-name="page"
                :only="['generations', 'balance', 'hasPending']"
                noun="generation"
                noun-plural="generations"
            />
        </div>
    </div>

    <GenerationModal :generation="selected" @close="close" />
</template>
