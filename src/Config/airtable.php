<?php

return [
    "api_key" => getenv("AIRTABLE_API_KEY") ?: '',
    "base_id" => getenv("AIRTABLE_BASE_ID") ?: '',
    "tables" => [
        "empresas" => "Empresas"
    ]
];
?>
