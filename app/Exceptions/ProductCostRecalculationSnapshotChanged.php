<?php

namespace App\Exceptions;

use RuntimeException;

class ProductCostRecalculationSnapshotChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Los costos o adquisiciones cambiaron desde la simulación. Genera una nueva simulación antes de aplicar.');
    }
}
