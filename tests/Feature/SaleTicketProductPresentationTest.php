<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaleTicketProductPresentationTest extends TestCase
{
    public function test_ticket_identifiers_follow_the_enabled_settings_without_labels(): void
    {
        $codeOnly = $this->renderIdentifiers(showCode: true, showBarcode: false);
        $barcodeOnly = $this->renderIdentifiers(showCode: false, showBarcode: true);
        $both = $this->renderIdentifiers(showCode: true, showBarcode: true);
        $none = $this->renderIdentifiers(showCode: false, showBarcode: false);
        $missingBarcode = $this->renderIdentifiers(showCode: true, showBarcode: true, barcode: '');

        $this->assertStringContainsString('HARINA', $codeOnly);
        $this->assertStringNotContainsString('7501234567890', $codeOnly);
        $this->assertStringContainsString('7501234567890', $barcodeOnly);
        $this->assertStringNotContainsString('HARINA', $barcodeOnly);
        $this->assertStringNotContainsString('Código:', $both);
        $this->assertStringNotContainsString('Barras:', $both);
        $this->assertLessThan(strpos($both, '7501234567890'), strpos($both, 'HARINA'));
        $this->assertSame('', trim($none));
        $this->assertStringContainsString('HARINA', $missingBarcode);
        $this->assertStringNotContainsString('7501234567890', $missingBarcode);
    }

    public function test_sale_tickets_render_identifiers_before_the_full_product_name(): void
    {
        $receiptTicket = file_get_contents(resource_path('views/sales/receipt-ticket.blade.php'));
        $felTicket = file_get_contents(resource_path('views/sales/fel-ticket.blade.php'));

        $receiptIdentifiers = strpos($receiptTicket, "@include('print.partials.product-identifiers'");
        $receiptName = strpos($receiptTicket, '<div class="item-name">{{ $item->product_name }}</div>');
        $felIdentifiers = strpos($felTicket, "@include('print.partials.product-identifiers'");
        $felName = strpos($felTicket, '<div class="item-name">{{ $item->product_name }}</div>');

        $this->assertNotFalse($receiptIdentifiers);
        $this->assertNotFalse($receiptName);
        $this->assertNotFalse($felIdentifiers);
        $this->assertNotFalse($felName);
        $this->assertLessThan($receiptName, $receiptIdentifiers);
        $this->assertLessThan($felName, $felIdentifiers);
    }

    private function renderIdentifiers(bool $showCode, bool $showBarcode, string $barcode = '7501234567890'): string
    {
        return view('print.partials.product-identifiers', [
            'item' => (object) [
                'product' => (object) ['code' => 'HARINA', 'barcode' => $barcode],
            ],
            'settings' => (object) [
                'show_product_code_on_receipts' => $showCode,
                'show_product_barcode_on_receipts' => $showBarcode,
            ],
            'presentation' => 'ticket',
        ])->render();
    }
}
