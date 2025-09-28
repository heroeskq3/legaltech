<?php
// =====================================
// API REST Buscador de Machotes Jurídicos (dinámico con ranking y stopwords)
// =====================================

// Cabeceras
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

// Ruta física base
$baseDir = realpath(__DIR__ . '/../../uploads/formularios'); 

// Construcción dinámica de la URL base
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
             || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];

// Calculamos el subpath dinámico
$docRoot = realpath($_SERVER['DOCUMENT_ROOT']); 
$relativePath = str_replace($docRoot, '', $baseDir);

// URL base final
$baseUrl = $protocol . $host . $relativePath;

// Verifica existencia
if (!$baseDir || !is_dir($baseDir)) {
    http_response_code(500);
    echo json_encode(["error" => "Carpeta de machotes no encontrada.", "debug" => $baseDir]);
    exit;
}

// Parámetro de búsqueda
$q = $_GET['q'] ?? '';
if (empty($q)) {
    http_response_code(400);
    echo json_encode(["error" => "Debe enviar parámetro q (palabra clave)."]);
    exit;
}
$q = strtolower($q);

// Stopwords en español (puedes ampliar la lista)
$stopwords = [
    "de","la","el","los","las","un","una","unos","unas",
    "para","por","con","sin","y","o","u","que","en","del",
    "se","su","sus","al","lo","quiero","necesito","escrito"
];

// Separa palabras clave y elimina stopwords
$keywords = array_filter(explode(" ", $q), function($word) use ($stopwords) {
    return !in_array($word, $stopwords) && strlen($word) > 2;
});

// Si después de filtrar no queda nada, forzamos a que no rompa
if (empty($keywords)) {
    $keywords = [$q];
}

// Función recursiva de búsqueda
function buscarArchivos($path, $urlBase, $keywords, $categoria = '') {
    $resultados = [];

    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') continue;

        $fullPath = $path . '/' . $item;
        $itemUrl  = $urlBase . '/' . rawurlencode($item);

        if (is_dir($fullPath)) {
            $subResultados = buscarArchivos(
                $fullPath,
                $itemUrl,
                $keywords,
                ($categoria ? $categoria . '/' : '') . $item
            );
            $resultados = array_merge($resultados, $subResultados);
        } elseif (is_file($fullPath)) {
            $nombreArchivo = strtolower(pathinfo($item, PATHINFO_FILENAME));
            $score = 0;

            foreach ($keywords as $word) {
                if (strpos($nombreArchivo, $word) !== false) {
                    $score++;
                }
            }

            if ($score > 0) {
                $resultados[] = [
                    "nombre"        => pathinfo($item, PATHINFO_FILENAME),
                    "categoria"     => $categoria,
                    "url"           => $itemUrl,
                    "extension"     => pathinfo($item, PATHINFO_EXTENSION),
                    "size_kb"       => round(filesize($fullPath) / 1024, 2),
                    "coincidencias" => $score
                ];
            }
        }
    }
    return $resultados;
}

// Ejecuta búsqueda
$resultados = buscarArchivos($baseDir, $baseUrl, $keywords);

// Ordenar por relevancia avanzada
usort($resultados, function($a, $b) use ($keywords) {
    // 1) Comparar por número de coincidencias
    if ($a['coincidencias'] !== $b['coincidencias']) {
        return $b['coincidencias'] <=> $a['coincidencias'];
    }

    // 2) Priorizar si empieza con una keyword
    foreach ($keywords as $word) {
        $aStarts = stripos($a['nombre'], $word) === 0 ? 1 : 0;
        $bStarts = stripos($b['nombre'], $word) === 0 ? 1 : 0;
        if ($aStarts !== $bStarts) {
            return $bStarts <=> $aStarts;
        }
    }

    // 3) Nombre más corto primero
    return strlen($a['nombre']) <=> strlen($b['nombre']);
});

// Respuesta
echo json_encode([
    "query" => $q,
    "palabras" => array_values($keywords),
    "total" => count($resultados),
    "resultados" => $resultados
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
