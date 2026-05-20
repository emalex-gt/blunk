<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantFelSetting extends Model
{
    protected $fillable = [
        'business_id',
        'provider',
        'environment',
        'enabled',
        'issuer_tax_id',
        'username',
        'password',
        'token',
        'token_expires_at',
        'test_base_url',
        'production_base_url',
        'establishment_code',
        'establishment_name',
        'establishment_address',
        'establishment_postal_code',
        'establishment_municipality',
        'establishment_department',
        'establishment_country',
        'affiliate_type',
        'phrase_type',
        'phrase_scenario',
        'certifier_tax_id',
        'certificate_path',
        'certificate_password',
        'last_successful_connection_at',
        'last_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'password' => 'encrypted',
        'token' => 'encrypted',
        'certificate_password' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_successful_connection_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'token',
        'certificate_password',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function phrases(): HasMany
    {
        return $this->hasMany(TenantFelPhrase::class);
    }

    public function baseUrl(): ?string
    {
        return $this->environment === 'production'
            ? $this->production_base_url
            : $this->test_base_url;
    }

    public function missingConfigurationFields(): array
    {
        $missing = [];

        if (! $this->enabled) {
            $missing[] = 'habilitar FEL';
        }

        if ($this->provider !== 'digifact') {
            $missing[] = 'proveedor Digifact';
        }

        if (! in_array($this->environment, ['test', 'production'], true)) {
            $missing[] = 'ambiente';
        }

        if (! filled($this->issuer_tax_id)) {
            $missing[] = 'NIT emisor';
        }

        if (! filled($this->username)) {
            $missing[] = 'usuario Digifact';
        }

        if (! filled($this->password)) {
            $missing[] = 'password';
        }

        if (! filled($this->establishment_code)) {
            $missing[] = 'codigo establecimiento';
        }

        if (! filled($this->affiliate_type)) {
            $missing[] = 'afiliacion IVA';
        }

        if ($this->exists && $this->phrases()->count() === 0) {
            $missing[] = 'frases FEL';
        }

        if ($this->environment === 'production') {
            if (! filled($this->production_base_url)) {
                $missing[] = 'URL Produccion';
            }
        } elseif (! filled($this->test_base_url)) {
            $missing[] = 'URL Test';
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfigurationFields() === [];
    }

    public function configurationErrorMessage(): string
    {
        $missing = $this->missingConfigurationFields();

        return $missing === []
            ? ''
            : 'FEL no configurada: falta '.implode(', ', $missing).'.';
    }
}
