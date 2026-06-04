export interface User {
    id: number;
    business_id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    role: string;
    is_super_admin?: boolean;
    is_active?: boolean;
}

export interface Business {
    id: number;
    name: string;
    country?: string | null;
    currency?: string | null;
    is_active?: boolean;
}

export interface CurrencyFormat {
    code: string;
    symbol: string;
    position: string;
}

export interface TenantSettings {
    id: number;
    business_id: number;
    use_product_images: boolean;
    max_users: number;
    receipt_format?: 'ticket' | 'document' | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    csrf_token: string;
    auth: {
        user: User;
    };
    business: Business | null;
    current_business_id: number | null;
    current_business: Business | null;
    available_businesses: Pick<Business, 'id' | 'name'>[] | null;
    tenant_settings: TenantSettings | null;
    currency_format: CurrencyFormat;
    use_product_images: boolean;
};
