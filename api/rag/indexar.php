<?php
// =====================================================
// INDEXADOR PIRÁMIDE DE KELSEN MULTI-MATERIA Y SUPLETORIEDAD
// Detecta materia correctamente (agrario, notarial, etc.)
// Compatible con macOS/XAMPP — UTF-8 Seguro
// Autor: Herbert Poveda (LegalTech CR)
// =====================================================

ini_set('memory_limit', '1G');
$EOL = (php_sapi_name() === 'cli') ? PHP_EOL : "<br>";

$DOCS_ROOT = realpath(__DIR__ . '/../../uploads/leyes/');
$VECTOR_DB_PATH = $DOCS_ROOT . '/vector_leyes.json';

echo "📁 DOCS_ROOT = $DOCS_ROOT{$EOL}";
echo "📄 VECTOR_DB_PATH = $VECTOR_DB_PATH{$EOL}{$EOL}";

if (!$DOCS_ROOT || !is_dir($DOCS_ROOT)) {
    echo "❌ ERROR: carpeta '/uploads/leyes/' no encontrada{$EOL}";
    exit;
}

// =====================================================
// FUNCIONES BASE
// =====================================================
function normalizar($t) { return preg_replace('/\s+/', ' ', trim($t)); }

function asegurar_utf8($str) {
    if ($str === null) return '';
    if (!mb_check_encoding($str, 'UTF-8')) {
        $det = mb_detect_encoding($str, ['UTF-8','ISO-8859-1','WINDOWS-1252'], true);
        $str = mb_convert_encoding($str, 'UTF-8', $det ?: 'ISO-8859-1');
    }
    return $str;
}

/**
 * Determina nivel jerárquico según la pirámide de Kelsen
 */
function obtenerJerarquia($dirName) {
    $map = [
        '1_' => 'Tratado o Derecho Internacional',
        '2_' => 'Constitución Política',
        '3_' => 'Ley Orgánica',
        '4_' => 'Ley Ordinaria',
        '5_' => 'Ley Especial',
        '6_' => 'Ley Sustantiva',
        '7_' => 'Ley Supletoria',
        '8_' => 'Decreto / Reglamento',
        '9_' => 'Manual / Doctrina',
        '10_' => 'Resolución'
    ];
    foreach ($map as $prefix => $nombre) {
        if (strpos($dirName, $prefix) === 0) {
            return [
                'nivel' => (int) filter_var($prefix, FILTER_SANITIZE_NUMBER_INT),
                'nombre' => $nombre
            ];
        }
    }
    return ['nivel' => 99, 'nombre' => 'Desconocido'];
}

/**
 * Escanea recursivamente las carpetas de leyes,
 * detectando materia raíz (agrario, notarial, etc.)
 */
function recorrerCarpetas($path, &$lista, $materiaRaiz = null, $nivelActual = null) {
    foreach (scandir($path) as $i) {
        if ($i === '.' || $i === '..') continue;
        $ruta = "$path/$i";

        if (is_dir($ruta)) {
            // Si estamos justo debajo de /leyes/, el nombre del subdirectorio es la materia
            $nivelBase = basename(dirname($ruta));
            if ($nivelBase === basename(realpath(__DIR__ . '/../../uploads/leyes'))) {
                $materiaRaiz = strtolower($i);
            }

            $jer = obtenerJerarquia($i);
            recorrerCarpetas($ruta, $lista, $materiaRaiz, $jer);
        }
        elseif (is_file($ruta) && preg_match('/\.txt$/i', $i)) {
            $lista[] = [
                'archivo' => $ruta,
                'materia' => strtolower($materiaRaiz ?? 'general'),
                'nivel_kelsen' => $nivelActual['nivel'] ?? 99,
                'jerarquia_nombre' => $nivelActual['nombre'] ?? 'Desconocido'
            ];
        }
    }
}

/**
 * Obtiene supletoriedad desde __meta__.json (si existe)
 */
