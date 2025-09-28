document.getElementById("chat-form").addEventListener("submit", async function(e) {
    e.preventDefault();
    
    let input = document.getElementById("user-input");
    let message = input.value;
    input.value = "";

    let chatBox = document.getElementById("chat-box");

    // Mostrar mensaje del usuario
    let userMsg = document.createElement("div");
    userMsg.className = "alert alert-primary";
    userMsg.innerText = "👤: " + message;
    chatBox.appendChild(userMsg);
    chatBox.scrollTop = chatBox.scrollHeight;

    // Llamar al backend
    let response = await fetch("backend.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({message})
    });

    let data = await response.json();

    // Mostrar respuesta de GPT
    let botMsg = document.createElement("div");
    botMsg.className = "alert alert-secondary";
    botMsg.innerText = "⚖️ GPT Jurídico: " + data.reply;
    chatBox.appendChild(botMsg);
    chatBox.scrollTop = chatBox.scrollHeight;
});
