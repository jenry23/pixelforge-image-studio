<script setup>
import { computed, ref, watch } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import StudioLayout from '@/Layouts/StudioLayout.vue'
import BalanceBar from '@/Components/BalanceBar.vue'
import CostBand from '@/Components/CostBand.vue'
import CostBreakdown from '@/Components/CostBreakdown.vue'
import OptionToggle from '@/Components/OptionToggle.vue'
import ReferenceImageInput from '@/Components/ReferenceImageInput.vue'

const props = defineProps({
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
    limits: { type: Object, required: true },
    markupPercent: { type: Number, default: 25 },
    initialEstimate: { type: Object, required: true },
    balance: { type: Object, required: true },
})

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
})

const estimate = ref(props.initialEstimate)
const quoting = ref(false)

/**
 * The quote comes from the server, debounced, rather than being recomputed in
 * JavaScript.
 *
 * Reimplementing the pricing maths here would mean two implementations of the
 * one calculation this product exists to get right, and they would drift. One
 * tested implementation in PHP is worth a 300ms round trip for a number the
 * user is about to be charged.
 */
let quoteTimer = null

watch(
    () => [form.prompt, form.size, form.quality, form.reference_images.length],
    () => {
        clearTimeout(quoteTimer)
        quoting.value = true

        quoteTimer = setTimeout(async () => {
            try {
                const query = new URLSearchParams({
                    prompt: form.prompt,
                    size: form.size,
                    quality: form.quality,
                    reference_image_count: String(
                        form.reference_images.filter((url) => url.trim() !== '').length,
                    ),
                })

                const response = await window.fetch(
                    `${route('generations.estimate')}?${query}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                )

                if (response.ok) {
                    estimate.value = (await response.json()).estimate
                }
            } finally {
                quoting.value = false
            }
        }, 300)
    },
)

const promptLength = computed(() => form.prompt.length)

/** We reserve the maximum, so that is the figure affordability turns on. */
const canAfford = computed(
    () => Number(props.balance.available_nanodollars) >= Number(estimate.value.charge.max.nanodollars),
)

const submittable = computed(
    () => form.prompt.trim().length > 0 && canAfford.value && !form.processing,
)

function submit() {
    form
        .transform((data) => ({
            ...data,
            reference_images: data.reference_images.filter((url) => url.trim() !== ''),
        }))
        .post(route('generations.store'), { preserveScroll: true })
}
</script>

<template>
    <Head title="Generate" />

    <StudioLayout>
        <BalanceBar :balance="props.balance" class="mb-8" />

        <form class="grid gap-8 lg:grid-cols-[1fr_22rem]" @submit.prevent="submit">
            <!-- Controls -->
            <div class="space-y-6">
                <div>
                    <label for="prompt" class="text-sm text-muted">
                        What should Pixelforge draw?
                    </label>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        :maxlength="props.limits.prompt_max_characters"
                        placeholder="A brass astrolabe resting on dark blue velvet, lit from one side"
                        class="field mt-2 w-full resize-y px-3 py-2.5 text-sm leading-relaxed"
                    />
                    <div class="mt-1.5 flex justify-between text-xs">
                        <span v-if="form.errors.prompt" class="text-fail">
                            {{ form.errors.prompt }}
                        </span>
                        <span v-else class="text-dim">
                            Longer prompts cost a little more. Around 4 characters per token.
                        </span>
                        <span class="figure text-dim">
                            {{ promptLength }} / {{ props.limits.prompt_max_characters }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <OptionToggle
                        v-model="form.size"
                        name="size"
                        label="Size"
                        :options="props.sizes"
                    />
                    <OptionToggle
                        v-model="form.quality"
                        name="quality"
                        label="Quality"
                        :options="props.qualities"
                    />
                </div>

                <ReferenceImageInput
                    v-model="form.reference_images"
                    :max="props.limits.reference_images_max"
                    :errors="form.errors"
                />

                <div class="border-t border-line pt-6">
                    <button
                        type="submit"
                        :disabled="!submittable"
                        class="rounded-md bg-measured px-5 py-2.5 text-sm font-medium text-ink transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {{ form.processing ? 'Sending to Pixelforge' : 'Generate image' }}
                    </button>

                    <p v-if="form.errors.credits" class="mt-3 text-sm text-fail">
                        {{ form.errors.credits }}
                    </p>
                    <p v-else-if="!canAfford" class="mt-3 text-sm text-estimate">
                        You need a little more credit to cover this one. Buy credits above,
                        or choose a lower quality.
                    </p>
                </div>
            </div>

            <!-- Readout -->
            <aside class="panel self-start rounded-lg p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-medium text-body">What this will cost</h2>
                    <span
                        class="text-xs text-dim transition-opacity"
                        :class="quoting ? 'opacity-100' : 'opacity-0'"
                    >
                        pricing
                    </span>
                </div>

                <div class="mt-4">
                    <CostBand
                        :min="estimate.charge.min"
                        :expected="estimate.charge.expected"
                        :max="estimate.charge.max"
                    />
                </div>

                <div class="mt-6 border-t border-line pt-4">
                    <CostBreakdown :breakdown="estimate.breakdown" />
                </div>

                <dl class="mt-4 space-y-1.5 border-t border-line pt-4 text-xs">
                    <div class="flex justify-between">
                        <dt class="text-dim">Pixelforge charges us</dt>
                        <dd class="figure text-muted">
                            ${{ estimate.provider.expected.usd.slice(0, 8) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-dim">Our margin</dt>
                        <dd class="figure text-muted">{{ props.markupPercent }}%</dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs leading-relaxed text-dim">
                    If a generation fails, it costs you nothing.
                </p>
            </aside>
        </form>
    </StudioLayout>
</template>
