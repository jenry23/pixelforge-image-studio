<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

/**
 * The app shell: a narrow top bar and nothing else.
 *
 * There are only three places to go, so a sidebar would be chrome for its own
 * sake. Flash messages appear inline here rather than as floating toasts,
 * because a confirmation about money should stay on screen until the page
 * changes.
 */
const page = usePage()

const user = computed(() => page.props.auth.user)
const flash = computed(() => page.props.flash ?? {})
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b border-line bg-panel/60">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
                <Link
                    :href="route('generations.create')"
                    class="text-sm font-semibold tracking-tight text-body"
                >
                    Image studio
                </Link>

                <nav class="flex items-center gap-4 text-sm">
                    <Link
                        :href="route('generations.create')"
                        class="transition-colors"
                        :class="route().current('generations.create') ? 'text-measured' : 'text-muted hover:text-body'"
                    >
                        Generate
                    </Link>
                    <Link
                        :href="route('generations.index')"
                        class="transition-colors"
                        :class="route().current('generations.index') ? 'text-measured' : 'text-muted hover:text-body'"
                    >
                        History
                    </Link>
                    <Link
                        v-if="user?.is_admin"
                        :href="route('admin.report')"
                        class="transition-colors"
                        :class="route().current('admin.report') ? 'text-measured' : 'text-muted hover:text-body'"
                    >
                        Billing
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-4 text-sm">
                    <span class="text-dim">{{ user?.email }}</span>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="text-muted transition-colors hover:text-body"
                    >
                        Sign out
                    </Link>
                </div>
            </div>
        </header>

        <div v-if="flash.success" class="border-b border-ok/30 bg-ok/10">
            <p class="mx-auto max-w-6xl px-4 py-2 text-sm text-ok sm:px-6">
                {{ flash.success }}
            </p>
        </div>

        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <slot />
        </main>
    </div>
</template>
