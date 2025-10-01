<?php
$url = "https://www.sicop.go.cr//servlet/usemn/sa/UM_REJ_IJQ001_CONTROLLER";

$postFields = http_build_query([
    "date_yn" => "",
    "biz_reg_no" => "",
    "supplier_nm" => "",
    "inst_reg_no" => "",
    "inst_nm" => "",
    "warn_sanction_cl" => "",
    "reqDtFrom" => "01/10/2024",
    "reqDtTo"   => "01/10/2025",
    "page_size" => "1000"
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

// Cabeceras mínimas (sin las cookies de navegador, no siempre hacen falta si el recurso es público)
$headers = [
    "Content-Type: application/x-www-form-urlencoded",
    "User-Agent: LegalTechBot/1.0"
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    die("Error en cURL: $error");
}

// Guardar el Excel en disco
file_put_contents("../../uploads/sicop/sancionados.xlsx", $response);
echo "Archivo Excel guardado como sancionados.xlsx\n";
