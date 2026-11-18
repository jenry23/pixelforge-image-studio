<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import MoneyFigure from './MoneyFigure.vue'

const props = defineProps({
    balance: { type: Object, required: true },
})

const packs = ['1.00', '5.00', '20.00']
const buying = ref(null)

function buy(amount) {
    buying.value = amount

    router.post(
        route('credits.store'),
        { amount_usd: amount },
        {
            preserveScroll: true,
            onFinish: () => (buying.value = null),
        },
    )
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <div>
            <div class="text-xs text-dim">Available to spend</div>
            <MoneyFigure :value="props.balance.available" certainty="measured" size="lg" />
        </div>

        <div v-if="props.balance.held !== '0.0000'" class="border-l border-line pl-6">
            <div class="text-xs text-dim">Reserved while running</div>
            <MoneyFigure :value="props.balance.held" certainty="estimate" />
        </div>

        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-dim">Buy credits</span>
            <button
                v-for="pack in packs"
                :key="pack"
                type="button"
                :disabled="buying !== null"
                class="figure rounded-md border border-line bg-panel px-3 py-1.5 text-sm text-body transition-colors hover:border-measured/50 disabled:opacity-50"
                @click="buy(pack)"
            >
                ${{ pack }}
            </button>
        </div>
    </div>
</template>
