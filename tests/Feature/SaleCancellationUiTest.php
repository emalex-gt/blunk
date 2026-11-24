<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaleCancellationUiTest extends TestCase
{
    public function test_sale_cancel_ui_prioritizes_the_specific_server_error_over_a_permission_fallback(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Sales/Show.tsx'));

        $this->assertStringContainsString("errors.sale ?? errors.reason ?? Object.values(errors)[0] ?? 'No se pudo anular la venta.'", $source);
        $this->assertStringNotContainsString("errors.reason ?? 'No tienes permisos para anular esta venta.'", $source);
    }
}
