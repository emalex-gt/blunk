import { departments, municipalitiesByDepartment } from '@/Data/guatemalaLocations';

type Props = {
    department: string;
    municipality: string;
    onDepartmentChange: (value: string) => void;
    onMunicipalityChange: (value: string) => void;
    departmentError?: string;
    municipalityError?: string;
    disabled?: boolean;
    compact?: boolean;
};

export default function GuatemalaLocationSelects({
    department,
    municipality,
    onDepartmentChange,
    onMunicipalityChange,
    departmentError,
    municipalityError,
    disabled = false,
    compact = false,
}: Props) {
    const municipalities = municipalitiesByDepartment[department] ?? [];
    const inputClass = [
        'w-full rounded-xl border-slate-200 bg-white text-slate-900 shadow-sm focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100 disabled:bg-slate-100',
        compact ? 'h-10 text-sm' : 'text-base',
    ].join(' ');

    return (
        <>
            <label className="block text-sm font-medium text-slate-700">
                Departamento
                <select
                    value={department}
                    disabled={disabled}
                    onChange={(event) => {
                        onDepartmentChange(event.target.value);
                        if (!municipalitiesByDepartment[event.target.value]?.includes(municipality)) {
                            onMunicipalityChange('');
                        }
                    }}
                    className={`mt-1 ${inputClass}`}
                >
                    <option value="">Seleccionar departamento</option>
                    {departments.map((item) => (
                        <option key={item} value={item}>{item}</option>
                    ))}
                </select>
                {departmentError && <span className="mt-1 block text-xs text-red-600">{departmentError}</span>}
            </label>

            <label className="block text-sm font-medium text-slate-700">
                Municipio
                <select
                    value={municipality}
                    disabled={disabled || !department}
                    onChange={(event) => onMunicipalityChange(event.target.value)}
                    className={`mt-1 ${inputClass}`}
                >
                    <option value="">Seleccionar municipio</option>
                    {municipalities.map((item) => (
                        <option key={item} value={item}>{item}</option>
                    ))}
                </select>
                {municipalityError && <span className="mt-1 block text-xs text-red-600">{municipalityError}</span>}
            </label>
        </>
    );
}
