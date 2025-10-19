<?php
// ==========================================================
// API LOCAL DE CONSULTA DE LEYES Y CÓDIGOS CR
// Pirámide de Kelsen + Coincidencias + Tokens + Detección de Ley y Artículo + Modo Compacto
// Autor: Herbert Poveda (LegalTech CR)
// ==========================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

$DATA_PATH = __DIR__ . '/../../uploads/leyes/vector_leyes.json';
$MAX_RESULTS_PER_LEVEL = 5;
$RESUMEN_LONG = 400;
$TOKENS_PER_CHAR = 1 / 4; // 1 token ≈ 4 caracteres

// ==========================================================
// FUNCIONES BASE
// ==========================================================
function normalizar($texto) {
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = preg_replace('/[^a-záéíóúüñ0-9\s]/u', ' ', $texto);
    return preg_replace('/\s+/', ' ', trim($texto));
}

function filtrarStopwords($palabras) {
    $stopwords = ["de","la","el","y","a","en","para","por","un","una","los","las","del","que","con","se","su","al","lo","sus","es","como","o","u"];
    return array_values(array_diff($palabras, $stopwords));
}

function analizarCoincidencias($texto, $palabras) {
    $textoNorm = normalizar($texto);
    $palabrasCoinciden = [];
    foreach ($palabras as $p) {
        if (strlen($p) < 3) continue;
        if (strpos($textoNorm, $p) !== false) $palabrasCoinciden[] = $p;
    }
    return [
        'total' => count(array_unique($palabrasCoinciden)),
        'palabras' => array_unique($palabrasCoinciden)
    ];
}

function crearResumen($texto, $long = 400) {
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    return (mb_strlen($texto, 'UTF-8') <= $long)
        ? $texto
        : mb_substr($texto, 0, $long, 'UTF-8') . '...';
}

function estimarTokens($texto) {
    global $TOKENS_PER_CHAR;
    return (int) round(mb_strlen($texto, 'UTF-8') * $TOKENS_PER_CHAR);
}

function nivelConsumoTokens($tokens) {
    if ($tokens <= 2000) return 'bajo';
    if ($tokens <= 10000) return 'medio';
    if ($tokens <= 30000) return 'alto';
    if ($tokens <= 50000) return 'muy alto';
    return 'crítico';
}

function agruparPorKelsen($resultados) {
    $grupos = [];
    foreach ($resultados as $r) {
        $nivel = $r['nivel_kelsen'] ?? 99;
        $nombre = $r['jerarquia_nombre'] ?? 'Desconocido';
        $key = "{$nivel}_{$nombre}";
        if (!isset($grupos[$key])) {
            $grupos[$key] = [
                'nivel_kelsen' => $nivel,
                'jerarquia_nombre' => $nombre,
                'resultados' => [],
                'tokens_total_nivel' => 0
            ];
        }
        $grupos[$key]['resultados'][] = $r;
        $grupos[$key]['tokens_total_nivel'] += $r['tokens_estimados'];
    }

    uasort($grupos, fn($a, $b) => $a['nivel_kelsen'] <=> $b['nivel_kelsen']);

    foreach ($grupos as &$g) {
        usort($g['resultados'], fn($a, $b) => $b['coincidencia'] <=> $a['coincidencia']);
        $g['resultados'] = array_slice($g['resultados'], 0, $GLOBALS['MAX_RESULTS_PER_LEVEL']);
        $g['total'] = count($g['resultados']);
        $g['nivel_consumo_tokens'] = nivelConsumoTokens($g['tokens_total_nivel']);
    }

    return array_values($grupos);
}

// ==========================================================
// DETECCIÓN DE LEY Y ARTÍCULO
// ==========================================================
function detectarConsulta($q) {
    $articulo = null;
    $ley = null;

    if (preg_match('/art(í?culo|\.?)\s*(\d{1,3})/iu', $q, $m)) $articulo = (int)$m[2];
    if (preg_match('/ley\s*(n\.?|no\.?)?\s*([0-9]{3,5})/iu', $q, $m)) $ley = $m[2];
    elseif (preg_match('/(constituci[oó]n|forestal|notarial|protecci[oó]n de datos|inder|aguas|jurisdicci[oó]n agraria)/iu', $q, $m)) $ley = trim($m[1]);

    return ['articulo' => $articulo, 'ley' => $ley];
}

