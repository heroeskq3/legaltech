<?php
// =====================================
// API REST Jurisprudencia CR (con coincidencias y salida estructurada)
// =====================================

// Cabeceras
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

// -----------------------------------------------------------------------------
// FUNCIONES
// -----------------------------------------------------------------------------

function ask_nexusPJv2(string $pregunta, int $page = 1, int $size = 10, array $options = []): ?array {
    $endpoint   = 'https://nexuspj.poder-judicial.go.cr/api/search';
    $timeout    = (int)($options['timeout'] ?? 30);

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
        'Origin: https://nexuspj.poder-judicial.go.cr',
        'Referer: https://nexuspj.poder-judicial.go.cr/search',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => "Error CURL: $curlError", 'http_code' => $httpCode];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => "JSON inválido: " . json_last_error_msg(), 'raw' => $response];
    }

    return $data;
}

function contarCoincidencias($texto, $palabras) {
    $count = 0;
    $texto = strtolower($texto);
    foreach ($palabras as $p) {
        if (empty($p)) continue;
        $count += substr_count($texto, strtolower($p));
    }
    return $count;
}

function normalizarHit($hit, $keywords) {
    $contenido = strip_tags($hit['content'] ?? '');
    $coincidencias = contarCoincidencias($contenido, $keywords);

    $resumen = '';
    if (!empty($hit['highlight']['contenido'][0])) {
        $resumen = strip_tags($hit['highlight']['contenido'][0]);
    } else {
        $resumen = mb_substr($contenido, 0, 400) . "...";
    }

    return [
        "coincidencia" => $coincidencias,
        "url"          => isset($hit['idDocument']) ? "https://nexuspj.poder-judicial.go.cr/document/" . $hit['idDocument'] : null,
        "expediente"   => $hit['expediente'] ?? null,
        "numero"       => $hit['numeroDocumento'] ?? null,
        "anno"         => $hit['anno'] ?? null,
        "sala"         => $hit['despacho'] ?? null,
        "redactor"     => $hit['redactor'] ?? null,
        "tipo"         => $hit['tipoDocumento'] ?? null,
        "titulo"       => $hit['title'] ?? null,
        "resumen"      => $resumen,
        "descriptores" => $hit['descriptores'] ?? null,
        "restrictores" => $hit['restrictores'] ?? null,
        "temas"        => $hit['temasYSubtemas'] ?? [],
        "fecha"        => $hit['date'] ?? null
    ];
}

// -----------------------------------------------------------------------------
// API ROUTER
// -----------------------------------------------------------------------------

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

$facets = $request['facets'] ?? [];
if (is_string($facets)) {
    $decoded = json_decode($facets, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $facets = $decoded;
    }
}

$options = [
    'nq'       => $request['nq'] ?? '',
    'advanced' => filter_var($request['advanced'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'facets'   => $facets,
    'exp'      => $request['exp'] ?? '',
    'cookies'  => $request['cookies'] ?? '',
];

// Ejecuta consulta
$result = ask_nexusPJv2($q, $page, $size, $options);

if (isset($result['error'])) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Keywords (quitamos stopwords)
$stopwords = ["de","la","el","y","a","en","para","por","un","una","los","las","del","que"];
$keywords = array_values(array_diff(explode(" ", strtolower($q)), $stopwords));

// Usar directamente hits del Nexus
$hits = [];
if (!empty($result['hits'])) {
    foreach ($result['hits'] as $hit) {
        $hits[] = normalizarHit($hit, $keywords);
    }
}

// Ordenamos por coincidencia
usort($hits, function($a, $b) {
    return $b['coincidencia'] <=> $a['coincidencia'];
});

// Respuesta
$response = [
    "query"     => $q,
    "keywords"  => $keywords,
    "total"     => $result['total'] ?? count($hits),
    "hits"      => $hits
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
