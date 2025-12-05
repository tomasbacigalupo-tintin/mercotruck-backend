<?php
/**
 * Configuración Mercotruck Backend
 * Multi-empresa en mismo Odoo: AR (ID 1) y CL (ID 2)
 * 
 * IMPORTANTE: Configurar variables de entorno o .env antes de usar
 */
return [
    // Odoo - mismo servidor, diferentes empresas
    "odoo_ar" => [
        "url"        => getenv('ODOO_URL') ?: '',
        "db"         => getenv('ODOO_DB') ?: '',
        "username"   => getenv('ODOO_USERNAME') ?: '',
        "password"   => getenv('ODOO_PASSWORD') ?: '',
        "company_id" => 1,  // Mercotruck AR
        "currency"   => "ARS",
        "country"    => "AR"
    ],

    "odoo_cl" => [
        "url"        => getenv('ODOO_URL') ?: '',
        "db"         => getenv('ODOO_DB') ?: '',
        "username"   => getenv('ODOO_USERNAME') ?: '',
        "password"   => getenv('ODOO_PASSWORD') ?: '',
        "company_id" => 2,  // Mercotruck CL
        "currency"   => "USD",
        "country"    => "CL"
    ],

    // Airtable
    "airtable" => [
        "api_key" => getenv('AIRTABLE_API_KEY') ?: '',
        "base_id" => getenv('AIRTABLE_BASE_ID') ?: '',
        "tables" => [
            "empresas"    => "Empresas",
            "masters"     => "Masters",
            "operaciones" => "tblV9e6v8lhdMCqUG",
            "tarjetas"    => "tblgNDyHnuG4pppWY",
            "fleteros"    => "Fleteros",
            "camiones"    => "Camiones"
        ]
    ],

    // Productos de servicio en Odoo (IDs por país)
    "productos" => [
        "transporte" => [
            "ar" => 2,
            "cl" => 2
        ],
        "flete_subcontratado" => [
            "ar" => 3,
            "cl" => 3
        ],
        "estadia" => [
            "ar" => 4,
            "cl" => 4
        ]
    ],

    // Journals (diarios) por país
    "journals" => [
        "ar" => [
            "ventas"  => 1,
            "compras" => 2
        ],
        "cl" => [
            "ventas"  => 1,
            "compras" => 2
        ]
    ],

    // Mapeo de campos Airtable
    "field_mapping" => [
        "empresas" => [
            "nombre"          => "Nombre",
            "razon_social"    => "Razón Social",
            "cuit_rut"        => "CUIT/RUT",
            "pais"            => "País",
            "tipo"            => "Tipo",
            "email"           => "Email",
            "odoo_partner_ar" => "odoo_partner_id_ar",
            "odoo_partner_cl" => "odoo_partner_id_cl"
        ],
        "masters" => [
            "id"                   => "Master",
            "estado"               => "Estado",
            "cliente"              => "Cliente",
            "origen"               => "Origen",
            "destino"              => "Destino",
            "operaciones"          => "Operaciones / Órdenes de Viaje 2",
            "company_venta"        => "company_venta",
            "odoo_analytic_ar"     => "odoo_analytic_id_master_ar",
            "odoo_analytic_cl"     => "odoo_analytic_id_master_cl",
            "odoo_invoice_ar"      => "odoo_invoice_id_ar",
            "odoo_invoice_cl"      => "odoo_invoice_id_cl",
            "numero_factura"       => "numero_factura_master",
            "estado_contable"      => "estado_contable_master"
        ],
        "operaciones" => [
            "id"                   => "Nombre de Operación",
            "tarifa_venta"         => "Tarifa de Venta",
            "tarifa_compra"        => "Tarifa de Compra",
            "fleteros"             => "Fleteros",
            "camiones"             => "Camiones",
            "master"               => "Master",
            "company_cost"         => "company_cost",
            "odoo_analytic_ar"     => "odoo_analytic_id_operacion_ar",
            "odoo_analytic_cl"     => "odoo_analytic_id_operacion_cl",
            "odoo_purchase_id"     => "odoo_purchase_invoice_id",
            "estado_contable"      => "estado_contable_operacion"
        ]
    ]
];
