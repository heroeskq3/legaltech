<?php
// =====================================
// API REST Jurisprudencia CR
// =====================================
// Dependencias: NINGUNA (solo PHP nativo)
// =====================================

// Cabeceras
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

// -----------------------------------------------------------------------------
// FUNCIONES
// -----------------------------------------------------------------------------

function obtenerIPconProxy($proxy, $proxyAuth) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.ipify.org?format=json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROXY => $proxy,
        CURLOPT_PROXYUSERPWD => $proxyAuth,
        CURLOPT_HTTPPROXYTUNNEL => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['ip'] ?? null;
}

function ask_nexusPJv2(string $pregunta, int $page = 1, int $size = 10, array $options = []): ?array {
    $endpoint   = 'https://nexuspj.poder-judicial.go.cr/api/search';
    $timeout    = (int)($options['timeout'] ?? 30);
    $cookies    = (string)($options['cookies'] ?? '');
    $origin     = (string)($options['origin'] ?? 'https://nexuspj.poder-judicial.go.cr');
    $referer    = (string)($options['referer'] ?? 'https://nexuspj.poder-judicial.go.cr/search');
    $userAgent  = (string)($options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139 Safari/537.36');

    $payload = [
        'q'        => $pregunta,
        'nq'       => (string)($options['nq'] ?? ''),
        'advanced' => (bool)  ($options['advanced'] ?? false),
        'facets'   => (array) ($options['facets'] ?? []),
        'size'     => (int)   $size,
        'page'     => (int)   $page,
        'exp'      => (string)($options['exp'] ?? ''),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $headers = [
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/json;charset=UTF-8',
        "Origin: {$origin}",
        "Referer: {$referer}",
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',  // gzip/deflate
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
    ]);

    // Proxy opcional
    if (!empty($options['proxy']) && !empty($options['proxy_auth'])) {
        curl_setopt($ch, CURLOPT_PROXY, $options['proxy']);
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $options['proxy_auth']);
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
    }

    // Cookies opcionales
    if ($cookies !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => "Error CURL: $curlError", 'http_code' => $httpCode];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return ['error' => "JSON inválido: " . json_last_error_msg(), 'raw' => $response];
    }

    return $data;
}

// -----------------------------------------------------------------------------
// API ROUTER
// -----------------------------------------------------------------------------

// Soporte GET / POST JSON
$request = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $request = $_GET;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $request = $json;
    }
}

$q     = $request['q'] ?? null;
$page  = (int)($request['page'] ?? 1);
$size  = (int)($request['size'] ?? 10);

if (empty($q)) {
    http_response_code(400);
    echo json_encode(['error' => 'Debe enviar parámetro q (consulta).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$options = [
    'nq'       => $request['nq'] ?? '',
    'advanced' => filter_var($request['advanced'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'facets'   => $request['facets'] ?? [],
    'exp'      => $request['exp'] ?? '',
    'cookies'  => $request['cookies'] ?? '',
];

$result = ask_nexusPJv2($q, $page, $size, $options);

// Respuesta
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
