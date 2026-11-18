<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import StatusPill from '@/Components/StatusPill.vue'
import type { GenerationRow } from '@/types/generations'

interface Props {
    generation: GenerationRow | null
}

const props = defineProps<Props>()
const emit = defineEmits<{ close: [] }>()

const dialog = ref<HTMLDialogElement | null>(null)

watch(
    () => props.generation,
    (generation) => {
        const el = dialog.value
        if (!el) return

        if (generation && !el.open) {
            el.showModal()
        } else if (!generation && el.open) {
            el.close()
        }
    },
    { flush: 'post' },
)

const dimensions = computed(() => props.generation?.dimensions ?? '')
const usage = computed(() => props.generation?.usage ?? null)
</script>

<template>
    <dialog
        ref="dialog"
        aria-labelledby="generation-details-title"
        class="m-auto w-[min(62rem,92vw)] rounded-2xl border border-[#2b3647] bg-[#111a28] p-0 text-left backdrop:bg-black/70"
        @close="emit('close')"
        @cancel.prevent="emit('close')"
    >
        <div v-if="generation" class="p-7 sm:p-9">
            <div class="flex items-start justify-between gap-6">
                <h2 id="generation-details-title" class="text-[1.9rem] font-bold text-white">
                    Generation details
                </h2>
                <button
                    type="button"
                    aria-label="Close"
                    class="rounded-lg p-1.5 text-[#c3ccd8] transition-colors hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd]"
                    @click="emit('close')"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-6" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>

            <div class="mt-7 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="overflow-hidden rounded-xl border border-[#2b3647] bg-[#0b111b]">
                    <img
                        v-if="generation.image_url && !generation.image_expired"
                        :src="generation.image_url"
                        :alt="generation.prompt"
                        class="h-auto w-full object-contain"
                    />
                    <div v-else class="flex aspect-square items-center justify-center text-sm text-[#5e758c]">
                        {{ generation.image_expired ? 'Link expired' : 'No image' }}
                    </div>
                </div>

                <div>
                    <StatusPill :status="generation.status" :label="generation.status_label" />

                    <h3 class="mt-6 text-sm font-semibold text-[#9aa5b4]">Prompt</h3>
                    <p class="mt-2 text-[1.15rem] leading-relaxed text-white">
                        {{ generation.prompt }}
                    </p>

                    <p v-if="generation.error_message" class="mt-3 text-sm leading-relaxed text-fail">
                        {{ generation.error_message }}
                    </p>

                    <dl class="mt-6 grid grid-cols-3 divide-x divide-[#2b3647] border-y border-[#2b3647] py-4 text-center">
                        <div class="px-2">
                            <dt class="text-xs text-[#9aa5b4]">Resolution</dt>
                            <dd class="figure mt-1 text-white">{{ dimensions }}</dd>
                        </div>
                        <div class="px-2">
                            <dt class="text-xs text-[#9aa5b4]">Quality</dt>
                            <dd class="mt-1 capitalize text-white">{{ generation.quality }}</dd>
                        </div>
                        <div class="px-2">
                            <dt class="text-xs text-[#9aa5b4]">Tokens</dt>
                            <dd class="figure mt-1 text-white">
                                {{ usage ? usage.total_tokens.toLocaleString() : '—' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6">
                        <template v-if="generation.actual_charge && generation.actual_charge.nanodollars > 0">
                            <div class="text-sm text-[#9aa5b4]">Charged</div>
                            <div class="figure mt-1 text-[2rem] text-measured">
                                ${{ generation.actual_charge.usd }}
                            </div>
                            <p class="mt-2 text-sm text-[#c3ccd8]">
                                Estimated ${{ generation.estimated_charge.expected }}
                            </p>
                            <p v-if="generation.provider_cost" class="text-sm text-[#c3ccd8]">
                                Provider cost ${{ generation.provider_cost.usd }}
                            </p>
                        </template>

                        <template v-else-if="generation.is_settled">
                            <div class="text-[1.4rem] font-semibold text-ok">No charge</div>
                            <p class="mt-1 text-sm text-[#9aa5b4]">
                                This one didn't work, so we didn't charge you.
                            </p>
                        </template>

                        <template v-else>
                            <div class="text-sm text-[#9aa5b4]">Estimated</div>
                            <div class="figure mt-1 text-[2rem] text-estimate">
                                ${{ generation.estimated_charge.expected }}
                            </div>
                            <p class="mt-2 text-sm text-[#9aa5b4]">
                                Reserved until this finishes.
                            </p>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </dialog>
</template>
