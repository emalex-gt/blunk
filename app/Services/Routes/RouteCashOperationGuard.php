<?php

namespace App\Services\Routes;

use App\Models\CashRegisterSession;
use App\Support\CashRegister;

class RouteCashOperationGuard
{
    public const MESSAGE = 'No hay caja abierta para operar rutas y registrar comprobantes.';

    public function status(int $businessId, int $branchId): array
    {
        $session = CashRegister::currentOpenSession($businessId, false, $branchId);

        return ['is_open' => $session !== null, 'session_id' => $session?->id];
    }

    public function requireOpen(int $businessId, int $branchId, bool $lock = false): CashRegisterSession
    {
        return CashRegister::requireOpenSession($businessId, self::MESSAGE, $lock, $branchId);
    }
}
