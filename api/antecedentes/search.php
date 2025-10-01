<?php
// Archivo: antecedentes/search.php
header('Content-Type: application/json; charset=utf-8');

// Cargar autoload de Composer desde el root del proyecto
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ==============================
// 🔹 Función: Normalizar cédula
// ==============================
function normalizarIdentificacion($tipo, $id) {
    $id = preg_replace('/[^0-9A-Za-z]/', '', $id);

    if ($tipo === 1) { // Cédula nacional
        if (strlen($id) === 9) {
            $id = "0" . $id;
        }
        if (strlen($id) === 10) {
            return substr($id, 0, 2) . "-" . substr($id, 2, 4) . "-" . substr($id, 6, 4);
        }
    }

    if ($tipo === 8) { // DIMEX
        return $id;
    }

    return $id; // Otros (pasaporte, DIDI, etc.)
}

// ==============================
// 🔹 Validación entrada
// ==============================
$tipo = isset($_GET['tipo']) ? intval($_GET['tipo']) : null;
$identificacion = isset($_GET['id']) ? trim($_GET['id']) : null;

if (!$tipo || !$identificacion) {
    http_response_code(400);
    echo json_encode([
        "error" => true,
        "message" => "Debe enviar ?tipo=1&id=XXXX"
    ]);
    exit;
}

$identificacionNormalizada = normalizarIdentificacion($tipo, $identificacion);

// ==============================
// 🔹 Leer Excel local de SICOP
// ==============================
$localFile = __DIR__ . '/../../uploads/antecedentes/sancionados.xlsx'; // 📂 archivo local

$sicop = [];
try {
    if (!file_exists($localFile)) {
        throw new Exception("Archivo Excel no encontrado en $localFile");
    }

    $spreadsheet = IOFactory::load($localFile);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    // Saltar encabezados si existen en la primera fila
    foreach ($rows as $index => $row) {
        if ($index === 1) continue; // asumiendo encabezados en fila 1

        // Cédula del proveedor (columna F en tu Excel de ejemplo)
        $cedulaProveedor = isset($row["F"]) ? preg_replace('/\D/', '', $row["F"]) : null;

        if ($cedulaProveedor && strpos($cedulaProveedor, str_replace("-", "", $identificacionNormalizada)) !== false) {
            $sicop[] = [
                "numero"        => $row["A"] ?? "",
                "tipo_sancion"  => $row["B"] ?? "",
                "cobertura"     => $row["C"] ?? "",
                "institucion"   => $row["D"] ?? "",
                "proveedor"     => $row["E"] ?? "",
                "cedula"        => $row["F"] ?? "",
                "tipo_proveedor"=> $row["G"] ?? "",
                "periodo"       => $row["H"] ?? "",
                "estado"        => $row["I"] ?? "",
                "codigo_bien"   => $row["J"] ?? "",
                "nombre_unspsc" => $row["K"] ?? "",
                "norma_incumplida" => $row["L"] ?? ""
            ];
        }
    }
} catch (Exception $e) {
    $sicop = ["error" => "No se pudo leer el Excel de SICOP", "detalle" => $e->getMessage()];
}

// ==============================
// 🔹 Respuesta JSON
// ==============================
echo json_encode([
    "consulta" => [
        "tipo" => $tipo,
        "identificacion_original" => $identificacion,
        "identificacion_normalizada" => $identificacionNormalizada
    ],
    "SICOP" => [
        "estado" => (empty($sicop) || isset($sicop['error'])) ? "Habilitado" : "Inhabilitado",
        "detalles" => $sicop
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
