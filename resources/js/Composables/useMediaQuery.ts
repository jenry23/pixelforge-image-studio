import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

export function useMediaQuery(query: string): Ref<boolean> {
    const matches = ref(false)

    let mql: MediaQueryList | null = null
    const update = (event: MediaQueryListEvent): void => {
        matches.value = event.matches
    }

    onMounted(() => {
        if (typeof window === 'undefined' || !window.matchMedia) {
            return
        }

        mql = window.matchMedia(query)
        matches.value = mql.matches
        mql.addEventListener('change', update)
    })

    onBeforeUnmount(() => mql?.removeEventListener('change', update))

    return matches
}
