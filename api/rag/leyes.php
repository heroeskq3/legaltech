<?php
// ==========================================================
// API LOCAL DE CONSULTA DE LEYES Y CÓDIGOS CR
// Pirámide de Kelsen + Coincidencias + Tokens + Detección de Ley y Artículo + Modo Compacto
// Versión: v1.9.4 (normalización + alias tratados + respuesta uniforme sin resultados)
// ==========================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

$DATA_PATH = __DIR__ . '/../../uploads/leyes/vector_leyes.json';
$MAX_RESULTS_PER_LEVEL = 5;
$RESUMEN_LONG = 400;
$TOKENS_PER_CHAR = 1 / 4;
$DEFAULT_FULL_TOKEN_LIMIT = 20000;
$RECOMENDACIONES_BASE = [
    'Divide la carga por tratado o bloque temático (CADH, PIDCP, CEDAW, etc.).',
    'Usa mode=compact o mode=index para obtener solo el esqueleto jerárquico.',
    'Solicita artículos específicos con los parámetros codigo/tratado + articulo.',
    'Guarda cada bloque localmente para hacer carga incremental sin agotar memoria.'
];

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
    return ['total' => count(array_unique($palabrasCoinciden)), 'palabras' => array_unique($palabrasCoinciden)];
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
function agregarMensajeUnico(&$lista, $mensaje) {
    if (!in_array($mensaje, $lista, true)) $lista[] = $mensaje;
}
function agruparPorKelsen($resultados, $maxPorNivel = null) {
    $maxPorNivel = $maxPorNivel ?: ($GLOBALS['MAX_RESULTS_PER_LEVEL'] ?? 5);
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
        $g['resultados'] = array_slice($g['resultados'], 0, $maxPorNivel);
        $g['total'] = count($g['resultados']);
        $g['nivel_consumo_tokens'] = nivelConsumoTokens($g['tokens_total_nivel']);
    }
    return array_values($grupos);
}
function limitarNivelesPorTokens($niveles, $maxTokens = null) {
    if (!$maxTokens || $maxTokens <= 0) {
        $total = 0;
        foreach ($niveles as $nivel) $total += $nivel['tokens_total_nivel'] ?? 0;
        return [$niveles, false, $total];
    }
    $nivelesLimitados = [];
    $tokensUsados = 0;
    $truncado = false;
    foreach ($niveles as $nivel) {
        $nivelTmp = $nivel;
        $nivelTmp['resultados'] = [];
        $nivelTokens = 0;
        foreach ($nivel['resultados'] as $res) {
            $tokensRes = $res['tokens_estimados'] ?? 0;
            if ($tokensUsados + $tokensRes > $maxTokens) {
                $truncado = true;
                if ($nivelTmp['resultados']) {
                    $nivelTmp['tokens_total_nivel'] = $nivelTokens;
                    $nivelTmp['total'] = count($nivelTmp['resultados']);
                    $nivelTmp['nivel_consumo_tokens'] = nivelConsumoTokens($nivelTokens);
                    $nivelesLimitados[] = $nivelTmp;
                }
                return [$nivelesLimitados, $truncado, $tokensUsados];
            }
            $nivelTmp['resultados'][] = $res;
            $nivelTokens += $tokensRes;
            $tokensUsados += $tokensRes;
        }
        if ($nivelTmp['resultados']) {
            $nivelTmp['tokens_total_nivel'] = $nivelTokens;
            $nivelTmp['total'] = count($nivelTmp['resultados']);
            $nivelTmp['nivel_consumo_tokens'] = nivelConsumoTokens($nivelTokens);
            $nivelesLimitados[] = $nivelTmp;
        }
    }
    return [$nivelesLimitados, $truncado, $tokensUsados];
}

