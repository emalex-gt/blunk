import axios from 'axios';

export type OperationDraftType = 'pos_sale' | 'purchase' | 'transfer';

export type OperationDraftRecord<TPayload = Record<string, unknown>> = {
    id: number;
    type: OperationDraftType;
    title: string | null;
    payload: TPayload;
    payload_version: number;
    customer: { id: number; name: string; doc_number: string | null } | null;
    supplier: { id: number; name: string } | null;
    branch: { id: number; name: string } | null;
    source_branch: { id: number; name: string } | null;
    destination_branch: { id: number; name: string } | null;
    user: { id: number; name: string } | null;
    item_count: number;
    total: number;
    updated_at: string | null;
    last_used_at: string | null;
};

function errorMessage(error: unknown): string {
    if (!axios.isAxiosError(error)) {
        return error instanceof Error ? error.message : 'No se pudo completar la operación.';
    }

    if (error.response?.status === 419) {
        return 'La sesión expiró o el formulario perdió el token de seguridad. Recarga la página e intenta de nuevo.';
    }

    const payload = error.response?.data as {
        message?: string;
        errors?: Record<string, string[] | string>;
    } | null | undefined;

    const firstValidationError = payload?.errors
        ? Object.values(payload.errors).flat()[0]
        : null;

    return String(firstValidationError ?? payload?.message ?? 'No se pudo completar la operación.');
}

export async function listOperationDrafts<TPayload>(type: OperationDraftType) {
    try {
        const response = await axios.get<{ drafts: OperationDraftRecord<TPayload>[] }>('/operation-drafts', {
            params: { type },
            headers: {
                Accept: 'application/json',
            },
        });

        return response.data;
    } catch (error) {
        throw new Error(errorMessage(error));
    }
}

export async function saveOperationDraft<TPayload>(data: {
    type: OperationDraftType;
    title?: string | null;
    branch_id?: number | null;
    customer_id?: number | null;
    supplier_id?: number | null;
    source_branch_id?: number | null;
    destination_branch_id?: number | null;
    payload: TPayload;
    payload_version?: number;
}) {
    try {
        const response = await axios.post<{ draft: OperationDraftRecord<TPayload> }>('/operation-drafts', data, {
            headers: {
                Accept: 'application/json',
            },
        });

        return response.data;
    } catch (error) {
        throw new Error(errorMessage(error));
    }
}

export async function discardOperationDraft(id: number) {
    try {
        const response = await axios.post<{ ok: boolean }>(`/operation-drafts/${id}/discard`, {}, {
            headers: {
                Accept: 'application/json',
            },
        });

        return response.data;
    } catch (error) {
        throw new Error(errorMessage(error));
    }
}
