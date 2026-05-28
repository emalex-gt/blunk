<?php

return [
    'pos' => [
        'name' => 'POS',
        'description' => 'Punto de venta y registro de ventas.',
        'group' => 'Basico',
        'plan_hint' => 'basic',
    ],
    'inventory' => [
        'name' => 'Inventario',
        'description' => 'Productos, stock e historial.',
        'group' => 'Basico',
        'plan_hint' => 'basic',
    ],
    'purchases' => [
        'name' => 'Compras',
        'description' => 'Registro de compras y proveedores.',
        'group' => 'Basico',
        'plan_hint' => 'basic',
    ],
    'cash_register' => [
        'name' => 'Caja',
        'description' => 'Aperturas, cierres, gastos y movimientos de caja.',
        'group' => 'Basico',
        'plan_hint' => 'basic',
    ],
    'customers' => [
        'name' => 'Clientes',
        'description' => 'Clientes y consultas fiscales.',
        'group' => 'Basico',
        'plan_hint' => 'basic',
    ],
    'reports' => [
        'name' => 'Reportes',
        'description' => 'Reportes operativos principales.',
        'group' => 'Basico',
        'plan_hint' => 'basic',
    ],
    'fel_gt' => [
        'name' => 'FEL Guatemala',
        'description' => 'Facturacion electronica Digifact Guatemala.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
    'discounts' => [
        'name' => 'Descuentos',
        'description' => 'Descuentos generales en ventas.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
    'credits' => [
        'name' => 'Créditos',
        'description' => 'Permite reservar productos a crédito y facturarlos posteriormente.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
    'branches' => [
        'name' => 'Sucursales',
        'description' => 'Permite manejar múltiples sucursales, traslados y precios por sucursal.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
    'routes' => [
        'name' => 'Rutas',
        'description' => 'Gestion futura de rutas.',
        'group' => 'Rutas',
        'plan_hint' => 'routes',
    ],
    'advanced_reports' => [
        'name' => 'Reportes avanzados',
        'description' => 'Analitica avanzada.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
    'multi_warehouse' => [
        'name' => 'Multi-bodega',
        'description' => 'Inventario por bodegas.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
    'expirations' => [
        'name' => 'Vencimientos',
        'description' => 'Control de lotes y vencimientos.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
    'users_limit' => [
        'name' => 'Limite de usuarios',
        'description' => 'Control de limites de usuarios por tenant.',
        'group' => 'Premium',
        'plan_hint' => 'premium',
    ],
];
