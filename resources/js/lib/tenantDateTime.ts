export function formatTenantDateTime(value: string, timezone: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('es', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: timezone,
    }).format(date);
}