// ==========================================================
// BÚSQUEDA
// ==========================================================
function buscarCoincidencias($query, $materia, $index, $articulo = null, $ley = null) {
    $palabras = filtrarStopwords(explode(' ', normalizar($query ?? '')));
    $resultados = [];

    foreach ($index as $item) {
        if ($materia && strtolower($item['materia']) !== strtolower($materia)) continue;
        if ($ley && stripos($item['codigo'], $ley) === false) continue;
        if ($articulo && (int)$item['articulo'] !== (int)$articulo) continue;

        $texto = $item['texto'] ?? '';
        $analisis = analizarCoincidencias($texto, $palabras);
        if ($analisis['total'] > 0 || $articulo || $ley) {
            $tokens = estimarTokens($texto);
            $resultados[] = [
                'materia' => $item['materia'] ?? 'general',
                'codigo' => $item['codigo'] ?? 'Desconocido',
                'articulo' => $item['articulo'] ?? '',
                'resumen' => crearResumen($texto, $GLOBALS['RESUMEN_LONG']),
                'texto_completo' => trim($texto),
                'nivel_kelsen' => $item['nivel_kelsen'] ?? 99,
                'jerarquia_nombre' => $item['jerarquia_nombre'] ?? 'Desconocido',
                'coincidencia' => $analisis['total'],
                'tokens_estimados' => $tokens,
                'nivel_consumo_tokens' => nivelConsumoTokens($tokens),
                'supletoria' => $item['supletoria'] ?? []
            ];
        }
    }

    return $resultados;
}

// ==========================================================
// ENTRADA
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $_GET['q'] ?? null;
    $materia = $_GET['materia'] ?? null;
    $leyParam = $_GET['ley'] ?? null;
    $artParam = $_GET['articulo'] ?? null;
    $mode = $_GET['mode'] ?? 'full';
} else {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    $q = $data['q'] ?? null;
    $materia = $data['materia'] ?? null;
    $leyParam = $data['ley'] ?? null;
    $artParam = $data['articulo'] ?? null;
    $mode = $data['mode'] ?? 'full';
}

if (!file_exists($DATA_PATH)) {
    http_response_code(500);
    echo json_encode(['error' => 'No se encontró vector_leyes.json.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$index = json_decode(file_get_contents($DATA_PATH), true);
if (!$index) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo leer el índice.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$det = detectarConsulta($q ?? '');
$ley = $leyParam ?? $det['ley'];
$articulo = $artParam ?? $det['articulo'];
$resultados = buscarCoincidencias($q, $materia, $index, $articulo, $ley);

// ==========================================================
// MODO COMPACTO Y ESPECÍFICO
// ==========================================================
if ($ley && $articulo) {
    $r = $resultados[0] ?? null;
    echo json_encode($r ? [
        'consulta' => $q ?? "Ley: $ley / Artículo: $articulo",
        'ley_detectada' => $ley,
        'articulo_detectado' => $articulo,
        'texto_completo' => $r['texto_completo'],
        'resumen' => $r['resumen'],
        'nivel_kelsen' => $r['nivel_kelsen'],
        'jerarquia_nombre' => $r['jerarquia_nombre'],
        'tokens_estimados' => $r['tokens_estimados'],
        'nivel_consumo_tokens' => $r['nivel_consumo_tokens'],
        'materia' => $r['materia'],
        'codigo' => $r['codigo'],
        'supletoria' => $r['supletoria']
    ] : ['error' => 'No se encontró el artículo solicitado.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$niveles = agruparPorKelsen($resultados);
$totalTokens = array_sum(array_column($niveles, 'tokens_total_nivel'));

// === Modo compacto o resumen ===
if ($mode === 'compact') {
    $compact = [];
    foreach ($niveles as $n) {
        $compact[] = [
            'nivel_kelsen' => $n['nivel_kelsen'],
            'jerarquia_nombre' => $n['jerarquia_nombre'],
            'total' => $n['total'],
            'nivel_consumo_tokens' => $n['nivel_consumo_tokens'],
            'resultados' => array_map(fn($r) => [
                'codigo' => $r['codigo'],
                'articulo' => $r['articulo'],
                'resumen' => $r['resumen'],
                'coincidencia' => $r['coincidencia'],
                'tokens_estimados' => $r['tokens_estimados']
            ], $n['resultados'])
        ];
    }

    echo json_encode([
        'consulta' => $q,
        'modo' => 'compact',
        'materia_consultada' => $materia ?? 'no especificada',
        'total_encontrados' => count($resultados),
        'tokens_total_consulta' => $totalTokens,
        'nivel_consumo_total' => nivelConsumoTokens($totalTokens),
        'niveles_compactos' => $compact
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// === Modo summary ===
if ($mode === 'summary') {
    echo json_encode([
        'consulta' => $q,
        'modo' => 'summary',
        'total_encontrados' => count($resultados),
        'tokens_total_consulta' => $totalTokens,
        'nivel_consumo_total' => nivelConsumoTokens($totalTokens)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// === Modo full ===
echo json_encode([
    'consulta' => $q,
    'modo' => 'full',
    'materia_consultada' => $materia ?? 'no especificada',
    'total_encontrados' => count($resultados),
    'tokens_total_consulta' => $totalTokens,
    'nivel_consumo_total' => nivelConsumoTokens($totalTokens),
    'niveles' => $niveles
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
