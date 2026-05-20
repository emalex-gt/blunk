import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type CompanySettings = {
    company_logo_url: string | null;
    company_name: string | null;
    company_tax_id: string | null;
    company_address: string | null;
    company_phone: string | null;
    receipt_format: 'ticket' | 'document' | null;
    allow_manual_price: boolean;
    remember_last_customer_product_price: boolean;
};

type FelSettings = {
    provider: string;
    environment: 'test' | 'production';
    enabled: boolean;
    issuer_tax_id: string | null;
    username: string | null;
    test_base_url: string | null;
    production_base_url: string | null;
    establishment_code: string | null;
    establishment_name: string | null;
    affiliate_type: string | null;
    phrase_type: string | null;
    phrase_scenario: string | null;
    certifier_tax_id: string | null;
    last_successful_connection_at: string | null;
    last_error: string | null;
} | null;

export default function Company({
    business,
    settings,
    felSettings,
}: {
    business: { country?: string | null };
    settings: CompanySettings;
    felSettings: FelSettings;
}) {
    const [preview, setPreview] = useState(settings.company_logo_url);
    const form = useForm<{
        company_name: string;
        company_tax_id: string;
        company_address: string;
        company_phone: string;
        receipt_format: 'ticket' | 'document';
        allow_manual_price: boolean;
        remember_last_customer_product_price: boolean;
        logo: File | null;
        fel: {
            enabled: boolean;
            provider: string;
            environment: 'test' | 'production';
            issuer_tax_id: string;
            username: string;
            password: string;
            token: string;
            test_base_url: string;
            production_base_url: string;
            establishment_code: string;
            establishment_name: string;
            affiliate_type: string;
            phrase_type: string;
            phrase_scenario: string;
            certifier_tax_id: string;
        };
    }>({
        company_name: settings.company_name ?? '',
        company_tax_id: settings.company_tax_id ?? '',
        company_address: settings.company_address ?? '',
        company_phone: settings.company_phone ?? '',
        receipt_format: settings.receipt_format ?? 'ticket',
        allow_manual_price: Boolean(settings.allow_manual_price),
        remember_last_customer_product_price: Boolean(settings.remember_last_customer_product_price),
        logo: null,
        fel: {
            enabled: Boolean(felSettings?.enabled),
            provider: felSettings?.provider ?? 'digifact',
            environment: felSettings?.environment ?? 'test',
            issuer_tax_id: felSettings?.issuer_tax_id ?? '',
            username: felSettings?.username ?? '',
            password: '',
            token: '',
            test_base_url: felSettings?.test_base_url ?? '',
            production_base_url: felSettings?.production_base_url ?? '',
            establishment_code: felSettings?.establishment_code ?? '',
            establishment_name: felSettings?.establishment_name ?? '',
            affiliate_type: felSettings?.affiliate_type ?? '',
            phrase_type: felSettings?.phrase_type ?? '',
            phrase_scenario: felSettings?.phrase_scenario ?? '',
            certifier_tax_id: felSettings?.certifier_tax_id ?? '',
        },
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('settings.company.update'), {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    function selectLogo(file: File | null) {
        form.setData('logo', file);

        if (file) {
            setPreview(URL.createObjectURL(file));
        }
    }

    function setFelField<K extends keyof typeof form.data.fel>(field: K, value: (typeof form.data.fel)[K]) {
        form.setData('fel', {
            ...form.data.fel,
            [field]: value,
        });
    }

    function testConnection() {
        router.post(route('fel.test-connection'), {}, {
            preserveScroll: true,
        });
    }

    const formErrors = form.errors as Record<string, string>;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-950">Empresa</h2>}>
            <Head title="Empresa" />

            <div className="py-5">
                <div className="mx-auto max-w-4xl px-5 sm:px-6">
                    <form onSubmit={submit} className="space-y-5 rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-[0_8px_30px_rgba(15,23,42,0.06)]">
                        <div>
                            <h1 className="text-xl font-semibold text-slate-950">Datos para comprobantes</h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Estos datos aparecerán en el comprobante imprimible.
                            </p>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <TextField label="Nombre de la empresa" value={form.data.company_name} onChange={(value) => form.setData('company_name', value)} error={form.errors.company_name} />
                            <TextField label="Identificación fiscal (NIT/CUIT)" value={form.data.company_tax_id} onChange={(value) => form.setData('company_tax_id', value)} error={form.errors.company_tax_id} />
                            <TextField label="Dirección" value={form.data.company_address} onChange={(value) => form.setData('company_address', value)} error={form.errors.company_address} />
                            <TextField label="Teléfono" value={form.data.company_phone} onChange={(value) => form.setData('company_phone', value)} error={form.errors.company_phone} />
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div className="text-sm font-medium text-slate-700">Formato de comprobante</div>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <button
                                    type="button"
                                    onClick={() => form.setData('receipt_format', 'ticket')}
                                    className={`rounded-2xl border px-4 py-3 text-left transition ${
                                        form.data.receipt_format === 'ticket'
                                            ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50'
                                    }`}
                                >
                                    <div className="font-semibold">Ticket</div>
                                    <div className="mt-1 text-xs text-slate-500">Formato compacto para impresora térmica.</div>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => form.setData('receipt_format', 'document')}
                                    className={`rounded-2xl border px-4 py-3 text-left transition ${
                                        form.data.receipt_format === 'document'
                                            ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm'
                                            : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-200 hover:bg-indigo-50'
                                    }`}
                                >
                                    <div className="font-semibold">Documento</div>
                                    <div className="mt-1 text-xs text-slate-500">Formato hoja A4 o Carta.</div>
                                </button>
                            </div>
                            {form.errors.receipt_format && <p className="mt-2 text-sm text-red-600">{form.errors.receipt_format}</p>}
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div className="text-sm font-medium text-slate-700">Precios en POS</div>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <label className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.allow_manual_price}
                                        onChange={(event) => form.setData('allow_manual_price', event.target.checked)}
                                        className="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span>
                                        <span className="block font-semibold text-slate-800">Permitir precio manual</span>
                                        <span className="mt-1 block text-xs text-slate-500">Permite editar el precio unitario en ventas POS.</span>
                                    </span>
                                </label>
                                <label className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.remember_last_customer_product_price}
                                        onChange={(event) => form.setData('remember_last_customer_product_price', event.target.checked)}
                                        className="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span>
                                        <span className="block font-semibold text-slate-800">Recordar Ãºltimo precio por cliente y producto</span>
                                        <span className="mt-1 block text-xs text-slate-500">Usa el Ãºltimo precio vendido al cliente cuando aplique.</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        {false && business.country === 'GT' && (
                            <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-lg font-semibold text-slate-950">
                                            Facturación electrónica FEL
                                        </h2>
                                        <p className="mt-1 text-sm text-slate-500">
                                            Configuración por empresa para Digifact Guatemala.
                                        </p>
                                    </div>
                                    <label className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={form.data.fel.enabled}
                                            onChange={(event) => setFelField('enabled', event.target.checked)}
                                            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        Habilitar FEL
                                    </label>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <label className="block">
                                        <span className="text-sm font-medium text-slate-700">Proveedor</span>
                                        <select
                                            value={form.data.fel.provider}
                                            onChange={(event) => setFelField('provider', event.target.value)}
                                            className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                        >
                                            <option value="digifact">Digifact</option>
                                        </select>
                                    </label>
                                    <label className="block">
                                        <span className="text-sm font-medium text-slate-700">Ambiente</span>
                                        <select
                                            value={form.data.fel.environment}
                                            onChange={(event) => setFelField('environment', event.target.value as 'test' | 'production')}
                                            className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
                                        >
                                            <option value="test">Test</option>
                                            <option value="production">Producción</option>
                                        </select>
                                    </label>
                                    <TextField label="Usuario Digifact" value={form.data.fel.username} onChange={(value) => setFelField('username', value)} error={form.errors['fel.username']} />
                                    <TextField label="NIT emisor" value={form.data.fel.issuer_tax_id} onChange={(value) => setFelField('issuer_tax_id', value)} error={form.errors['fel.issuer_tax_id']} />
                                    <TextField label="Password Digifact" type="password" value={form.data.fel.password} onChange={(value) => setFelField('password', value)} error={form.errors['fel.password']} />
                                    <TextField label="Token/API Key" type="password" value={form.data.fel.token} onChange={(value) => setFelField('token', value)} error={form.errors['fel.token']} />
                                    <TextField label="URL Test" value={form.data.fel.test_base_url} onChange={(value) => setFelField('test_base_url', value)} error={form.errors['fel.test_base_url']} />
                                    <TextField label="URL Producción" value={form.data.fel.production_base_url} onChange={(value) => setFelField('production_base_url', value)} error={form.errors['fel.production_base_url']} />
                                    <TextField label="Código establecimiento" value={form.data.fel.establishment_code} onChange={(value) => setFelField('establishment_code', value)} error={form.errors['fel.establishment_code']} />
                                    <TextField label="Nombre establecimiento" value={form.data.fel.establishment_name} onChange={(value) => setFelField('establishment_name', value)} error={form.errors['fel.establishment_name']} />
                                    <TextField label="Tipo afiliación IVA" value={form.data.fel.affiliate_type} onChange={(value) => setFelField('affiliate_type', value)} error={form.errors['fel.affiliate_type']} />
                                    <TextField label="Tipo frase" value={form.data.fel.phrase_type} onChange={(value) => setFelField('phrase_type', value)} error={form.errors['fel.phrase_type']} />
                                    <TextField label="Escenario frase" value={form.data.fel.phrase_scenario} onChange={(value) => setFelField('phrase_scenario', value)} error={form.errors['fel.phrase_scenario']} />
                                    <TextField label="NIT certificador" value={form.data.fel.certifier_tax_id} onChange={(value) => setFelField('certifier_tax_id', value)} error={form.errors['fel.certifier_tax_id']} />
                                </div>

                                {(felSettings?.last_successful_connection_at || felSettings?.last_error || formErrors.fel_connection) && (
                                    <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                                        {felSettings?.last_successful_connection_at && (
                                            <div>Última conexión exitosa: {felSettings?.last_successful_connection_at}</div>
                                        )}
                                        {(formErrors.fel_connection || felSettings?.last_error) && (
                                            <div className="mt-1 font-semibold text-red-600">
                                                {formErrors.fel_connection || felSettings?.last_error}
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div className="flex justify-end">
                                    <button
                                        type="button"
                                        onClick={testConnection}
                                        className="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                                    >
                                        Probar conexión
                                    </button>
                                </div>
                            </div>
                        )}

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <label className="block">
                                <span className="text-sm font-medium text-slate-700">Logo</span>
                                <input
                                    type="file"
                                    accept="image/*"
                                    onChange={(event) => selectLogo(event.target.files?.[0] ?? null)}
                                    className="mt-2 block w-full text-sm text-slate-700 file:mr-4 file:rounded-xl file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-indigo-700"
                                />
                            </label>
                            {form.errors.logo && <p className="mt-2 text-sm text-red-600">{form.errors.logo}</p>}

                            {preview && (
                                <div className="mt-4">
                                    <img src={preview} alt="Logo" className="h-20 max-w-56 rounded-xl object-contain" />
                                </div>
                            )}
                        </div>

                        <div className="flex justify-end">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Guardar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function TextField({
    label,
    value,
    onChange,
    error,
    type = 'text',
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
}) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">{label}</span>
            <input
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"
            />
            {error && <span className="mt-1 block text-sm text-red-600">{error}</span>}
        </label>
    );
}
