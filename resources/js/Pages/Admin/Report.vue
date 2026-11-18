<script setup>
import { Head } from '@inertiajs/vue3'
import StudioLayout from '@/Layouts/StudioLayout.vue'
import MoneyFigure from '@/Components/MoneyFigure.vue'

/**
 * The operator view: what users were charged, what Pixelforge charged us, and
 * the difference -- checked against the provider's own record.
 *
 * The delta column is the reason this page exists. Our provider-cost figure is
 * computed from stored token usage and the rate snapshot each generation was
 * created with; Pixelforge's comes from GET /v1/usage. They are compared as
 * exact integers, so a mismatch of a single nanodollar shows up.
 *
 * When the usage endpoint cannot be reached, the comparison is shown as
 * unavailable rather than quietly omitted -- our own numbers are never
 * presented as verified when nothing verified them.
 */
defineOptions({ layout: StudioLayout })

defineProps({
    report: { type: Object, required: true },
})
</script>

<template>
    <Head title="Billing" />

    <header class="flex flex-wrap items-baseline justify-between gap-4">
        <div>
            <h1 class="text-sm font-medium text-body">Credit spend and provider cost</h1>
            <p class="figure mt-1 text-xs text-dim">
                {{ report.start_date }} to {{ report.end_date }}, UTC days
            </p>
        </div>

        <div class="flex flex-wrap gap-x-8 gap-y-2">
            <div>
                <div class="text-xs text-dim">Charged to users</div>
                <MoneyFigure
                    :value="report.totals.credit_spend.display"
                    :exact="report.totals.credit_spend.exact"
                    certainty="measured"
                    size="lg"
                />
            </div>
            <div>
                <div class="text-xs text-dim">Paid to Pixelforge</div>
                <MoneyFigure
                    :value="report.totals.provider_cost.display"
                    :exact="report.totals.provider_cost.exact"
                    certainty="muted"
                    size="lg"
                />
            </div>
            <div>
                <div class="text-xs text-dim">Profit</div>
                <MoneyFigure
                    :value="report.totals.profit.display"
                    :exact="report.totals.profit.exact"
                    certainty="free"
                    size="lg"
                />
            </div>
        </div>
    </header>

    <!-- Reconciliation state. Three outcomes, each stated plainly. -->
    <div
        class="mt-6 rounded-lg border px-4 py-3 text-sm"
        :class="{
            'border-ok/30 bg-ok/5 text-ok': report.reconciliation.matches === true,
            'border-fail/30 bg-fail/5 text-fail': report.reconciliation.matches === false,
            'border-estimate/30 bg-estimate/5 text-estimate':
                !report.reconciliation.available,
        }"
    >
        <template v-if="report.reconciliation.matches === true">
            Our provider costs match Pixelforge's own record exactly, to the nanodollar.
        </template>
        <template v-else-if="report.reconciliation.matches === false">
            Our provider costs disagree with Pixelforge's record. The days that differ are
            marked below.
        </template>
        <template v-else>
            Couldn't reach Pixelforge's usage endpoint, so these figures are unverified.
            <span class="mt-1 block text-xs opacity-80">
                {{ report.reconciliation.error }}
            </span>
        </template>
    </div>

    <!-- Per day -->
    <div class="mt-8 overflow-x-auto">
        <table class="w-full min-w-3xl text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-dim">
                    <th scope="col" class="py-2 text-left font-normal">UTC day</th>
                    <th scope="col" class="py-2 text-right font-normal">Images</th>
                    <th scope="col" class="py-2 text-right font-normal">Charged</th>
                    <th scope="col" class="py-2 text-right font-normal">Our cost</th>
                    <th scope="col" class="py-2 text-right font-normal">Pixelforge says</th>
                    <th scope="col" class="py-2 text-right font-normal">Delta</th>
                    <th scope="col" class="py-2 text-right font-normal">Profit</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="day in report.days"
                    :key="day.date"
                    class="border-b border-line/50"
                    :class="{ 'opacity-45': day.generations === 0 }"
                >
                    <th scope="row" class="figure py-2 text-left font-normal text-muted">
                        {{ day.date }}
                    </th>
                    <td class="figure py-2 text-right text-muted">{{ day.generations }}</td>
                    <td class="py-2 text-right">
                        <MoneyFigure
                            :value="day.credit_spend.display"
                            :exact="day.credit_spend.exact"
                            certainty="measured"
                            size="sm"
                        />
                    </td>
                    <td class="py-2 text-right">
                        <MoneyFigure
                            :value="day.provider_cost.display"
                            :exact="day.provider_cost.exact"
                            certainty="muted"
                            size="sm"
                        />
                    </td>
                    <td class="py-2 text-right">
                        <MoneyFigure
                            v-if="day.provider_reported_cost"
                            :value="day.provider_reported_cost.display"
                            :exact="day.provider_reported_cost.exact"
                            certainty="muted"
                            size="sm"
                        />
                        <span v-else class="text-xs text-estimate">unavailable</span>
                    </td>
                    <td class="py-2 text-right">
                        <!-- Deliberately blank when it agrees: a column of
                             zeros is noise, so only a real difference shows. -->
                        <span v-if="day.reconciled === true" class="text-xs text-ok">—</span>
                        <MoneyFigure
                            v-else-if="day.delta"
                            :value="day.delta.display"
                            :exact="day.delta.exact"
                            certainty="estimate"
                            size="sm"
                        />
                        <span v-else class="text-xs text-dim">?</span>
                    </td>
                    <td class="py-2 text-right">
                        <MoneyFigure
                            :value="day.profit.display"
                            :exact="day.profit.exact"
                            certainty="free"
                            size="sm"
                        />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Per user -->
    <h2 class="mt-12 text-sm font-medium text-body">By user</h2>

    <div v-if="report.users.length" class="mt-4 overflow-x-auto">
        <table class="w-full min-w-xl text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-dim">
                    <th scope="col" class="py-2 text-left font-normal">User</th>
                    <th scope="col" class="py-2 text-right font-normal">Images</th>
                    <th scope="col" class="py-2 text-right font-normal">Charged</th>
                    <th scope="col" class="py-2 text-right font-normal">Our cost</th>
                    <th scope="col" class="py-2 text-right font-normal">Profit</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="user in report.users" :key="user.id" class="border-b border-line/50">
                    <th scope="row" class="py-2 text-left font-normal">
                        <span class="text-body">{{ user.name }}</span>
                        <span class="ml-2 text-xs text-dim">{{ user.email }}</span>
                    </th>
                    <td class="figure py-2 text-right text-muted">{{ user.generations }}</td>
                    <td class="py-2 text-right">
                        <MoneyFigure
                            :value="user.credit_spend.display"
                            :exact="user.credit_spend.exact"
                            certainty="measured"
                            size="sm"
                        />
                    </td>
                    <td class="py-2 text-right">
                        <MoneyFigure
                            :value="user.provider_cost.display"
                            :exact="user.provider_cost.exact"
                            certainty="muted"
                            size="sm"
                        />
                    </td>
                    <td class="py-2 text-right">
                        <MoneyFigure
                            :value="user.profit.display"
                            :exact="user.profit.exact"
                            certainty="free"
                            size="sm"
                        />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <p v-else class="mt-4 text-sm text-muted">
        No billed generations in this window.
    </p>
</template>
