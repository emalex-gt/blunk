export type MaintenanceAnnouncement = {
    title: string;
    message: string;
    expected_downtime: string | null;
    starts_at: string;
    ends_at: string;
    timezone: string;
    status: 'scheduled' | 'active';
};

export default function MaintenanceAnnouncementBanner({ announcement }: { announcement: MaintenanceAnnouncement | null }) {
    if (!announcement) {
        return null;
    }

    return (
        <aside className="border-b border-amber-300 bg-amber-50 text-amber-950" aria-label="Mantenimiento programado">
            <div className="mx-auto flex max-w-[1800px] flex-col gap-1 px-4 py-3 text-sm sm:px-6">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="rounded-full bg-amber-200 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-amber-900">
                        {announcement.status === 'active' ? 'Mantenimiento en curso' : 'Mantenimiento programado'}
                    </span>
                    <strong>{announcement.title}</strong>
                </div>
                <p>{announcement.message}</p>
                <p className="text-xs font-medium text-amber-800">
                    Ventana: {announcement.starts_at} a {announcement.ends_at} ({announcement.timezone})
                    {announcement.expected_downtime ? ` · Indisponibilidad estimada: ${announcement.expected_downtime}` : ''}
                </p>
            </div>
        </aside>
    );
}