function obtenerSupletorias($materia) {
    global $DOCS_ROOT;
    $meta = "$DOCS_ROOT/$materia/__meta__.json";
    if (file_exists($meta)) {
        $j = json_decode(file_get_contents($meta), true);
        return $j['supletoria'] ?? [];
    }
    return [];
}

/**
 * Indexa los artículos de cada archivo
 */
function indexarArchivo($archivo, $materia, $nivelKelsen, $jerarquiaNombre, &$index, $EOL) {
    $nombre = pathinfo($archivo, PATHINFO_FILENAME);
    $texto = @file_get_contents($archivo);
    if (!$texto || strlen(trim($texto)) < 20) {
        echo "⚠️  Vacío: $archivo{$EOL}";
        return;
    }

    $texto = asegurar_utf8($texto);
    $bloques = preg_split("/(Artículo|ARTÍCULO|Art\.?)\s+\d+\.?/", $texto);
    preg_match_all("/(Artículo|ARTÍCULO|Art\.?)\s+(\d+)/", $texto, $nums);

    if (count($nums[2]) == 0) {
        echo "⚠️  No detecta artículos en $nombre, dividiendo en bloques...{$EOL}";
        $bloques = str_split($texto, 1800);
        $nums[2] = range(1, count($bloques));
    }

    $prev = count($index);
    foreach ($bloques as $i => $b) {
        $b = asegurar_utf8(normalizar($b));
        if (strlen($b) < 50) continue;
        $art = $nums[2][$i] ?? $i + 1;

        $index[] = [
            'materia' => strtolower($materia),
            'codigo' => asegurar_utf8($nombre),
            'articulo' => $art,
            'texto' => trim($b),
            'nivel_kelsen' => $nivelKelsen,
            'jerarquia_nombre' => $jerarquiaNombre,
            'supletoria' => obtenerSupletorias($materia)
        ];
    }

    echo "✅ $nombre → " . (count($index) - $prev) .
         " artículos (Nivel $nivelKelsen - $jerarquiaNombre | Materia: $materia){$EOL}";
}

// =====================================================
// PROCESO PRINCIPAL
// =====================================================
$index = [];
$archivos = [];

recorrerCarpetas($DOCS_ROOT, $archivos);

echo "📄 Archivos detectados: " . count($archivos) . $EOL;
if (!count($archivos)) {
    echo "❌ No se encontraron archivos .txt{$EOL}";
    exit;
}

foreach ($archivos as $a) {
    echo "📘 Procesando: " . basename($a['archivo']) .
         " (" . strtoupper($a['materia']) . "){$EOL}";
    indexarArchivo(
        $a['archivo'],
        $a['materia'],
        $a['nivel_kelsen'],
        $a['jerarquia_nombre'],
        $index,
        $EOL
    );
}

echo "{$EOL}🧮 Total artículos indexados: " . count($index) . "{$EOL}";

// =====================================================
// GUARDAR JSON SEGURO
// =====================================================
$tmpPath = $VECTOR_DB_PATH . ".tmp";
$f = fopen($tmpPath, 'w');
if (!$f) {
    echo "❌ No se puede abrir archivo temporal{$EOL}";
    exit;
}

fwrite($f, "[\n");
$total = count($index);
foreach ($index as $k => $row) {
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        echo "❌ Error JSON fila $k: " . json_last_error_msg() . "{$EOL}";
        continue;
    }
    fwrite($f, $json);
    if ($k < $total - 1) fwrite($f, ",\n");
}
fwrite($f, "\n]");
fclose($f);

rename($tmpPath, $VECTOR_DB_PATH);

echo "✅ Archivo final: $VECTOR_DB_PATH{$EOL}";
echo "📦 Tamaño: " . filesize($VECTOR_DB_PATH) . " bytes{$EOL}";
echo "🏁 Indexación completada correctamente{$EOL}";
