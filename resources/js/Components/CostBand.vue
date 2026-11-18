<script setup>
import { computed } from 'vue'
import MoneyFigure from './MoneyFigure.vue'

/**
 * The one bold element in this interface: the cost band.
 *
 * Output and reference-image tokens are known exactly before a generation runs,
 * but input text carries +/-15% variance and reasoning is only counted at
 * completion -- and for `high` quality the reasoning range alone spans $0.0875,
 * more than the rest of a typical generation. A single "estimated cost" would
 * therefore be a misleading number dressed up as a fact.
 *
 * So the uncertainty is drawn: a measured scale from the cheapest to the dearest
 * this generation could be, with a marker at the expected figure. When the
 * generation settles, the band is replaced by one exact number -- the band
 * collapsing to a point is the moment this product is about.
 */
const props = defineProps({
    min: { type: Object, required: true },
    expected: { type: Object, required: true },
    max: { type: Object, required: true },
})

/** Where the expected figure sits along the band, as a percentage. */
const markerPosition = computed(() => {
    const min = Number(props.min.nanodollars)
    const max = Number(props.max.nanodollars)
    const expected = Number(props.expected.nanodollars)

    if (max <= min) return 50

    return Math.min(97, Math.max(3, ((expected - min) / (max - min)) * 100))
})
</script>

<template>
    <div>
        <div class="flex items-baseline gap-2">
            <MoneyFigure
                :value="expected.usd.slice(0, 8)"
                :exact="expected.usd"
                certainty="estimate"
                size="xl"
            />
            <span class="text-sm text-muted">expected</span>
        </div>

        <!-- The band itself. Endpoints are hard limits, not decoration: the
             charge cannot fall outside them. -->
        <div class="mt-5">
            <div class="relative h-px bg-line-bright">
                <span class="absolute -top-1 left-0 h-2 w-px bg-line-bright" aria-hidden="true" />
                <span class="absolute -top-1 right-0 h-2 w-px bg-line-bright" aria-hidden="true" />
                <span
                    class="absolute -top-[3px] size-2 rounded-full bg-estimate ring-2 ring-panel"
                    :style="{ left: `calc(${markerPosition}% - 4px)` }"
                    aria-hidden="true"
                />
            </div>

            <div class="mt-2 flex justify-between">
                <MoneyFigure
                    :value="min.usd.slice(0, 8)"
                    :exact="min.usd"
                    certainty="muted"
                    size="sm"
                />
                <MoneyFigure
                    :value="max.usd.slice(0, 8)"
                    :exact="max.usd"
                    certainty="muted"
                    size="sm"
                />
            </div>
        </div>

        <p class="mt-3 text-xs leading-relaxed text-dim">
            The final charge lands somewhere in this range. We reserve the
            highest figure while it runs, then charge you what it actually cost.
        </p>
    </div>
</template>
