<script setup>
import MoneyFigure from './MoneyFigure.vue'

defineProps({
    breakdown: { type: Array, required: true },
})

const tokens = (row) =>
    row.exact
        ? row.tokens_expected.toLocaleString()
        : `${row.tokens_min.toLocaleString()}–${row.tokens_max.toLocaleString()}`
</script>

<template>
    <table class="w-full text-sm">
        <caption class="sr-only">
            Estimated cost by token type
        </caption>
        <tbody>
            <tr
                v-for="row in breakdown"
                :key="row.type"
                class="border-t border-line/60"
            >
                <th scope="row" class="py-2 text-left font-normal text-muted">
                    {{ row.label }}
                </th>

                <td class="py-2 text-right">
                    <span class="figure text-xs" :class="row.exact ? 'text-muted' : 'text-estimate'">
                        {{ tokens(row) }}
                    </span>
                </td>

                <td class="w-20 py-2 pl-3 text-right text-xs">
                    <span v-if="row.exact" class="text-dim">exact</span>
                    <span v-else class="text-estimate/80">varies</span>
                </td>

                <td class="py-2 pl-3 text-right">
                    <MoneyFigure
                        :value="row.cost_expected.usd.slice(0, 8)"
                        :exact="row.cost_expected.usd"
                        :certainty="row.exact ? 'measured' : 'estimate'"
                        size="sm"
                    />
                </td>
            </tr>
        </tbody>
    </table>
</template>
