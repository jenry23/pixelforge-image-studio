/**
 * Props every page receives from HandleInertiaRequests::share().
 *
 * Page-specific props are declared next to the page that uses them; only the
 * genuinely shared shape lives here.
 */
export interface AuthUser {
    id: number
    name: string
    email: string
    is_admin: boolean
}

export interface FlashMessages {
    success: string | null
    error: string | null
}

export interface SharedPageProps {
    auth: { user: AuthUser | null }
    flash: FlashMessages
    [key: string]: unknown
}

/** Laravel's validation errors, keyed by field name. */
export type ValidationErrors = Record<string, string>
