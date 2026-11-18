export interface MoneyValue {
    nanodollars: number
    usd: string
}

export interface MoneyBand {
    min: MoneyValue
    expected: MoneyValue
    max: MoneyValue
}

export interface CostBreakdownRow {
    type: string
    label: string
    exact: boolean
    tokens_min: number
    tokens_expected: number
    tokens_max: number
    cost_expected: MoneyValue
}

export interface CostEstimate {
    provider: MoneyBand
    charge: MoneyBand
    breakdown: CostBreakdownRow[]
}

export interface BalanceSummary {
    balance: string
    held: string
    available: string
    available_nanodollars: number
}

export interface MoneyDisplay {
    usd: string
    nanodollars: number
    exact: string
}

export interface TokenUsage {
    input_text_tokens: number
    input_image_tokens: number
    output_tokens: number
    reasoning_tokens: number
    total_tokens: number
}

export interface GenerationRow {
    id: string
    status: string
    status_label: string
    is_settled: boolean
    prompt: string
    size: string
    dimensions: string
    quality: string
    total_tokens: number | null
    reference_images: string[]
    estimated_charge: { expected: string; min: string; max: string; expected_nanodollars: number }
    actual_charge: MoneyDisplay | null
    provider_cost: { usd: string; exact: string } | null
    usage: TokenUsage | null
    image_url: string | null
    image_expired: boolean
    image_url_expires_at: string | null
    error_code: string | null
    error_message: string | null
    created_at: string
    completed_at: string | null
}
