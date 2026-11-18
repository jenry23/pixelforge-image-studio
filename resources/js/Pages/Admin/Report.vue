<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import Pagination from '@/Components/Pagination.vue'
import StudioLayout from '@/Layouts/StudioLayout.vue'
import type { PaginationMeta } from '@/types/pagination'

interface MoneyCell {
    exact: string
    display: string
    nanodollars: number
}

interface DayRow {
    date: string
    generations: number
    credit_spend: MoneyCell
    provider_cost: MoneyCell
    profit: MoneyCell
    provider_reported_cost: MoneyCell | null
    delta: MoneyCell | null
    reconciled: boolean | null
}

interface UserRow {
    id: number
    name: string
    email: string
    generations: number
    credit_spend: MoneyCell
    provider_cost: MoneyCell
    profit: MoneyCell
}

interface Report {
    start_date: string
    end_date: string
    period_label: string
    days: { data: DayRow[]; meta: PaginationMeta }
    users: { data: UserRow[]; meta: PaginationMeta }
    totals: {
        generations: number
        credit_spend: MoneyCell
        provider_cost: MoneyCell
        profit: MoneyCell
        provider_reported_cost: MoneyCell | null
        delta: MoneyCell | null
    }
    reconciliation: { available: boolean; error: string | null; matches: boolean | null }
}

defineOptions({ layout: StudioLayout })

defineProps<{ report: Report }>()

const only = ['report']
</script>

