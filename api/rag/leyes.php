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
$TOKENS_PER_CHAR = 1 / 4;
$DEFAULT_FULL_TOKEN_LIMIT = 20000;
$SIZE_WARNING_BYTES = 512 * 1024;
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
// DETECCIÓN DE LEY Y ARTÍCULO
// ==========================================================
function detectarConsulta($q) {
    $articulo = null; $rango = null; $ley = null;

    // ⚙️ FIX: detectar rango tipo “artículos 1–5” o “arts. 3 a 7”
    if (preg_match('/art(í?culos?|\.?)\s*(\d+)\s*[-a–]\s*(\d+)/iu', $q, $m)) {
        $rango = [(int)$m[2], (int)$m[3]];
    } elseif (preg_match('/art(í?culo|\.?)\s*(\d{1,3})/iu', $q, $m)) {
        $articulo = (int)$m[2];
    }

    if (preg_match('/ley\s*(n\.?|no\.?)?\s*([0-9]{3,5})/iu', $q, $m)) $ley = $m[2];
    elseif (preg_match('/(constituci[oó]n|jurisdicci[oó]n|forestal|notarial|inder|aguas)/iu', $q, $m)) $ley = trim($m[1]);
    else {
        $tratados = [
            'convención americana sobre derechos humanos',
            'pacto internacional de derechos civiles y políticos',
            'pacto internacional de derechos económicos, sociales y culturales',
            'convención contra la tortura',
            'cedaw',
            'convención sobre los derechos del niño',
            'acuerdo de escazú'
        ];
        foreach ($tratados as $t) if (stripos($q, $t) !== false) { $ley = $t; break; }
    }
    return ['articulo' => $articulo, 'rango' => $rango, 'ley' => $ley];
}

