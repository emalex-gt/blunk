export type GuatemalaIdentityType = 'CF' | 'NIT' | 'CUI';

export default function GuatemalaIdentityTypeSelector({
    value,
    onChange,
    disabled = false,
    options = ['CF', 'NIT', 'CUI'],
}: {
    value: GuatemalaIdentityType;
    onChange: (type: GuatemalaIdentityType) => void;
    disabled?: boolean;
    options?: GuatemalaIdentityType[];
}) {
    return (
        <div aria-label="Tipo de documento" className="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
            {options.map((type) => (
                <button
                    key={type}
                    type="button"
                    disabled={disabled}
                    onClick={() => onChange(type)}
                    aria-pressed={value === type}
                    className={[
                        'rounded-lg px-3 py-1.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                        value === type
                            ? 'bg-orange-600 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-orange-50 hover:text-orange-700',
                    ].join(' ')}
                >
                    {type}
                </button>
            ))}
        </div>
    );
}