<template>
    <Head title="Billing" />

    <div class="flex flex-wrap items-start justify-between gap-x-10 gap-y-3">
        <div>
            <h1 class="text-[2.6rem] font-bold leading-none tracking-tight text-white">Billing</h1>
            <p class="mt-2 text-[1.05rem] text-[#9aa5b4]">Credit spend and provider cost</p>
        </div>
        <p class="figure text-sm text-[#9aa5b4]">{{ report.period_label }}</p>
    </div>

    <div class="mt-7 grid gap-5 sm:grid-cols-3">
        <div class="rounded-xl border border-[#262d39] bg-[#0f1621] px-6 py-5">
            <div class="text-[1.05rem] text-white">Charged to users</div>
            <div class="figure mt-2 text-[1.9rem] text-measured">
                ${{ report.totals.credit_spend.display }}
            </div>
        </div>
        <div class="rounded-xl border border-[#262d39] bg-[#0f1621] px-6 py-5">
            <div class="text-[1.05rem] text-white">Paid to Pixelforge</div>
            <div class="figure mt-2 text-[1.9rem] text-white">
                ${{ report.totals.provider_cost.display }}
            </div>
        </div>
        <div class="rounded-xl border border-[#262d39] bg-[#0f1621] px-6 py-5">
            <div class="text-[1.05rem] text-white">Profit</div>
            <div class="figure mt-2 text-[1.9rem] text-ok">${{ report.totals.profit.display }}</div>
        </div>
    </div>

    <div
        class="mt-5 flex items-start gap-3 rounded-xl border px-5 py-4 text-[1.05rem]"
        :class="{
            'border-ok/40 bg-ok/10 text-ok': report.reconciliation.matches === true,
            'border-fail/40 bg-fail/10 text-fail': report.reconciliation.matches === false,
            'border-estimate/40 bg-estimate/10 text-estimate': !report.reconciliation.available,
        }"
    >
        <svg
            v-if="report.reconciliation.matches === true"
            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 size-5 shrink-0" aria-hidden="true"
        >
            <path d="m5 13 4 4L19 7" />
        </svg>
        <svg
            v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" class="mt-0.5 size-5 shrink-0" aria-hidden="true"
        >
            <path d="M12 8v5M12 17h.01" />
            <circle cx="12" cy="12" r="9" />
        </svg>

        <div>
            <template v-if="report.reconciliation.matches === true">
                Provider costs match Pixelforge to the nanodollar.
            </template>
            <template v-else-if="report.reconciliation.matches === false">
                Provider costs disagree with Pixelforge. The days that differ are marked below.
            </template>
            <template v-else>
                Couldn't reach Pixelforge's usage endpoint, so these figures are unverified.
                <span class="mt-1 block text-sm opacity-80">{{ report.reconciliation.error }}</span>
            </template>
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl border border-[#262d39] bg-[#0f1621]">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem] text-[0.95rem]">
                <thead>
                    <tr class="border-b border-[#262d39] text-[#9aa5b4]">
                        <th scope="col" class="px-5 py-4 text-left font-medium">UTC day</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Images</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Charged</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Our cost</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Pixelforge cost</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Delta</th>
                        <th scope="col" class="px-5 py-4 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="day in report.days.data"
                        :key="day.date"
                        class="border-b border-[#262d39]/60 last:border-b-0"
                        :class="{ 'opacity-55': day.generations === 0 }"
                    >
                        <th scope="row" class="figure px-5 py-3.5 text-left font-normal text-white">
                            {{ day.date }}
                        </th>
                        <td class="figure px-3 py-3.5 text-right text-[#c3ccd8]">{{ day.generations }}</td>
                        <td class="figure px-3 py-3.5 text-right text-measured">
                            ${{ day.credit_spend.display }}
                        </td>
                        <td class="figure px-3 py-3.5 text-right text-[#c3ccd8]">
                            ${{ day.provider_cost.display }}
                        </td>
                        <td class="figure px-3 py-3.5 text-right text-[#c3ccd8]">
                            <template v-if="day.provider_reported_cost">
                                ${{ day.provider_reported_cost.display }}
                            </template>
                            <span v-else class="text-estimate">unavailable</span>
                        </td>
                        <td class="figure px-3 py-3.5 text-right">
                            <span v-if="day.delta" :class="day.reconciled ? 'text-[#c3ccd8]' : 'text-fail'">
                                ${{ day.delta.display }}
                            </span>
                            <span v-else class="text-[#5e758c]">—</span>
                        </td>
                        <td class="figure px-5 py-3.5 text-right text-ok">${{ day.profit.display }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-t border-[#262d39]">
            <Pagination
                :meta="report.days.meta"
                page-name="days_page"
                :only="only"
                noun="day"
                noun-plural="days"
            />
        </div>
    </div>

    <h2 class="mt-10 text-[1.9rem] font-bold tracking-tight text-white">By user</h2>

    <div class="mt-4 overflow-hidden rounded-xl border border-[#262d39] bg-[#0f1621]">
        <div v-if="report.users.data.length" class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-[0.95rem]">
                <thead>
                    <tr class="border-b border-[#262d39] text-[#9aa5b4]">
                        <th scope="col" class="px-5 py-4 text-left font-medium">User</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Images</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Charged</th>
                        <th scope="col" class="px-3 py-4 text-right font-medium">Our cost</th>
                        <th scope="col" class="px-5 py-4 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in report.users.data"
                        :key="user.id"
                        class="border-b border-[#262d39]/60 last:border-b-0"
                    >
                        <th scope="row" class="px-5 py-3.5 text-left font-normal">
                            <span class="font-semibold text-white">{{ user.name }}</span>
                            <span class="ml-2 text-[#9aa5b4]">{{ user.email }}</span>
                        </th>
                        <td class="figure px-3 py-3.5 text-right text-[#c3ccd8]">{{ user.generations }}</td>
                        <td class="figure px-3 py-3.5 text-right text-measured">
                            ${{ user.credit_spend.display }}
                        </td>
                        <td class="figure px-3 py-3.5 text-right text-[#c3ccd8]">
                            ${{ user.provider_cost.display }}
                        </td>
                        <td class="figure px-5 py-3.5 text-right text-ok">${{ user.profit.display }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else class="px-5 py-10 text-center text-[#9aa5b4]">
            No billed generations in this window.
        </p>

        <div v-if="report.users.meta.last_page > 1" class="border-t border-[#262d39]">
            <Pagination
                :meta="report.users.meta"
                page-name="users_page"
                :only="only"
                noun="user"
                noun-plural="users"
            />
        </div>
    </div>
</template>
