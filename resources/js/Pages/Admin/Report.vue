<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import Dropdown from '@/Components/Dropdown.vue'
import Download from '@/Components/Icons/Download.vue'
import Pagination from '@/Components/Pagination.vue'
import Spinner from '@/Components/Icons/Spinner.vue'
import TableSkeleton from '@/Components/TableSkeleton.vue'
import { useSteadyColumns } from '@/Composables/useSteadyColumns'
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

const pagingDays = ref(false)
const pagingUsers = ref(false)

const daysTable = ref<HTMLTableElement | null>(null)
const usersTable = ref<HTMLTableElement | null>(null)

const daysColumns = useSteadyColumns(daysTable)
const usersColumns = useSteadyColumns(usersTable)

function onDaysLoading(loading: boolean): void {
    daysColumns.onLoading(loading)
    pagingDays.value = loading
}

function onUsersLoading(loading: boolean): void {
    usersColumns.onLoading(loading)
    pagingUsers.value = loading
}

const daysSkeleton = [
    { cellClass: 'px-5 py-2', pillClass: 'h-2.5 rounded-full w-[5.6rem]' },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[1.75rem]', align: 'right' as const },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
    { cellClass: 'px-5 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
]

const usersSkeleton = [
    { cellClass: 'px-5 py-2', pillClass: 'h-2.5 rounded-full w-[14.45rem]' },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[1.75rem]', align: 'right' as const },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
    { cellClass: 'px-3 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
    { cellClass: 'px-5 py-2', pillClass: 'h-2.5 rounded-full w-[5rem]', align: 'right' as const },
]

// The report is a deferred prop, so the page paints before the figures land.
const props = defineProps<{ report?: Report }>()

const only = ['report']

const loaded = computed(() => props.report !== undefined)
const failed = ref(false)

const exports = computed(() => {
    const params = {
        start_date: props.report?.start_date,
        end_date: props.report?.end_date,
    }

    return [
        { label: 'Billing report (.xlsx)', href: route('admin.report.export.workbook', params) },
        { label: 'Daily breakdown (.csv)', href: route('admin.report.export.daily', params) },
        { label: 'By user (.csv)', href: route('admin.report.export.users', params) },
    ]
})

const placeholderDays = Array.from({ length: 6 })

function retry(): void {
    failed.value = false
    router.reload({ only })
}

const stopListening = router.on('finish', (event) => {
    if (event.detail.visit.only.includes('report')) {
        failed.value = props.report === undefined
    }
})

onBeforeUnmount(() => stopListening())
</script>

<template>
    <Head title="Billing" />

    <div class="flex flex-wrap items-start justify-between gap-x-10 gap-y-3">
        <div>
            <h1 class="text-[2.6rem] font-bold leading-none tracking-tight text-white">Billing</h1>
            <p class="mt-2 text-[1.05rem] text-[#9aa5b4]">Credit spend and provider cost</p>
        </div>
        <div class="flex items-center gap-5">
            <p v-if="loaded" class="figure text-sm text-[#9aa5b4]">{{ report!.period_label }}</p>

            <p v-else-if="failed" class="flex items-center gap-3 text-sm text-fail">
                Couldn't load the billing report.
                <button
                    type="button"
                    class="rounded-lg border border-fail/60 px-3 py-1.5 text-sm text-fail transition-colors hover:border-fail"
                    @click="retry"
                >
                    Try again
                </button>
            </p>

            <p v-else class="flex items-center gap-2.5 text-sm text-[#c7d1e0]" role="status">
                <Spinner class="size-4 shrink-0 text-[#8a9ffd]" />
                Loading billing report…
            </p>

            <Dropdown
                label="Export"
                :items="exports"
                :disabled="! loaded"
                note="Full UTC reporting period"
            >
                <template #icon>
                    <Download class="size-4 shrink-0" />
                </template>
            </Dropdown>
        </div>
    </div>

    <div class="mt-6 grid gap-5 sm:grid-cols-3">
        <div class="rounded-xl border border-outline bg-surface px-6 py-4">
            <div class="text-[1.05rem] text-[#dbe3ef]">Charged to users</div>
            <div class="figure mt-1.5 flex h-[2.3rem] items-center text-[1.9rem] font-semibold text-measured">
                <template v-if="loaded">${{ report!.totals.credit_spend.display }}</template>
                <span v-else class="block h-[1.4rem] w-[11rem] animate-pulse rounded-full bg-[#3f4a5e] motion-reduce:animate-none" />
            </div>
        </div>
        <div class="rounded-xl border border-outline bg-surface px-6 py-4">
            <div class="text-[1.05rem] text-[#dbe3ef]">Paid to Pixelforge</div>
            <div class="figure mt-1.5 flex h-[2.3rem] items-center text-[1.9rem] font-semibold text-white">
                <template v-if="loaded">${{ report!.totals.provider_cost.display }}</template>
                <span v-else class="block h-[1.4rem] w-[11rem] animate-pulse rounded-full bg-[#3f4a5e] motion-reduce:animate-none" />
            </div>
        </div>
        <div class="rounded-xl border border-outline bg-surface px-6 py-4">
            <div class="text-[1.05rem] text-[#dbe3ef]">Profit</div>
            <div class="figure mt-1.5 flex h-[2.3rem] items-center text-[1.9rem] font-semibold text-ok">
                <template v-if="loaded">${{ report!.totals.profit.display }}</template>
                <span v-else class="block h-[1.4rem] w-[11rem] animate-pulse rounded-full bg-[#3f4a5e] motion-reduce:animate-none" />
            </div>
        </div>
    </div>

    <div
        v-if="! loaded"
        class="mt-5 flex items-center rounded-xl border border-outline bg-surface px-5 py-2 text-[1.05rem]"
    >
        <span
            class="block h-[1.6rem] w-full animate-pulse rounded-full bg-[#3f4a5e] motion-reduce:animate-none"
        />
    </div>

    <div
        v-else
        class="mt-5 flex items-start gap-3 rounded-xl border px-5 py-2 text-[1.05rem]"
        :class="{
            'border-ok/40 bg-ok/10 text-ok': report!.reconciliation.matches === true,
            'border-fail/40 bg-fail/10 text-fail': report!.reconciliation.matches === false,
            'border-estimate/40 bg-estimate/10 text-estimate': !report!.reconciliation.available,
        }"
    >
        <svg
            v-if="report!.reconciliation.matches === true"
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
            <template v-if="report!.reconciliation.matches === true">
                Provider costs match Pixelforge to the nanodollar.
            </template>
            <template v-else-if="report!.reconciliation.matches === false">
                Provider costs disagree with Pixelforge. The days that differ are marked below.
            </template>
            <template v-else>
                Couldn't reach Pixelforge's usage endpoint, so these figures are unverified.
                <span class="mt-1 block text-sm opacity-80">{{ report!.reconciliation.error }}</span>
            </template>
        </div>
    </div>

    <div class="mt-4 overflow-hidden rounded-xl border border-outline bg-surface">
        <div class="overflow-x-auto">
            <table
                ref="daysTable"
                class="w-full min-w-[52rem] text-[0.95rem]"
                :style="daysColumns.widths.value.length ? { tableLayout: 'fixed' } : undefined"
            >
                <colgroup v-if="daysColumns.widths.value.length">
                    <col
                        v-for="(width, index) in daysColumns.widths.value"
                        :key="index"
                        :style="{ width: `${width}px` }"
                    />
                </colgroup>
                <thead>
                    <tr class="border-b border-outline text-white">
                        <th scope="col" class="px-5 py-3 text-left font-medium">UTC day</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Images</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Charged</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Our cost</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Pixelforge cost</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Delta</th>
                        <th scope="col" class="px-5 py-3 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <TableSkeleton
                    v-if="! loaded || pagingDays"
                    :rows="report?.days.data.length ?? placeholderDays.length"
                    :columns="daysSkeleton"
                    box-class="h-[1.5em]"
                    row-class="border-b border-divider-soft last:border-b-0"
                />

                <tbody v-else>
                    <tr
                        v-for="day in report!.days.data"
                        :key="day.date"
                        class="border-b border-divider-soft last:border-b-0"
                        :class="day.generations === 0 ? 'text-[#97a4ba]' : ''"
                    >
                        <th scope="row" class="figure px-5 py-2 text-left font-normal text-inherit">
                            {{ day.date }}
                        </th>
                        <td class="figure px-3 py-2 text-right text-inherit">{{ day.generations }}</td>
                        <td class="figure px-3 py-2 text-right text-measured">
                            ${{ day.credit_spend.display }}
                        </td>
                        <td class="figure px-3 py-2 text-right text-inherit">
                            ${{ day.provider_cost.display }}
                        </td>
                        <td class="figure px-3 py-2 text-right text-inherit">
                            <template v-if="day.provider_reported_cost">
                                ${{ day.provider_reported_cost.display }}
                            </template>
                            <span v-else class="text-estimate">unavailable</span>
                        </td>
                        <td class="figure px-3 py-2 text-right">
                            <span v-if="day.delta" :class="day.reconciled ? 'text-[#c3ccd8]' : 'text-fail'">
                                ${{ day.delta.display }}
                            </span>
                            <span v-else class="text-[#5e758c]">—</span>
                        </td>
                        <td class="figure px-5 py-2 text-right text-ok">${{ day.profit.display }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="! loaded" class="flex flex-wrap items-center justify-between gap-4 border-t border-outline px-5 py-4">
            <span
                class="block h-[1.1rem] w-[11rem] animate-pulse rounded-full bg-[#3f4a5e] motion-reduce:animate-none"
            />

            <div class="flex items-center gap-2" aria-hidden="true">
                <span class="rounded-lg border border-outline/40 px-3.5 py-2 text-sm text-[#5e758c]">
                    Previous
                </span>
                <span
                    v-for="slot in 3"
                    :key="slot"
                    class="block h-[2.4rem] min-w-[2.5rem] animate-pulse rounded-lg bg-[#3f4a5e] motion-reduce:animate-none"
                />
                <span class="rounded-lg border border-outline/40 px-3.5 py-2 text-sm text-[#5e758c]">
                    Next
                </span>
            </div>
        </div>

        <div v-else class="border-t border-outline">
            <Pagination
                :meta="report!.days.meta"
                page-name="days_page"
                :only="only"
                noun="day"
                noun-plural="days"
                @loading="onDaysLoading"
            />
        </div>
    </div>

    <h2 class="mt-7 text-[1.6rem] font-bold tracking-tight text-white">By user</h2>

    <div class="mt-4 overflow-hidden rounded-xl border border-outline bg-surface">
        <div v-if="! loaded || report!.users.data.length" class="overflow-x-auto">
            <table
                ref="usersTable"
                class="w-full min-w-[40rem] text-[0.95rem]"
                :style="usersColumns.widths.value.length ? { tableLayout: 'fixed' } : undefined"
            >
                <colgroup v-if="usersColumns.widths.value.length">
                    <col
                        v-for="(width, index) in usersColumns.widths.value"
                        :key="index"
                        :style="{ width: `${width}px` }"
                    />
                </colgroup>
                <thead>
                    <tr class="border-b border-outline text-white">
                        <th scope="col" class="px-5 py-3 text-left font-medium">User</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Images</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Charged</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Our cost</th>
                        <th scope="col" class="px-5 py-3 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <TableSkeleton
                    v-if="! loaded || pagingUsers"
                    :rows="report?.users.data.length ?? 3"
                    :columns="usersSkeleton"
                    box-class="h-[1.5em]"
                    row-class="border-b border-divider-soft last:border-b-0"
                />

                <tbody v-else>
                    <tr
                        v-for="user in report!.users.data"
                        :key="user.id"
                        class="border-b border-divider-soft last:border-b-0"
                    >
                        <th scope="row" class="px-5 py-2 text-left font-normal">
                            <span class="font-semibold text-white">{{ user.name }}</span>
                            <span class="ml-2 text-[#9aa5b4]">{{ user.email }}</span>
                        </th>
                        <td class="figure px-3 py-2 text-right text-inherit">{{ user.generations }}</td>
                        <td class="figure px-3 py-2 text-right text-measured">
                            ${{ user.credit_spend.display }}
                        </td>
                        <td class="figure px-3 py-2 text-right text-inherit">
                            ${{ user.provider_cost.display }}
                        </td>
                        <td class="figure px-5 py-2 text-right text-ok">${{ user.profit.display }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else class="px-5 py-10 text-center text-[#9aa5b4]">
            No billed generations in this window.
        </p>

        <div v-if="loaded && report!.users.meta.last_page > 1" class="border-t border-outline">
            <Pagination
                :meta="report!.users.meta"
                page-name="users_page"
                :only="only"
                noun="user"
                noun-plural="users"
                @loading="onUsersLoading"
            />
        </div>
    </div>
</template>
