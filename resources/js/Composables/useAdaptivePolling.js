import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

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

                preserveState: true,
                preserveScroll: true,
            })
        }, interval)
    }

    const sync = () => (isActive() ? start() : stop())

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
