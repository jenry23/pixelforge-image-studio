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
