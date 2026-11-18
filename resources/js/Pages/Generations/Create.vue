<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import Form from '@/Components/Form/Form.vue'
import FormTextarea from '@/Components/Form/FormTextarea.vue'
import RadioGroup, { type RadioOption } from '@/Components/Form/RadioGroup.vue'
import ReferenceImageInput from '@/Components/ReferenceImageInput.vue'
import StudioLayout from '@/Layouts/StudioLayout.vue'
import type { BalanceSummary, CostEstimate } from '@/types/generations'

interface Props {
    sizes: RadioOption[]
    qualities: RadioOption[]
    limits: { prompt_max_characters: number; reference_images_max: number }
    markupPercent: number
    initialEstimate: CostEstimate
    balance: BalanceSummary
}

interface GenerationForm {
    prompt: string
    size: string
    quality: string
    reference_images: string[]
}

defineOptions({ layout: StudioLayout })

const props = defineProps<Props>()

const form = useForm<GenerationForm>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
})

const estimate = ref<CostEstimate>(props.initialEstimate)
const quoting = ref(false)

let quoteTimer: ReturnType<typeof setTimeout> | undefined

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

                const response = await window.fetch(`${route('generations.estimate')}?${query}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                })

                if (response.ok) {
                    estimate.value = ((await response.json()) as { estimate: CostEstimate }).estimate
                }
            } finally {
                quoting.value = false
            }
        }, 300)
    },
)

const canAfford = computed(
    () =>
        Number(props.balance.available_nanodollars) >=
        Number(estimate.value.charge.max.nanodollars),
)

const submittable = computed(
    () => form.prompt.trim().length > 0 && canAfford.value && !form.processing,
)

const creditsError = computed(
    () => (form.errors as Record<string, string | undefined>).credits,
)

const markerPosition = computed(() => {
    const min = Number(estimate.value.charge.min.nanodollars)
    const max = Number(estimate.value.charge.max.nanodollars)
    const expected = Number(estimate.value.charge.expected.nanodollars)

    if (max <= min) return 50

    return Math.min(97, Math.max(3, ((expected - min) / (max - min)) * 100))
})

function tokensFor(row: CostEstimate['breakdown'][number]): string {
    return row.exact
        ? row.tokens_expected.toLocaleString()
        : `${row.tokens_min.toLocaleString()}–${row.tokens_max.toLocaleString()}`
}

function submit(): void {
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

    <div class="flex flex-wrap items-center justify-between gap-x-10 gap-y-5">
        <h1 class="text-[2.2rem] font-bold tracking-tight text-white sm:text-[2.6rem]">
            Generate image
        </h1>

        <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
            <div>
                <div class="text-sm text-[#9aa5b4]">Available to spend</div>
                <div class="figure text-[1.75rem] text-white">
                    ${{ props.balance.available }}
                </div>
            </div>

            <div class="hidden h-12 w-px bg-[#262d39] sm:block" />

            <div class="flex items-center gap-3">
                <span class="text-sm text-[#9aa5b4]">Buy credits</span>
                <form
                    v-for="pack in ['1.00', '5.00', '20.00']"
                    :key="pack"
                    :action="route('credits.store')"
                    method="post"
                    @submit.prevent="
                        $inertia.post(route('credits.store'), { amount_usd: pack }, { preserveScroll: true })
                    "
                >
                    <button
                        type="submit"
                        class="figure rounded-lg border border-[#262d39] bg-[#101723] px-3.5 py-2 text-sm text-white transition-colors hover:border-[#8a9ffd]/60"
                    >
                        ${{ pack }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_25rem]">
        <section class="rounded-2xl border border-[#262d39] bg-[#0f1621] p-6 sm:p-7">
            <Form :processing="form.processing" @submit="submit">
                <FormTextarea
                    v-model="form.prompt"
                    name="prompt"
                    label="Prompt"
                    placeholder="A brass astrolabe resting on dark blue velvet, lit from one side"
                    :rows="5"
                    :maxlength="props.limits.prompt_max_characters"
                    show-count
                    hint="Longer prompts cost a little more. Around 4 characters per token."
                    :error="form.errors.prompt"
                />

                <div class="grid gap-6 sm:grid-cols-2">
                    <RadioGroup
                        v-model="form.size"
                        legend="Size"
                        name="size"
                        :options="props.sizes"
                    />
                    <RadioGroup
                        v-model="form.quality"
                        legend="Quality"
                        name="quality"
                        :options="props.qualities"
                    />
                </div>

                <ReferenceImageInput
                    v-model="form.reference_images"
                    :max="props.limits.reference_images_max"
                    :errors="form.errors"
                />

                <template #actions>
                    <div class="mt-7 border-t border-[#262d39] pt-7">
                        <button
                            type="submit"
                            :disabled="!submittable"
                            class="h-[3.5rem] w-full rounded-xl bg-[#8a9ffd] text-[1.05rem] font-bold text-[#0b1020] transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0e1522] disabled:cursor-not-allowed disabled:opacity-45"
                        >
                            {{ form.processing ? 'Sending to Pixelforge…' : 'Generate image' }}
                        </button>

                        <p v-if="creditsError" class="mt-3 text-sm text-fail">
                            {{ creditsError }}
                        </p>
                        <p v-else-if="!canAfford" class="mt-3 text-sm text-estimate">
                            You need a little more credit to cover this one. Buy credits above, or
                            choose a lower quality.
                        </p>
                    </div>
                </template>
            </Form>
        </section>

        <aside class="rounded-2xl border border-[#262d39] bg-[#0f1621] p-6 sm:p-7">
            <div class="flex items-baseline justify-between">
                <h2 class="text-[1.15rem] font-semibold text-white">Estimated cost</h2>
                <span
                    class="text-xs text-[#9aa5b4] transition-opacity"
                    :class="quoting ? 'opacity-100' : 'opacity-0'"
                >
                    pricing
                </span>
            </div>

            <div class="mt-4 flex flex-wrap items-baseline gap-x-3">
                <span class="figure text-[2.1rem] text-white">
                    ${{ estimate.charge.expected.usd.slice(0, 8) }}
                </span>
                <span class="text-sm text-[#9aa5b4]">Expected charge</span>
            </div>

            <div class="mt-5">
                <div class="relative h-px bg-[#2f3a4a]">
                    <span class="absolute -top-1 left-0 h-2 w-px bg-[#2f3a4a]" aria-hidden="true" />
                    <span class="absolute -top-1 right-0 h-2 w-px bg-[#2f3a4a]" aria-hidden="true" />
                    <span
                        class="absolute -top-[3px] size-2 rounded-full bg-[#8a9ffd] ring-2 ring-[#0f1621]"
                        :style="{ left: `calc(${markerPosition}% - 4px)` }"
                        aria-hidden="true"
                    />
                </div>
                <div class="mt-2.5 flex justify-between">
                    <span class="figure text-xs text-[#9aa5b4]">
                        ${{ estimate.charge.min.usd.slice(0, 8) }}
                    </span>
                    <span class="figure text-xs text-[#9aa5b4]">
                        ${{ estimate.charge.max.usd.slice(0, 8) }}
                    </span>
                </div>
            </div>

            <p class="mt-4 text-sm leading-relaxed text-[#9aa5b4]">
                We reserve the upper estimate and settle the actual cost.
            </p>

            <table class="mt-6 w-full border-t border-[#262d39] text-sm">
                <thead>
                    <tr class="text-xs text-[#9aa5b4]">
                        <th scope="col" class="py-3 text-left font-medium">Component</th>
                        <th scope="col" class="py-3 text-right font-medium">Tokens</th>
                        <th scope="col" class="py-3 text-right font-medium">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in estimate.breakdown"
                        :key="row.type"
                        class="border-t border-[#262d39]/70"
                    >
                        <th scope="row" class="py-3 pr-3 text-left font-normal text-white">
                            {{ row.label }}
                        </th>
                        <td class="figure py-3 text-right text-[#c3ccd8]">{{ tokensFor(row) }}</td>
                        <td class="figure py-3 pl-3 text-right text-white">
                            ${{ row.cost_expected.usd.slice(0, 8) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <dl class="mt-5 space-y-2 border-t border-[#262d39] pt-5 text-sm">
                <div class="flex justify-between">
                    <dt class="text-[#c3ccd8]">Provider cost</dt>
                    <dd class="figure text-white">
                        ${{ estimate.provider.expected.usd.slice(0, 8) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-[#c3ccd8]">Markup</dt>
                    <dd class="figure text-white">{{ props.markupPercent }}%</dd>
                </div>
            </dl>

            <p class="mt-5 text-sm text-[#9aa5b4]">Failed generations are free.</p>
        </aside>
    </div>
</template>
