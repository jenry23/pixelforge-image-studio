<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import StudioLayout from '@/Layouts/StudioLayout.vue'
import BalanceBar from '@/Components/BalanceBar.vue'
import GenerationRow from '@/Components/GenerationRow.vue'
import { useAdaptivePolling } from '@/Composables/useAdaptivePolling.js'

const props = defineProps({
    generations: { type: Object, required: true },
    balance: { type: Object, required: true },
    hasPending: { type: Boolean, default: false },
})

/**
 * Poll only while the server says something is still in flight.
 *
 * `hasPending` comes back with every reload, so the final poll -- the one that
 * reports everything settled -- is also the one that switches polling off.
 */
const { polling } = useAdaptivePolling({
    only: ['generations', 'balance', 'hasPending'],
    isActive: () => props.hasPending,
})

const rows = computed(() => props.generations.data)
</script>

<template>
    <Head title="History" />

    <StudioLayout>
        <BalanceBar :balance="props.balance" class="mb-8" />

        <div class="flex items-baseline justify-between">
            <h1 class="text-sm font-medium text-body">Your generations</h1>

            <!-- Says plainly whether the page is watching for changes, rather
                 than leaving the user guessing why numbers move on their own. -->
            <span v-if="polling" class="flex items-center gap-1.5 text-xs text-measured">
                <span class="size-1.5 animate-pulse rounded-full bg-current" aria-hidden="true" />
                Watching for updates
            </span>
            <span v-else-if="rows.length" class="text-xs text-dim">Up to date</span>
        </div>

        <div v-if="rows.length" class="mt-4">
            <GenerationRow
                v-for="generation in rows"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <!-- An empty screen is an invitation to act. -->
        <div v-else class="panel mt-4 rounded-lg px-6 py-16 text-center">
            <p class="text-sm text-body">Nothing generated yet.</p>
            <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-muted">
                Write a prompt and you'll see the cost before you commit to it.
            </p>
            <Link
                :href="route('generations.create')"
                class="mt-5 inline-block rounded-md bg-measured px-4 py-2 text-sm font-medium text-ink transition-opacity hover:opacity-90"
            >
                Generate an image
            </Link>
        </div>

        <nav v-if="props.generations.meta?.last_page > 1" class="mt-8 flex gap-1">
            <Link
                v-for="link in props.generations.meta.links"
                :key="link.label"
                :href="link.url ?? ''"
                :disabled="!link.url"
                class="rounded border border-line px-3 py-1.5 text-sm transition-colors"
                :class="
                    link.active
                        ? 'border-measured/50 text-measured'
                        : 'text-muted hover:text-body aria-disabled:opacity-40'
                "
                v-html="link.label"
            />
        </nav>
    </StudioLayout>
</template>
