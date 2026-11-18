

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

export type ValidationErrors = Record<string, string>
