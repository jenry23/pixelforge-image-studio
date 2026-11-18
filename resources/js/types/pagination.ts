export interface PaginationLink {
    url: string | null
    label: string
    active: boolean
}

export interface PaginationMeta {
    current_page: number
    from: number | null
    to: number | null
    last_page: number
    per_page: number
    total: number
    links: PaginationLink[]
}

export interface Paginated<T> {
    data: T[]
    meta: PaginationMeta
}
