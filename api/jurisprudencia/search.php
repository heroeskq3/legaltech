<?php
// =====================================
// API REST Jurisprudencia CR
// Coincidencias únicas + Tokens + Nivel de Consumo + Normativa citada
// Autor: Herbert Poveda (LegalTech CR)
// =====================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

// -----------------------------------------------------------------------------
// CONFIGURACIÓN
// -----------------------------------------------------------------------------
$TOKENS_PER_CHAR = 1 / 4; // Aproximación: 1 token = 4 caracteres
$TOKEN_COST_USD   = 0.00001; // Costo estimado por token (GPT-4-turbo)

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

/** ---------------- NORMALIZACIÓN Y ANÁLISIS ---------------- */

function normalizar($texto) {
    $texto = strtolower(strip_tags($texto));
    $texto = preg_replace('/[^a-záéíóúüñ0-9\s]/u', ' ', $texto);
    return preg_replace('/\s+/', ' ', trim($texto));
}

function limpiarStopwords($palabras) {
    $stopwords = ["de","la","el","y","a","en","para","por","un","una","los","las","del","que","con","se","su","al","lo","sus","es","como","o","u"];
    return array_values(array_diff($palabras, $stopwords));
}

function estimarTokens($texto) {
    global $TOKENS_PER_CHAR;
    $chars = mb_strlen($texto, 'UTF-8');
    return (int) round($chars * $TOKENS_PER_CHAR);
}

function clasificarNivelConsumo($tokens) {
    if ($tokens < 2000) return "bajo";
    if ($tokens < 10000) return "medio";
    if ($tokens < 30000) return "alto";
    if ($tokens < 50000) return "muy alto";
    return "crítico";
}

/** Detecta coincidencias únicas */
function detectarCoincidencias($texto, $palabras) {
    $textoNorm = normalizar($texto);
    $coinciden = [];
    foreach ($palabras as $p) {
        if (strlen($p) < 3) continue;
        if (strpos($textoNorm, $p) !== false) {
            $coinciden[] = $p;
        }
    }
    $total = count(array_unique($coinciden));
    return [
        'total' => $total,
        'palabras' => array_unique($coinciden),
        'relevancia' => $total > 0 ? round($total / count($palabras), 2) : 0.0
    ];
}

/** Extrae normativa citada */
function extraerNormativaCitada($texto) {
    $normas = [];
    preg_match_all('/(Ley\s+N\.?°?\s?\d+)|(Código\s+[A-ZÁÉÍÓÚa-záéíóú]+)|(Constitución\s+Política)|(Reglamento\s+[A-ZÁÉÍÓÚa-záéíóú]+)/u', $texto, $matches);
    foreach ($matches[0] as $m) {
        $normas[] = trim($m);
    }
    return array_values(array_unique($normas));
}

/** Normaliza un resultado del Nexus */
function normalizarHit($hit, $keywords) {
    $contenido = strip_tags($hit['content'] ?? '');
    $analisis = detectarCoincidencias($contenido, $keywords);
    $tokens = estimarTokens($contenido);
    $normativa = extraerNormativaCitada($contenido);

    $resumen = '';
    if (!empty($hit['highlight']['contenido'][0])) {
        $resumen = strip_tags($hit['highlight']['contenido'][0]);
    } else {
        $resumen = mb_substr($contenido, 0, 400) . "...";
    }

    return [
        "coincidencia" => $analisis['total'],
        "relevancia"   => $analisis['relevancia'],
        "palabras_coinciden" => $analisis['palabras'],
        "tokens_estimados" => $tokens,
        "nivel_consumo_tokens" => clasificarNivelConsumo($tokens),
        "costo_estimado_usd" => round($tokens * $GLOBALS['TOKEN_COST_USD'], 4),
        "normativa_citada" => $normativa,
        "url"          => isset($hit['idDocument']) ? "https://nexuspj.poder-judicial.go.cr/document/" . $hit['idDocument'] : null,
        "expediente"   => $hit['expediente'] ?? null,
        "numero"       => $hit['numeroDocumento'] ?? null,
        "anno"         => $hit['anno'] ?? null,
        "sala"         => $hit['despacho'] ?? null,
        "redactor"     => $hit['redactor'] ?? null,
        "tipo"         => $hit['tipoDocumento'] ?? null,
        "titulo"       => $hit['title'] ?? null,
        "resumen"      => $resumen,
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

$options = [
    'nq'       => $request['nq'] ?? '',
    'advanced' => filter_var($request['advanced'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'facets'   => [],
    'exp'      => $request['exp'] ?? '',
];

// Ejecuta consulta
$result = ask_nexusPJv2($q, $page, $size, $options);

if (isset($result['error'])) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Palabras clave
$keywords = limpiarStopwords(explode(" ", strtolower($q)));

// Procesar resultados
$hits = [];
$totalTokens = 0;
$totalCosto = 0;

if (!empty($result['hits'])) {
    foreach ($result['hits'] as $hit) {
        $h = normalizarHit($hit, $keywords);
        $totalTokens += $h['tokens_estimados'];
        $totalCosto += $h['costo_estimado_usd'];
        $hits[] = $h;
    }
}

// Ordenar por relevancia
usort($hits, fn($a, $b) => $b['relevancia'] <=> $a['relevancia']);

$nivelTotal = clasificarNivelConsumo($totalTokens);

// -----------------------------------------------------------------------------
// SALIDA FINAL
// -----------------------------------------------------------------------------
$response = [
    "query"                 => $q,
    "keywords"              => $keywords,
    "total_resultados"      => count($hits),
    "tokens_total_consulta" => $totalTokens,
    "nivel_consumo_total"   => $nivelTotal,
    "costo_estimado_usd"    => round($totalCosto, 4),
    "hits"                  => $hits
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
