import { ref, type Ref } from 'vue'

export function useSteadyColumns(table: Ref<HTMLTableElement | null>) {
    const widths = ref<number[]>([])

    function freeze(): void {
        const element = table.value

        if (! element) {
            return
        }

        widths.value = Array.from(element.querySelectorAll('thead th')).map(
            (header) => header.getBoundingClientRect().width,
        )
    }

    function release(): void {
        widths.value = []
    }

    function onLoading(loading: boolean): void {
        loading ? freeze() : release()
    }

    return { widths, freeze, release, onLoading }
}
