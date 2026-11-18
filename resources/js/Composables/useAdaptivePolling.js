import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Reload specific Inertia props on an interval, but only while there is
 * something to wait for.
 *
 * Generations are asynchronous, so the history page has to learn about state
 * changes it did not cause. Polling is used rather than websockets because it
 * needs no extra infrastructure to deploy and is deterministic under test --
 * correctness lives in the server-side reconciler, not in the transport.
 *
 * "Adaptive" means it stops completely once everything has settled, so an idle
 * page makes no requests at all. It also pauses while the tab is hidden, since
 * nobody is reading it.
 */
export function useAdaptivePolling(options) {
    const { only, isActive, interval = 2500 } = options

    const polling = ref(false)
    let timer = null

    const stop = () => {
        if (timer !== null) {
            clearInterval(timer)
            timer = null
        }
        polling.value = false
    }

    const start = () => {
        if (timer !== null || document.hidden) return

        polling.value = true
        timer = setInterval(() => {
            router.reload({
                only,
                // A reload must never clobber half-typed input or scroll position.
                preserveState: true,
                preserveScroll: true,
            })
        }, interval)
    }

    const sync = () => (isActive() ? start() : stop())

    // React to the props the poll itself brings back: once the server says
    // nothing is in flight, this is what turns the polling off.
    watch(isActive, sync)

    const onVisibilityChange = () => (document.hidden ? stop() : sync())

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibilityChange)
        sync()
    })

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibilityChange)
        stop()
    })

    return { polling }
}
