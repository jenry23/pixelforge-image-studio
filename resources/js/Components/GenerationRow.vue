<script setup>
import { computed } from 'vue'
import MoneyFigure from './MoneyFigure.vue'
import StatusPill from './StatusPill.vue'

const props = defineProps({
    generation: { type: Object, required: true },
})

const isRunning = computed(() =>
    ['pending', 'queued', 'processing', 'unknown'].includes(props.generation.status),
)

const wasFree = computed(
    () =>
        props.generation.is_settled &&
        props.generation.actual_charge !== null &&
        props.generation.actual_charge.nanodollars === 0,
)

const tokenSummary = computed(() => {
    const usage = props.generation.usage
    if (!usage) return null

    return `${usage.total_tokens.toLocaleString()} tokens`
})
</script>

<template>
    <article class="grid gap-4 border-t border-line py-5 sm:grid-cols-[6rem_1fr_11rem]">
        <div
            class="flex aspect-square w-24 items-center justify-center overflow-hidden rounded border border-line bg-ink"
        >
            <img
                v-if="generation.image_url && !generation.image_expired"
                :src="generation.image_url"
                :alt="generation.prompt"
                class="size-full object-cover"
                loading="lazy"
            />
            <span v-else-if="generation.image_expired" class="px-2 text-center text-xs text-dim">
                Link expired
            </span>
            <span v-else-if="isRunning" class="px-2 text-center text-xs text-dim">
                Drawing
            </span>
            <span v-else class="px-2 text-center text-xs text-dim">No image</span>
        </div>

        <div class="min-w-0">
            <p class="line-clamp-2 text-sm leading-relaxed text-body">
                {{ generation.prompt }}
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <StatusPill :status="generation.status" :label="generation.status_label" />
                <span class="text-xs text-dim">
                    {{ generation.size }} · {{ generation.quality }}
                </span>
                <span v-if="tokenSummary" class="figure text-xs text-dim">
                    {{ tokenSummary }}
                </span>
            </div>

            <p v-if="generation.error_message" class="mt-2 text-xs leading-relaxed text-fail">
                {{ generation.error_message }}
            </p>
        </div>

        <div class="sm:text-right">
            <template v-if="wasFree">
                <div class="text-sm text-ok">Free</div>
                <div class="mt-0.5 text-xs text-dim">This one didn't work, so we didn't charge you</div>
            </template>

            <template v-else-if="generation.actual_charge">
                <div class="text-xs text-dim">Charged</div>
                <MoneyFigure
                    data-testid="actual-charge"
                    :value="generation.actual_charge.usd"
                    :exact="generation.actual_charge.exact"
                    certainty="measured"
                    size="lg"
                />
                <div class="mt-1 text-xs text-dim">
                    estimated
                    <span class="figure line-through">
                        ${{ generation.estimated_charge.expected }}
                    </span>
                </div>
                <div v-if="generation.provider_cost" class="mt-0.5 text-xs text-dim">
                    provider cost
                    <span class="figure">${{ generation.provider_cost.usd }}</span>
                </div>
            </template>

            <template v-else>
                <div class="text-xs text-dim">Estimated</div>
                <MoneyFigure
                    data-testid="estimated-charge"
                    :value="generation.estimated_charge.expected"
                    certainty="estimate"
                    size="lg"
                />
                <div class="figure mt-1 text-xs text-dim">
                    ${{ generation.estimated_charge.min }}–${{ generation.estimated_charge.max }}
                </div>
                <div v-if="isRunning" class="mt-1 text-xs text-estimate/80">
                    reserved until it finishes
                </div>
            </template>
        </div>
    </article>
</template>
