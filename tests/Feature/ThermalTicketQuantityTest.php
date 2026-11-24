<?php

namespace Tests\Feature;

use Tests\TestCase;

class ThermalTicketQuantityTest extends TestCase
{
    public function test_thermal_sale_tickets_keep_fractional_quantities_together(): void
    {
        $styles = file_get_contents(resource_path('views/print/partials/ticket-styles.blade.php'));
        $receiptTicket = file_get_contents(resource_path('views/sales/receipt-ticket.blade.php'));
        $felTicket = file_get_contents(resource_path('views/sales/fel-ticket.blade.php'));

        $this->assertStringContainsString('.ticket-quantity', $styles);
        $this->assertStringContainsString('white-space: nowrap', $styles);
        $this->assertStringContainsString('ticket-quantity', $receiptTicket);
        $this->assertStringContainsString('ticket-quantity', $felTicket);
    }
}