// ==========================================================
// BÚSQUEDA
// ==========================================================
function buscarCoincidencias($query, $materia, $index, $articulo = null, $codigoFiltro = null, $incluirTextoCompleto = true, $rango = null) {
    $palabras = filtrarStopwords(explode(' ', normalizar($query ?? '')));
    $resultados = [];

    foreach ($index as $item) {
        if ($materia && strtolower($item['materia']) !== strtolower($materia)) continue;
        if ($codigoFiltro) {
            $codigoItem = mb_strtolower($item['codigo'] ?? '', 'UTF-8');
            $codigoFiltroNorm = mb_strtolower($codigoFiltro, 'UTF-8');
            if (stripos($codigoItem, $codigoFiltroNorm) === false) continue;
        }

        // ⚙️ FIX: admitir rango de artículos
        if ($rango && isset($item['articulo'])) {
            if ((int)$item['articulo'] < $rango[0] || (int)$item['articulo'] > $rango[1]) continue;
        } elseif ($articulo && (int)$item['articulo'] !== (int)$articulo) continue;

        $texto = $item['texto'] ?? '';
        $analisis = analizarCoincidencias($texto, $palabras);
        if ($analisis['total'] > 0 || $articulo || $codigoFiltro || $rango) {
            $tokens = estimarTokens($texto);
            $entrada = [
                'materia' => $item['materia'] ?? 'general',
                'codigo' => $item['codigo'] ?? 'Desconocido',
                'articulo' => $item['articulo'] ?? '',
                'resumen' => crearResumen($texto, $GLOBALS['RESUMEN_LONG']),
                'nivel_kelsen' => $item['nivel_kelsen'] ?? 99,
                'jerarquia_nombre' => $item['jerarquia_nombre'] ?? 'Desconocido',
                'coincidencia' => $analisis['total'],
                'tokens_estimados' => $tokens,
                'nivel_consumo_tokens' => nivelConsumoTokens($tokens),
                'supletoria' => $item['supletoria'] ?? []
            ];
            if ($incluirTextoCompleto) $entrada['texto_completo'] = trim($texto);
            $resultados[] = $entrada;
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
    $codigoParam = $_GET['codigo'] ?? ($_GET['tratado'] ?? null);
    $maxTokensParam = isset($_GET['max_tokens']) ? (int)$_GET['max_tokens'] : null;
    $maxPorNivelParam = isset($_GET['max_por_nivel']) ? (int)$_GET['max_por_nivel'] : null;
    $mode = $_GET['mode'] ?? 'full';
} else {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    $q = $data['q'] ?? null;
    $materia = $data['materia'] ?? null;
    $leyParam = $data['ley'] ?? null;
    $artParam = $data['articulo'] ?? null;
    $codigoParam = $data['codigo'] ?? ($data['tratado'] ?? null);
    $maxTokensParam = isset($data['max_tokens']) ? (int)$data['max_tokens'] : null;
    $maxPorNivelParam = isset($data['max_por_nivel']) ? (int)$data['max_por_nivel'] : null;
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
$rango = $det['rango'] ?? null;
$codigoFiltro = ($codigoParam ?: $ley);

$modeRequested = strtolower($mode ?? 'full');
$modeAllowed = ['full','compact','summary','index'];
if (!in_array($modeRequested, $modeAllowed,true)) $modeRequested = 'full';
$mode = ($modeRequested==='index') ? 'compact' : $modeRequested;

$maxPorNivel = ($maxPorNivelParam && $maxPorNivelParam>0)?$maxPorNivelParam:$MAX_RESULTS_PER_LEVEL;
$maxTokensEffective = $maxTokensParam!==null?max(0,(int)$maxTokensParam):($mode==='full'?$DEFAULT_FULL_TOKEN_LIMIT:null);

$incluirTextoCompleto = ($articulo!==null)||$rango||$mode==='full';
$resultados = buscarCoincidencias($q,$materia,$index,$articulo,$codigoFiltro,$incluirTextoCompleto,$rango);

// ==========================================================
// SALIDA (idéntica a tu versión, sin cambios sustantivos)
// ==========================================================
$nivelesAgrupados = agruparPorKelsen($resultados,$maxPorNivel);
[$niveles,$truncadoPorTokens,$totalTokens] = limitarNivelesPorTokens($nivelesAgrupados,$maxTokensEffective);
$warnings=[]; $recomendaciones=$RECOMENDACIONES_BASE;
$totalEncontrados=count($resultados); $totalEntregados=0;
foreach($niveles as $n) $totalEntregados+=$n['total']??count($n['resultados']);

$response=[
 'consulta'=>$q,
 'modo'=>$modeRequested,
 'modo_ejecutado'=>$mode,
 'materia_consultada'=>$materia??'no especificada',
 'total_encontrados'=>$totalEncontrados,
 'total_entregados'=>$totalEntregados,
 'tokens_total_consulta'=>$totalTokens,
 'nivel_consumo_total'=>nivelConsumoTokens($totalTokens),
 'filtros'=>['materia'=>$materia,'ley'=>$ley,'codigo'=>$codigoFiltro,'articulo'=>$articulo,'rango'=>$rango],
 'truncado_por_tokens'=>$truncadoPorTokens
];

if($mode==='compact'){
  $compact=[]; foreach($niveles as $n){
    $compact[]=[
     'nivel_kelsen'=>$n['nivel_kelsen'],
     'jerarquia_nombre'=>$n['jerarquia_nombre'],
     'total'=>$n['total'],
     'nivel_consumo_tokens'=>$n['nivel_consumo_tokens'],
     'resultados'=>array_map(fn($r)=>[
        'codigo'=>$r['codigo'],
        'articulo'=>$r['articulo'],
        'resumen'=>$r['resumen'],
        'coincidencia'=>$r['coincidencia'],
        'tokens_estimados'=>$r['tokens_estimados']
     ],$n['resultados'])
    ];
  }
  $response['modo_estructura']=($modeRequested==='index')?'index':'compact';
  $response['niveles_compactos']=$compact;
}else{
  $response['modo_estructura']='full';
  $response['niveles']=$niveles;
}

if($rango) agregarMensajeUnico($warnings,"Se detectó rango de artículos {$rango[0]}–{$rango[1]} (modo unificado).");

$response['warnings']=$warnings;
$response['recomendaciones']=array_values(array_unique($recomendaciones));

echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
?>