// ==========================================================
// BÚSQUEDA FLEXIBLE (soporta index sin q y alias tratados)
// ==========================================================
function buscarCoincidencias($query, $materia, $index, $articulo = null, $codigoFiltro = null, $incluirTextoCompleto = true, $rango = null, $modoIndex = false, $page = 1, $limit = 50) {
    $palabras = filtrarStopwords(explode(' ', normalizar($query ?? '')));
    $resultados = [];
    $offset = ($page - 1) * $limit;

    $aliasTratados = [
        'CADH' => 'convencion america de derechos humanos',
        'PIDCP' => 'pacto internacional de derechos civiles y politicos',
        'PIDESC' => 'pacto internacional de derechos economicos sociales y culturales',
        'CEDAW' => 'convencion sobre la eliminacion de todas las formas de discriminacion contra la mujer',
        'CDPD' => 'convencion sobre los derechos de las personas con discapacidad'
    ];
    if ($codigoFiltro && isset($aliasTratados[strtoupper($codigoFiltro)])) {
        $codigoFiltro = $aliasTratados[strtoupper($codigoFiltro)];
    }

    $contador = 0;
    foreach ($index as $item) {
        if ($materia && strtolower($item['materia']) !== strtolower($materia)) continue;

        $codigoItem = normalizar($item['codigo'] ?? '');
        $codigoFiltroNorm = normalizar($codigoFiltro ?? '');

        if ($codigoFiltro && strpos($codigoItem, $codigoFiltroNorm) === false &&
            strpos(normalizar($item['nombre'] ?? ''), $codigoFiltroNorm) === false) continue;

        // Modo index (solo listado)
        if ($modoIndex) {
            if ($contador++ < $offset) continue;
            if (count($resultados) >= $limit) break;
            $texto = $item['texto'] ?? '';
            $tokens = estimarTokens($texto);
            $resultados[] = [
                'materia' => $item['materia'] ?? 'general',
                'codigo' => $item['codigo'] ?? 'Desconocido',
                'articulo' => $item['articulo'] ?? '',
                'resumen' => crearResumen($texto, 300),
                'nivel_kelsen' => $item['nivel_kelsen'] ?? 99,
                'jerarquia_nombre' => $item['jerarquia_nombre'] ?? 'Desconocido',
                'coincidencia' => 1,
                'tokens_estimados' => $tokens,
                'texto_completo' => null
            ];
            continue;
        }

        // Modo normal
        $texto = $item['texto'] ?? '';
        $analisis = analizarCoincidencias($texto, $palabras);
        if ($analisis['total'] > 0 || $articulo || $rango || $codigoFiltro) {
            $tokens = estimarTokens($texto);
            $resultados[] = [
                'materia' => $item['materia'] ?? 'general',
                'codigo' => $item['codigo'] ?? 'Desconocido',
                'articulo' => $item['articulo'] ?? '',
                'resumen' => crearResumen($texto, 300),
                'nivel_kelsen' => $item['nivel_kelsen'] ?? 99,
                'jerarquia_nombre' => $item['jerarquia_nombre'] ?? 'Desconocido',
                'coincidencia' => max(1, $analisis['total']),
                'tokens_estimados' => $tokens,
                'texto_completo' => $incluirTextoCompleto ? trim($texto) : null
            ];
        }
    }
    return $resultados;
}

// ==========================================================
// ENTRADA Y EJECUCIÓN
// ==========================================================
$params = ($_SERVER['REQUEST_METHOD'] === 'GET') ? $_GET : (json_decode(file_get_contents("php://input"), true) ?? []);

$q = $params['q'] ?? null;
$materia = $params['materia'] ?? null;
$mode = strtolower($params['mode'] ?? 'full');
$page = isset($params['page']) ? max(1,(int)$params['page']) : 1;
$limit = isset($params['limit']) ? max(1,(int)$params['limit']) : 50;
$codigo = $params['codigo'] ?? ($params['tratado'] ?? null);

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

$modoIndex = in_array($mode, ['index', 'compact']);
$resultados = buscarCoincidencias($q, $materia, $index, null, $codigo, false, null, $modoIndex, $page, $limit);
$nivelesAgrupados = agruparPorKelsen($resultados, 5);
[$niveles, $truncadoPorTokens, $totalTokens] = limitarNivelesPorTokens($nivelesAgrupados, null);

// ==========================================================
// SALIDA FINAL UNIFORME
// ==========================================================
$response = [
    'version_api' => 'v1.9.4',
    'estado_api' => count($resultados) > 0 ? 'ok' : 'vacio',
    'consulta' => $q,
    'modo' => $mode,
    'materia_consultada' => $materia ?? null,
    'total_encontrados' => count($resultados),
    'niveles_compactos' => $niveles,
    'page' => $page,
    'limit' => $limit,
    'recomendaciones' => $GLOBALS['RECOMENDACIONES_BASE']
];

if (!$materia && !$codigo && !$q) {
    $response['mensaje_sistema'] = "API activa. Parámetros disponibles: materia, codigo/tratado, q, articulo, rango, mode, page, limit.";
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
