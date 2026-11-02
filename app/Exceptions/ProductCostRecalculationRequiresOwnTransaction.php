<?php

namespace App\Exceptions;

use LogicException;

class ProductCostRecalculationRequiresOwnTransaction extends LogicException
{
    public function __construct()
    {
        parent::__construct('El recálculo de costos debe iniciarse fuera de una transacción existente para garantizar aislamiento SERIALIZABLE.');
    }
}
