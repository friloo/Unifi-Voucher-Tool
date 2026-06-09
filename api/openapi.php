<?php
/**
 * OpenAPI 3.0 Spezifikation der REST-API (zum Import in Postman/Swagger).
 * GET /api/openapi.php
 */
header('Content-Type: application/json; charset=utf-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$spec = [
    'openapi' => '3.0.3',
    'info' => [
        'title'       => 'UniFi Voucher Tool API',
        'version'     => '1.0.0',
        'description' => 'REST-API zum Erstellen und Abrufen von WLAN-Vouchers. Authentifizierung per API-Schlüssel (Authorization: Bearer … oder X-API-Key).',
    ],
    'servers' => [['url' => $base]],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            'apiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
        ],
    ],
    'security' => [['bearerAuth' => []], ['apiKeyAuth' => []]],
    'paths' => [
        '/sites.php' => [
            'get' => [
                'summary' => 'Aktive Sites auflisten',
                'description' => 'Erfordert Scope read.',
                'responses' => ['200' => ['description' => 'Liste der Sites']],
            ],
        ],
        '/vouchers.php' => [
            'get' => [
                'summary' => 'Voucher einer Site auflisten',
                'description' => 'Erfordert Scope read.',
                'parameters' => [[
                    'name' => 'site_id', 'in' => 'query', 'required' => true,
                    'schema' => ['type' => 'integer'],
                ]],
                'responses' => ['200' => ['description' => 'Liste der Voucher']],
            ],
            'post' => [
                'summary' => 'Voucher erstellen',
                'description' => 'Erfordert Scope write.',
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['site_id', 'name'],
                        'properties' => [
                            'site_id'        => ['type' => 'integer'],
                            'name'           => ['type' => 'string'],
                            'max_uses'       => ['type' => 'integer', 'default' => 1],
                            'expire_minutes' => ['type' => 'integer', 'default' => 480],
                            'qos' => ['type' => 'object', 'properties' => [
                                'down'     => ['type' => 'integer', 'description' => 'Download kbit/s'],
                                'up'       => ['type' => 'integer', 'description' => 'Upload kbit/s'],
                                'quota_mb' => ['type' => 'integer', 'description' => 'Datenkontingent MB'],
                            ]],
                        ],
                    ]]],
                ],
                'responses' => [
                    '201' => ['description' => 'Voucher erstellt'],
                    '401' => ['description' => 'Nicht authentifiziert'],
                    '403' => ['description' => 'Fehlender Scope'],
                    '429' => ['description' => 'Rate-Limit überschritten'],
                ],
            ],
        ],
    ],
];

echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
