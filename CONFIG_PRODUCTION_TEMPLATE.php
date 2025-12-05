<?php
/**
 * PLANTILLA DE CONFIGURACIÓN PARA PRODUCCIÓN
 * 
 * ADVERTENCIA: NO incluir credenciales reales en este archivo
 * Usar variables de entorno en producción
 */

return [
    // Odoo Argentina
    "odoo_ar" => [
        "url"        => getenv('ODOO_URL') ?: '',
        "db"         => getenv('ODOO_DB') ?: '',
        "username"   => getenv('ODOO_USERNAME') ?: '',
        "password"   => getenv('ODOO_PASSWORD') ?: '',
        "company_id" => 1,
        "currency"   => "ARS",
        "country"    => "AR"
    ],

    // Odoo Chile
    "odoo_cl" => [
        "url"        => getenv('ODOO_URL') ?: '',
        "db"         => getenv('ODOO_DB') ?: '',
        "username"   => getenv('ODOO_USERNAME') ?: '',
        "password"   => getenv('ODOO_PASSWORD') ?: '',
        "company_id" => 2,
        "currency"   => "USD",
        "country"    => "CL"
    ],

    // Airtable
    "airtable" => [
        "api_key" => getenv('AIRTABLE_API_KEY') ?: '',
        "base_id" => getenv('AIRTABLE_BASE_ID') ?: ''
    ]
];

/**
 * INSTRUCCIONES PARA CONFIGURACIÓN:
 * 
 * 1. Crear archivo .env en la raíz del proyecto:
 *    ODOO_URL=https://tu-odoo.com
 *    ODOO_DB=tu_db
 *    ODOO_USERNAME=usuario@email.com
 *    ODOO_PASSWORD=tu_password
 *    AIRTABLE_API_KEY=pat...
 *    AIRTABLE_BASE_ID=app...
 * 
 * 2. O configurar variables de entorno del servidor
 */
