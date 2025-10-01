<?php
// Archivo: ociann_rtbf.php
header('Content-Type: application/json; charset=utf-8');

// 🔹 Función para normalizar identificación según el tipo
function normalizarIdentificacion($tipo, $id) {
    $id = preg_replace('/[^0-9A-Za-z]/', '', $id); // limpiar caracteres no válidos

    // Cédula nacional (tipo 1 → formato 0X-XXXX-XXXX)
    if ($tipo === 1) {
        // Si tiene 9 dígitos, anteponer un 0
        if (strlen($id) === 9) {
            $id = "0" . $id;
        }
        // Si tiene 10 dígitos, aplicar formato
        if (strlen($id) === 10) {
            return substr($id, 0, 2) . "-" . substr($id, 2, 4) . "-" . substr($id, 6, 4);
        }
    }

    // DIMEX (tipo 8) → solo números (11 o 12 dígitos)
    if ($tipo === 8) {
        return $id;
    }

    // Otros (Pasaporte, DIDI, etc.)
    return $id;
}

// 🔹 Si viene GET, mapear parámetros
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tipo = isset($_GET['tipo']) ? intval($_GET['tipo']) : null;
    $identificacion = isset($_GET['id']) ? $_GET['id'] : null;
    $input = [
        "TipoDeIdentificacion" => $tipo,
        "Identificacion" => $identificacion
    ];
} else {
    // POST normal con JSON
    $input = json_decode(file_get_contents("php://input"), true);
}

if (!$input || !isset($input['TipoDeIdentificacion']) || !isset($input['Identificacion'])) {
    http_response_code(400);
    echo json_encode([
        "error" => true,
        "message" => "Debe enviar TipoDeIdentificacion e Identificacion."
    ]);
    exit;
}

$tipo = intval($input['TipoDeIdentificacion']);
$identificacionOriginal = trim($input['Identificacion']);

// Normalizar formato
$identificacionNormalizada = normalizarIdentificacion($tipo, $identificacionOriginal);

$url = "https://servicios.sinpe.fi.cr/RBF/RBFCiudadanoConsultaBasica/Consulta/ConsulteSiLaPersonaFisicaNacionalEstaIncluidaEnRBF?api-version=1";

$headers = [
    "Accept: application/json",
    "Content-Type: application/json;charset=UTF-8",
    "Authorization: Bearer null", // ⚠️ Reemplazar con token real cuando lo den
    "Bccr-Entidad-Actual: null",
    "Origin: https://www.centraldirecto.fi.cr",
    "Referer: https://www.centraldirecto.fi.cr/",
    "User-Agent: OCIANNLegal/1.0"
];

$data = json_encode([
    "TipoDeIdentificacion" => $tipo,
    "Identificacion" => $identificacionNormalizada
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(["error" => true, "message" => "Error: $error"]);
    exit;
}

$result = json_decode($response, true);

// 🔹 Procesar respuesta y mensaje humanizado
$mensaje = "No se pudo interpretar la respuesta del servicio.";
if (isset($result["objetoDeRespuestaParaSPA"])) {
    $objeto = json_decode($result["objetoDeRespuestaParaSPA"], true);
    if ($objeto && isset($objeto["FueIncluidoEnElSistema"])) {
        if ($objeto["FueIncluidoEnElSistema"]) {
            $periodos = isset($objeto["Periodos"]) && is_array($objeto["Periodos"]) 
                        ? implode(", ", $objeto["Periodos"]) 
                        : "N/D";
            $mensaje = "La persona física con identificación $identificacionNormalizada SÍ está incluida como participante y/o beneficiario en el RTBF (períodos: $periodos).";
        } else {
            $mensaje = "La persona física con identificación $identificacionNormalizada NO está incluida en el Registro de Transparencia y Beneficiarios Finales.";
        }
    }
}

// 🔹 Respuesta final
echo json_encode([
    "consulta" => [
        "tipo" => $tipo,
        "identificacion_original" => $identificacionOriginal,
        "identificacion_normalizada" => $identificacionNormalizada
    ],
    "httpCode" => $httpCode,
    "resultado" => $result,
    "mensaje" => $mensaje
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
