const DRAFT_VERSION = 1;
const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000;

type DraftEnvelope<T> = {
    version: number;
    saved_at: string;
    data: T;
};

export function makeDraftKey(
    type: 'pos' | 'purchase',
    businessId: number | string | null | undefined,
    userId?: number | string | null,
    branchId?: number | string | null,
) {
    const userScope = userId === undefined ? '' : `_user_${userId ?? 'unknown'}`;
    const branchScope = branchId === undefined ? '' : `_branch_${branchId ?? 'default'}`;

    return `blunk_${type}_draft_business_${businessId ?? 'unknown'}${userScope}${branchScope}`;
}

export function saveDraft<T>(key: string, data: T) {
    if (typeof localStorage === 'undefined') {
        return;
    }

    try {
        const payload: DraftEnvelope<T> = {
            version: DRAFT_VERSION,
            saved_at: new Date().toISOString(),
            data,
        };

        localStorage.setItem(key, JSON.stringify(payload));
    } catch {
        // localStorage can fail in private mode or when full. Drafts are best-effort only.
    }
}

export function loadDraft<T>(key: string): T | null {
    if (typeof localStorage === 'undefined') {
        return null;
    }

    try {
        const raw = localStorage.getItem(key);

        if (!raw) {
            return null;
        }

        const payload = JSON.parse(raw) as DraftEnvelope<T>;

        if (payload.version !== DRAFT_VERSION || !payload.saved_at) {
            clearDraft(key);
            return null;
        }

        if (Date.now() - new Date(payload.saved_at).getTime() > DRAFT_TTL_MS) {
            clearDraft(key);
            return null;
        }

        return payload.data ?? null;
    } catch {
        clearDraft(key);
        return null;
    }
}

export function clearDraft(key: string) {
    if (typeof localStorage === 'undefined') {
        return;
    }

    try {
        localStorage.removeItem(key);
    } catch {
        // Best-effort only.
    }
}
