<?php include("config.php"); ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asistente Jurídico CR - Familia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">GPT Jurídico CR – Familia</h4>
            </div>
            <div class="card-body" id="chat-box" style="height: 700px; overflow-y: auto;">
                <!-- Mensajes se agregan aquí -->
            </div>
            <div class="card-footer">
                <form id="chat-form" class="d-flex">
                    <input type="text" id="user-input" class="form-control me-2" placeholder="Escribe tu consulta..." required>
                    <button class="btn btn-success" type="submit">Enviar</button>
                </form>
            </div>
        </div>
    </div>
    <script src="chat.js"></script>
</body>
</html>
