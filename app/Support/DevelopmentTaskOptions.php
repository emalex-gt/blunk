<?php

namespace App\Support;

final class DevelopmentTaskOptions
{
    public static function all(): array
    {
        return [
            'work_status' => ['pending' => 'Pendiente', 'in_progress' => 'En desarrollo', 'in_review' => 'En revisión', 'blocked' => 'Bloqueada', 'closed' => 'Cerrada'],
            'implementation_status' => ['not_started' => 'No iniciado', 'partial' => 'Parcial', 'implemented' => 'Implementado'],
            'production_status' => ['unverified' => 'Sin verificar', 'not_deployed' => 'No desplegado', 'deployed' => 'Desplegado', 'validated' => 'Validado'],
            'priority' => ['P0' => 'P0 — Crítica', 'P1' => 'P1 — Alta', 'P2' => 'P2 — Media', 'P3' => 'P3 — Baja'],
            'task_type' => ['feature' => 'Nueva funcionalidad', 'bug' => 'Corrección de error', 'audit' => 'Auditoría', 'improvement' => 'Mejora', 'infrastructure' => 'Infraestructura', 'migration' => 'Migración', 'verification' => 'Verificación'],
            'module' => array_combine($modules = ['POS y ventas', 'Compras', 'Inventario', 'Traslados', 'Rutas y preventas', 'Créditos y cuentas por cobrar', 'Caja', 'Facturación FEL', 'Dashboards', 'Reportes', 'Super Admin', 'Infraestructura', 'Migraciones', 'Asistente inteligente', 'Core y arquitectura', 'Otros'], $modules),
        ];
    }
}
