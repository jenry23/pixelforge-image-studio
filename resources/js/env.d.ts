

declare module '*.vue' {
    import type { DefineComponent } from 'vue'

    const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
    export default component
}

interface ZiggyRouter {
    current(name?: string, params?: unknown): boolean
    has(name: string): boolean
}

interface RouteHelper {
    (name: string, params?: unknown, absolute?: boolean): string
    (): ZiggyRouter
}

declare global {
    const route: RouteHelper
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: RouteHelper
    }
}

export {}
