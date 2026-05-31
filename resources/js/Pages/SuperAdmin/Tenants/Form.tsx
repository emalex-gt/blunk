import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Tenant = {
    id: number;
    name: string;
    slug: string | null;
    country: string | null;
    currency: string;
    phone: string | null;
    email: string | null;
    logo_url: string | null;
    is_active: boolean;
};

type FelPhrase = {
    data_identifier: string;
    phrase_type: string;
    scenario_code: string;
    resolution_number: string | null;
    resolution_date: string | null;
};

type FelSettings = {
    enabled: boolean;
    environment: 'test' | 'production';
    issuer_tax_id: string | null;
    username: string | null;
    test_base_url: string | null;
    production_base_url: string | null;
    affiliate_type: string | null;
    certifier_tax_id: string | null;
    last_successful_connection_at: string | null;
    last_error: string | null;
    phrases: FelPhrase[];
};

type AvailableModule = {
    key: string;
    name: string;
    description: string;
    group: string;
    plan_hint: string;
};

const countryOptions = [
    { value: 'GT', label: 'Guatemala', symbol: 'Q' },
    { value: 'AR', label: 'Argentina', symbol: '$' },
];

export default function Form({
    tenant,
    settings,
    felSettings,
    availableModules,
    enabledModules,
}: {
    tenant: Tenant | null;
    settings: {
        use_product_images: boolean;
        max_users: number;
        receipt_format?: 'ticket' | 'document' | null;
        use_branches?: boolean;
        products_shared_across_branches?: boolean;
        pricing_scope?: 'global' | 'branch';
        allow_manual_price?: boolean;
        manual_price_min_margin_percent?: number | string;
        remember_last_customer_product_price?: boolean;
        enable_credit_sales?: boolean;
        allow_receipts?: boolean;
        allow_invoices?: boolean;
    };
    felSettings: FelSettings;
    availableModules: AvailableModule[];
    enabledModules: string[];
}) {
    const editing = Boolean(tenant);
    const { data, setData, post, processing, errors } = useForm({
        name: tenant?.name ?? '',
        slug: tenant?.slug ?? '',
        country: tenant?.country ?? 'GT',
        phone: tenant?.phone ?? '',
        email: tenant?.email ?? '',
        is_active: tenant?.is_active ?? true,
        use_product_images: settings.use_product_images,
        max_users: settings.max_users ?? 1,
        receipt_format: settings.receipt_format ?? 'ticket',
        use_branches: settings.use_branches ?? false,
        products_shared_across_branches: settings.products_shared_across_branches ?? true,
        pricing_scope: settings.pricing_scope ?? 'global',
        allow_manual_price: settings.allow_manual_price ?? false,
        manual_price_min_margin_percent: settings.manual_price_min_margin_percent ?? 0,
        remember_last_customer_product_price: settings.remember_last_customer_product_price ?? false,
        enable_credit_sales: settings.enable_credit_sales ?? false,
        allow_receipts: settings.allow_receipts ?? true,
        allow_invoices: settings.allow_invoices ?? false,
        owner_name: '',
        owner_email: '',
        owner_password: '',
        fel_enabled: felSettings.enabled ?? false,
        fel_environment: felSettings.environment ?? 'test',
        fel_issuer_tax_id: felSettings.issuer_tax_id ?? '',
        fel_username: felSettings.username ?? '',
        fel_password: '',
        fel_test_base_url: felSettings.test_base_url ?? 'https://testnucgt.digifact.com/api',
        fel_production_base_url: felSettings.production_base_url ?? 'https://nucgt.digifact.com/gt.com.apinuc/api',
        fel_affiliate_type: felSettings.affiliate_type ?? '',
        fel_certifier_tax_id: felSettings.certifier_tax_id ?? '',
        fel_phrases: felSettings.phrases?.length ? felSettings.phrases : [defaultPhrase()],
        modules: enabledModules,
        _method: tenant ? 'put' : 'post',
        logo: null as File | null,
        remove_logo: false,
    });
    const [logoPreview, setLogoPreview] = useState<string | null>(tenant?.logo_url ?? null);

    function submit(event: FormEvent) {
        event.preventDefault();

        if (tenant) {
            post(route('super-admin.tenants.update', tenant.id), { forceFormData: true });
            return;
        }

        post(route('super-admin.tenants.store'), { forceFormData: true });
    }

    function updatePhrase(index: number, field: keyof FelPhrase, value: string) {
        setData('fel_phrases', data.fel_phrases.map((phrase, phraseIndex) => (
            phraseIndex === index ? { ...phrase, [field]: value } : phrase
        )));
    }

    function addPhrase() {
        setData('fel_phrases', [...data.fel_phrases, defaultPhrase()]);
    }

    function removePhrase(index: number) {
        const next = data.fel_phrases.filter((_, phraseIndex) => phraseIndex !== index);
        setData('fel_phrases', next.length ? next : [defaultPhrase()]);
    }

    function toggleModule(module: string, checked: boolean) {
        setData('modules', checked
            ? Array.from(new Set([...data.modules, module]))
            : data.modules.filter((item) => item !== module));
    }

    function testFelConnection() {
        if (!tenant) {
            return;
        }

        router.post(route('super-admin.tenants.fel.test-connection', tenant.id), {}, {
            preserveScroll: true,
        });
    }

    const formErrors = errors as Record<string, string>;

    return (
        <SuperAdminLayout title={editing ? 'Editar negocio' : 'Crear negocio'}>
            <form onSubmit={submit} className="space-y-6">
                <section className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div className="mb-5">
                        <h2 className="text-xl font-semibold text-gray-900">
                            {editing ? 'Datos del negocio' : 'Nuevo negocio'}
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            Configuración principal del tenant y sus permisos básicos.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="Nombre" error={errors.name}>
                            <TextInput
                                className={inputClass}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                        </Field>
                        <Field label="Slug" error={errors.slug}>
                            <TextInput
                                className={inputClass}
                                value={data.slug}
                                onChange={(e) => setData('slug', e.target.value)}
                            />
                        </Field>
                        <Field label="País" error={errors.country}>
                            <select
                                className={inputClass}
                                value={data.country}
                                onChange={(e) => setData('country', e.target.value)}
                            >
                                {countryOptions.map((country) => (
                                    <option key={country.value} value={country.value}>
                                        {country.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Moneda</label>
                            <div className="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-700">
                                {countryOptions.find((country) => country.value === data.country)?.symbol ?? 'Q'}
                            </div>
                        </div>
                        <Field label="Teléfono" error={errors.phone}>
                            <TextInput
                                className={inputClass}
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                            />
                        </Field>
                        <Field label="Email" error={errors.email}>
                            <TextInput
                                className={inputClass}
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                        </Field>
                        <Field label="Usuarios permitidos" error={errors.max_users}>
                            <TextInput
                                className={inputClass}
                                type="number"
                                min="1"
                                value={data.max_users}
                                onChange={(e) => setData('max_users', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Formato de comprobante" error={errors.receipt_format}>
                            <select
                                className={inputClass}
                                value={data.receipt_format}
                                onChange={(e) => setData('receipt_format', e.target.value as 'ticket' | 'document')}
                            >
                                <option value="ticket">Ticket</option>
                                <option value="document">Documento</option>
                            </select>
                        </Field>
                    </div>

                    <div className="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <Toggle
                            checked={data.is_active}
                            onChange={(checked) => setData('is_active', checked)}
                            label="Activa"
                        />
                        <Toggle
                            checked={data.use_product_images}
                            onChange={(checked) => setData('use_product_images', checked)}
                            label="Imágenes de productos"
                        />
                    </div>

                    <div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div className="mb-3">
                            <h3 className="text-base font-semibold text-gray-900">Logo del tenant</h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Se usa en comprobantes y facturas impresas cuando la sucursal no tiene logo propio.
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-4">
                            {logoPreview ? (
                                <img src={logoPreview} alt="Logo" className="h-20 w-20 rounded-lg border border-slate-200 bg-white object-contain p-2" />
                            ) : (
                                <div className="flex h-20 w-20 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white text-xs font-semibold text-slate-400">
                                    Sin logo
                                </div>
                            )}
                            <div className="space-y-2">
                                <input
                                    type="file"
                                    accept="image/*"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0] ?? null;
                                        setData('logo', file);
                                        setData('remove_logo', false);
                                        setLogoPreview(file ? URL.createObjectURL(file) : tenant?.logo_url ?? null);
                                    }}
                                    className="block text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-indigo-700"
                                />
                                {logoPreview && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('logo', null);
                                            setData('remove_logo', true);
                                            setLogoPreview(null);
                                        }}
                                        className="text-sm font-semibold text-red-600 hover:text-red-700"
                                    >
                                        Quitar logo
                                    </button>
                                )}
                                <InputError message={(errors as Record<string, string>).logo} />
                            </div>
                        </div>
                    </div>

                    <div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div className="mb-4">
                            <h3 className="text-base font-semibold text-gray-900">Documentos de venta</h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Define los tipos de documento disponibles en POS. Debe quedar al menos uno habilitado.
                            </p>
                        </div>
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <Toggle
                                checked={data.allow_receipts}
                                onChange={(checked) => setData('allow_receipts', checked)}
                                label="Permitir comprobantes"
                            />
                            <Toggle
                                checked={data.allow_invoices}
                                onChange={(checked) => setData('allow_invoices', checked)}
                                label="Permitir facturas FEL"
                            />
                        </div>
                        <InputError message={errors.allow_receipts || errors.allow_invoices} className="mt-2" />
                        {data.allow_invoices && (
                            <p className="mt-3 text-xs text-slate-500">
                                Requiere Guatemala, módulo FEL Guatemala y configuración Digifact activa y completa.
                            </p>
                        )}
                    </div>
                </section>

                <section className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div className="mb-5">
                        <h2 className="text-xl font-semibold text-gray-900">Módulos</h2>
                        <p className="mt-1 text-sm text-gray-500">
                            Activa o desactiva funcionalidades para este tenant desde una sola app compartida.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {availableModules.map((module) => (
                            <label key={module.key} className="flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.modules.includes(module.key)}
                                    onChange={(event) => toggleModule(module.key, event.target.checked)}
                                    className="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <span>
                                    <span className="block font-semibold text-gray-900">{module.name}</span>
                                    <span className="mt-0.5 block text-xs font-semibold uppercase tracking-wide text-indigo-600">{module.group}</span>
                                    <span className="mt-1 block text-gray-500">{module.description}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <InputError message={formErrors.modules} className="mt-2" />

                    {data.modules.includes('credits') && (
                        <div className="mt-6 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4">
                            <div className="mb-4">
                                <h3 className="text-base font-semibold text-gray-900">Configuración de créditos</h3>
                                <p className="mt-1 text-sm text-gray-500">
                                    Activa la reserva de productos a crédito en POS para este tenant.
                                </p>
                            </div>
                            <Toggle
                                checked={data.enable_credit_sales}
                                onChange={(checked) => setData('enable_credit_sales', checked)}
                                label="Habilitar ventas a crédito"
                            />
                        </div>
                    )}

                    {data.modules.includes('branches') && (
                        <div className="mt-6 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4">
                            <div className="mb-4">
                                <h3 className="text-base font-semibold text-gray-900">Configuración de sucursales</h3>
                                <p className="mt-1 text-sm text-gray-500">
                                    Define si el tenant usa sucursales, comparte catálogo y maneja precios globales o por sucursal.
                                </p>
                            </div>
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <Toggle
                                    checked={data.use_branches}
                                    onChange={(checked) => setData('use_branches', checked)}
                                    label="Usar sucursales"
                                />
                                <Toggle
                                    checked={data.products_shared_across_branches}
                                    onChange={(checked) => setData('products_shared_across_branches', checked)}
                                    label="Productos compartidos entre sucursales"
                                />
                                <Field label="Precios" error={errors.pricing_scope}>
                                    <select
                                        className={inputClass}
                                        value={data.pricing_scope}
                                        onChange={(e) => setData('pricing_scope', e.target.value as 'global' | 'branch')}
                                    >
                                        <option value="global">Globales</option>
                                        <option value="branch">Por sucursal</option>
                                    </select>
                                </Field>
                            </div>
                        </div>
                    )}

                    <div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div className="mb-4">
                            <h3 className="text-base font-semibold text-gray-900">Precios en POS</h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Controla si se permiten precios manuales y si el POS recuerda el último precio por cliente y producto.
                            </p>
                        </div>
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <Toggle
                                checked={data.allow_manual_price}
                                onChange={(checked) => setData('allow_manual_price', checked)}
                                label="Permitir precio manual"
                            />
                            <Field label="Margen mínimo sobre costo para precio manual" error={errors.manual_price_min_margin_percent}>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={data.manual_price_min_margin_percent}
                                    onChange={(event) => setData('manual_price_min_margin_percent', event.target.value)}
                                    className={inputClass}
                                />
                            </Field>
                            <Toggle
                                checked={data.remember_last_customer_product_price}
                                onChange={(checked) => setData('remember_last_customer_product_price', checked)}
                                label="Recordar último precio por cliente y producto"
                            />
                        </div>
                    </div>
                </section>

                {data.country === 'GT' && (
                    <section className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-semibold text-gray-900">Facturación electrónica FEL</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Configuración Digifact por tenant. Las credenciales no son visibles para usuarios del tenant.
                                </p>
                            </div>
                            <FelEnabledSwitch
                                checked={data.fel_enabled}
                                onChange={(checked) => setData('fel_enabled', checked)}
                            />
                        </div>

                        {(felSettings.last_successful_connection_at || felSettings.last_error || formErrors.fel_connection) && (
                            <div className="mb-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                                {felSettings.last_successful_connection_at && (
                                    <div>Última conexión exitosa: {felSettings.last_successful_connection_at}</div>
                                )}
                                {(formErrors.fel_connection || felSettings.last_error) && (
                                    <div className="mt-1 font-semibold text-red-600">
                                        {formErrors.fel_connection || felSettings.last_error}
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="Ambiente" error={errors.fel_environment}>
                                <select
                                    className={inputClass}
                                    value={data.fel_environment}
                                    onChange={(e) => setData('fel_environment', e.target.value as 'test' | 'production')}
                                >
                                    <option value="test">Test</option>
                                    <option value="production">Producción</option>
                                </select>
                            </Field>
                            <Field label="NIT emisor" error={errors.fel_issuer_tax_id}>
                                <TextInput className={inputClass} value={data.fel_issuer_tax_id} onChange={(e) => setData('fel_issuer_tax_id', e.target.value)} />
                            </Field>
                            <Field label="Usuario Digifact" error={errors.fel_username}>
                                <TextInput className={inputClass} value={data.fel_username} onChange={(e) => setData('fel_username', e.target.value)} />
                            </Field>
                            <Field label="Password Digifact" error={errors.fel_password}>
                                <TextInput className={inputClass} type="password" value={data.fel_password} onChange={(e) => setData('fel_password', e.target.value)} />
                                <p className="mt-1 text-xs text-gray-500">Se actualiza solo si ingresas un nuevo password.</p>
                            </Field>
                            <Field label="URL Test" error={errors.fel_test_base_url}>
                                <TextInput className={inputClass} value={data.fel_test_base_url} onChange={(e) => setData('fel_test_base_url', e.target.value)} />
                            </Field>
                            <Field label="URL Producción" error={errors.fel_production_base_url}>
                                <TextInput className={inputClass} value={data.fel_production_base_url} onChange={(e) => setData('fel_production_base_url', e.target.value)} />
                            </Field>
                            <Field label="Afiliación IVA" error={errors.fel_affiliate_type}>
                                <TextInput className={inputClass} value={data.fel_affiliate_type} onChange={(e) => setData('fel_affiliate_type', e.target.value)} />
                            </Field>
                            <Field label="NIT certificador" error={errors.fel_certifier_tax_id}>
                                <TextInput className={inputClass} value={data.fel_certifier_tax_id} onChange={(e) => setData('fel_certifier_tax_id', e.target.value)} />
                            </Field>
                        </div>

                        <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <p>
                                Los datos del establecimiento FEL se configuran en la sucursal. Si la empresa no usa sucursales, se utiliza la Sucursal Principal.
                            </p>
                            {tenant && (
                                <Link
                                    href={route('super-admin.tenants.branches', tenant.id)}
                                    className="mt-3 inline-flex rounded-md border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100"
                                >
                                    Configurar establecimiento FEL
                                </Link>
                            )}
                        </div>

                        <div className="mt-6">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <h3 className="text-base font-semibold text-gray-900">Frases FEL</h3>
                                    <p className="text-sm text-gray-500">Agrega las combinaciones TipoFrase/Escenario requeridas por SAT.</p>
                                </div>
                                <button type="button" onClick={addPhrase} className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-100">
                                    Agregar frase
                                </button>
                            </div>

                            <div className="space-y-3">
                                {data.fel_phrases.map((phrase, index) => (
                                    <div key={index} className="grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 md:grid-cols-6">
                                        <Field label="Identificador Data" error={formErrors[`fel_phrases.${index}.data_identifier`]}>
                                            <TextInput className={inputClass} value={phrase.data_identifier} onChange={(e) => updatePhrase(index, 'data_identifier', e.target.value)} />
                                        </Field>
                                        <Field label="TipoFrase Value" error={formErrors[`fel_phrases.${index}.phrase_type`]}>
                                            <TextInput className={inputClass} value={phrase.phrase_type} onChange={(e) => updatePhrase(index, 'phrase_type', e.target.value)} />
                                        </Field>
                                        <Field label="Escenario Value" error={formErrors[`fel_phrases.${index}.scenario_code`]}>
                                            <TextInput className={inputClass} value={phrase.scenario_code} onChange={(e) => updatePhrase(index, 'scenario_code', e.target.value)} />
                                        </Field>
                                        <Field label="Número resolución" error={formErrors[`fel_phrases.${index}.resolution_number`]}>
                                            <TextInput className={inputClass} value={phrase.resolution_number ?? ''} onChange={(e) => updatePhrase(index, 'resolution_number', e.target.value)} />
                                        </Field>
                                        <Field label="Fecha resolución" error={formErrors[`fel_phrases.${index}.resolution_date`]}>
                                            <TextInput className={inputClass} type="date" value={phrase.resolution_date ?? ''} onChange={(e) => updatePhrase(index, 'resolution_date', e.target.value)} />
                                        </Field>
                                        <div className="flex items-end">
                                            <button type="button" onClick={() => removePhrase(index)} className="rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
                                                Eliminar
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {tenant && (
                            <div className="mt-5 flex justify-end gap-3">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    Guardar configuración FEL
                                </button>
                                <button
                                    type="button"
                                    onClick={testFelConnection}
                                    className="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                                >
                                    Probar conexión
                                </button>
                            </div>
                        )}
                    </section>
                )}

                {!editing && (
                    <section className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="mb-5">
                            <h2 className="text-xl font-semibold text-gray-900">Usuario dueño opcional</h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Completa estos campos solo si quieres crear el primer usuario del negocio.
                            </p>
                        </div>

                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <Field label="Nombre" error={errors.owner_name}>
                                <TextInput
                                    className={inputClass}
                                    value={data.owner_name}
                                    onChange={(e) => setData('owner_name', e.target.value)}
                                />
                            </Field>
                            <Field label="Email" error={errors.owner_email}>
                                <TextInput
                                    className={inputClass}
                                    value={data.owner_email}
                                    onChange={(e) => setData('owner_email', e.target.value)}
                                />
                            </Field>
                            <Field label="Contraseña" error={errors.owner_password}>
                                <TextInput
                                    className={inputClass}
                                    type="password"
                                    value={data.owner_password}
                                    onChange={(e) => setData('owner_password', e.target.value)}
                                />
                            </Field>
                        </div>
                    </section>
                )}

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                        Guardar
                    </button>
                    <Link
                        href={route('super-admin.tenants.index')}
                        className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                    >
                        Volver
                    </Link>
                    {tenant && (
                        <Link
                            href={route('super-admin.tenants.branches', tenant.id)}
                            className="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                        >
                            Gestionar sucursales
                        </Link>
                    )}
                </div>
            </form>
        </SuperAdminLayout>
    );
}

const inputClass =
    'w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500';

function defaultPhrase(): FelPhrase {
    return {
        data_identifier: '1',
        phrase_type: '1',
        scenario_code: '2',
        resolution_number: null,
        resolution_date: null,
    };
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div>
            <label className="text-sm font-medium text-gray-700">{label}</label>
            <div className="mt-1">{children}</div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function Toggle({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
}) {
    return (
        <label className="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700">
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            {label}
        </label>
    );
}

function FelEnabledSwitch({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex min-w-56 items-center justify-between gap-4 rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-3">
            <span>
                <span className="block text-sm font-semibold text-gray-900">Habilitar FEL</span>
                <span className="block text-xs text-gray-500">
                    {checked ? 'Activo para este tenant' : 'Desactivado'}
                </span>
            </span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label="Habilitar FEL"
                onClick={() => onChange(!checked)}
                className={[
                    'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                    checked ? 'bg-indigo-600' : 'bg-gray-300',
                ].join(' ')}
            >
                <span
                    className={[
                        'mt-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform',
                        checked ? 'translate-x-5' : 'translate-x-0.5',
                    ].join(' ')}
                />
            </button>
        </div>
    );
}
