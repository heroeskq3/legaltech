<?php
// =====================================
// API REST Contenido de Machotes Jurídicos
// =====================================
// Dependencias: NINGUNA (solo PHP nativo)
// - Lee Word (.docx) y PDF (.pdf)
// =====================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

// Ruta base donde están los formularios
$baseDir = realpath(__DIR__ . '/../../uploads/formularios');

// Validación del parámetro
$id = $_GET['id'] ?? '';
if (empty($id)) {
    http_response_code(400);
    echo json_encode(["error" => "Debe enviar parámetro id (ruta relativa del archivo)."]);
    exit;
}

// Normalizamos y prevenimos path traversal
$fullPath = realpath($baseDir . '/' . $id);
if (!$fullPath || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    echo json_encode(["error" => "Archivo no encontrado.", "id" => $id]);
    exit;
}

$extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$content   = "";

// Función para leer DOCX
function leerDocx($filePath) {
    $zip = new ZipArchive;
    if ($zip->open($filePath) === TRUE) {
        if (($index = $zip->locateName("word/document.xml")) !== false) {
            $data = $zip->getFromIndex($index);
            $zip->close();
            // Extraer texto eliminando etiquetas XML
            return strip_tags(str_replace("</w:p>", "\n", $data));
        }
        $zip->close();
    }
    return "";
}

// Función para leer PDF (solo texto simple)
function leerPdf($filePath) {
    $content = @file_get_contents($filePath);
    if ($content === false) return "";
    // Muy básico: extrae solo texto visible
    return preg_replace('/[^(\x20-\x7F)\x0A\x0D]*/', '', $content);
}

// Determinar cómo leer
if ($extension === "docx") {
    $content = leerDocx($fullPath);
} elseif ($extension === "pdf") {
    $content = leerPdf($fullPath);
} else {
    http_response_code(415);
    echo json_encode(["error" => "Formato no soportado. Solo DOCX o PDF.", "id" => $id]);
    exit;
}

// Respuesta JSON
echo json_encode([
    "id"       => $id,
    "nombre"   => pathinfo($fullPath, PATHINFO_FILENAME),
    "extension"=> $extension,
    "size_kb"  => round(filesize($fullPath) / 1024, 2),
    "content"  => $content
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
