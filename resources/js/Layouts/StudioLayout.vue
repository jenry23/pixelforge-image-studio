<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import Spinner from '@/Components/Icons/Spinner.vue'
import StarFour from '@/Components/Icons/StarFour.vue'
import type { SharedPageProps } from '@/types'

const page = usePage<SharedPageProps>()

const user = computed(() => page.props.auth.user)
const flash = computed(() => page.props.flash ?? { success: null, error: null })

const navLinks = [
    { label: 'Generate', route: 'generations.create' },
    { label: 'History', route: 'generations.index' },
] as const

const pendingPath = ref<string | null>(null)

const stopStart = router.on('start', (event) => {
    const target = new URL(event.detail.visit.url, window.location.origin).pathname

    // Paging and polling stay on the page they are already on; only a move to a
    // different page earns feedback in the nav.
    pendingPath.value = target === window.location.pathname ? null : target
})

const stopFinish = router.on('finish', () => (pendingPath.value = null))

function isPending(routeName: string): boolean {
    return pendingPath.value !== null
        && new URL(route(routeName), window.location.origin).pathname === pendingPath.value
}

onBeforeUnmount(() => {
    stopStart()
    stopFinish()
})
</script>

<template>
    <div class="min-h-screen bg-[#0e1522]">
        <header class="border-b border-[#1d2635]">
            <div class="mx-auto flex max-w-[100rem] flex-wrap items-center gap-x-8 gap-y-3 px-5 py-4 sm:px-[3.4rem]">
                <Link
                    :href="route('generations.create')"
                    class="inline-flex items-center gap-2.5 text-[1.15rem] font-semibold tracking-tight text-white"
                >
                    <StarFour class="size-5 shrink-0" />
                    Image Studio
                </Link>

                <nav class="flex items-center gap-6 text-[1rem]">
                    <Link
                        v-for="link in navLinks"
                        :key="link.route"
                        :href="route(link.route)"
                        :aria-current="route().current(link.route) ? 'page' : undefined"
                        class="inline-flex items-center gap-2 transition-colors"
                        :class="
                            route().current(link.route)
                                ? 'font-medium text-[#8a9ffd]'
                                : 'text-[#c3ccd8] hover:text-white'
                        "
                    >
                        {{ link.label }}
                        <Spinner v-if="isPending(link.route)" class="size-3.5 shrink-0" />
                    </Link>

                    <Link
                        v-if="user?.is_admin"
                        :href="route('admin.report')"
                        :aria-current="route().current('admin.report') ? 'page' : undefined"
                        class="inline-flex items-center gap-2 transition-colors"
                        :class="
                            route().current('admin.report')
                                ? 'font-medium text-[#8a9ffd]'
                                : 'text-[#c3ccd8] hover:text-white'
                        "
                    >
                        Billing
                        <Spinner v-if="isPending('admin.report')" class="size-3.5 shrink-0" />
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-5 text-[0.95rem]">
                    <span class="text-[#9aa5b4]">{{ user?.email }}</span>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="text-white transition-colors hover:text-[#8a9ffd]"
                    >
                        Sign out
                    </Link>
                </div>
            </div>
        </header>

        <div v-if="flash.success" class="border-b border-ok/30 bg-ok/10">
            <p class="mx-auto max-w-[100rem] px-5 py-2.5 text-sm text-ok sm:px-[3.4rem]">
                {{ flash.success }}
            </p>
        </div>

        <main class="mx-auto max-w-[100rem] px-5 py-6 sm:px-[3.4rem]">
            <slot />
        </main>
    </div>
</template>
