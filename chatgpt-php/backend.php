<?php
include("config.php");

header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);
$userMessage = $input["message"] ?? "";

// Endpoint de OpenAI Chat
$url = "https://api.openai.com/v1/chat/completions";

$data = [
    "model" => "gpt-4o-mini", // puedes usar gpt-4o o gpt-4.1
    "messages" => [
        ["role" => "system", "content" => "Eres un asistente jurídico especializado en derecho de familia en Costa Rica. Responde en formato APA 7 con normativa y jurisprudencia."],
        ["role" => "user", "content" => $userMessage]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . OPENAI_API_KEY
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$reply = $result["choices"][0]["message"]["content"] ?? "⚠️ Error al obtener respuesta.";

echo json_encode(["reply" => $reply]);
