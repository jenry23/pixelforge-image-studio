import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

/**
 * Reactive `matchMedia`.
 *
 * Used to keep the auth artwork out of the DOM entirely on small screens.
 * Hiding an `<img>` with `display: none` is not enough — browsers still fetch
 * it — so the only reliable way to skip the download is for the element never
 * to be rendered. `v-if` on this is that guarantee.
 *
 * Starts false so the first client render never requests the image before we
 * know the viewport can use it.
 */
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
