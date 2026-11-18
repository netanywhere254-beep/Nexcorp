<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NexCore AI | {{ config('app.name', 'Nexora') }}</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #071426; color: #e8f2ff; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at 80% 0%, #12365a 0, #071426 42rem); }
        header { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1.1rem clamp(1rem,4vw,3rem); border-bottom:1px solid #1c3855; }
        .brand { color:#49d2ff; font-weight:800; letter-spacing:.16em; } .tag { color:#9bb4cc; font-size:.85rem; }
        main { width:min(920px, calc(100% - 2rem)); margin:3rem auto; }
        h1 { margin:.3rem 0; font-size:clamp(2rem,5vw,3.2rem); } .intro { color:#a9bfd7; line-height:1.65; max-width:680px; }
        .panel { margin-top:2rem; border:1px solid #244563; border-radius:20px; background:#0b1d31eF; padding:clamp(1rem,3vw,2rem); box-shadow:0 24px 70px #0005; }
        label { display:block; margin-bottom:.5rem; color:#c8d9e9; font-size:.9rem; }
        select, textarea { width:100%; color:#e8f2ff; background:#071426; border:1px solid #345674; border-radius:12px; padding:.85rem 1rem; font:inherit; }
        textarea { min-height:110px; resize:vertical; margin-top:1rem; }
        button { margin-top:1rem; border:0; border-radius:12px; padding:.85rem 1.3rem; background:#19b9ed; color:#041522; font-weight:800; font:inherit; cursor:pointer; }
        button:disabled { opacity:.55; cursor:wait; }
        #status { min-height:1.5rem; margin:.8rem 0 0; color:#a9bfd7; font-size:.9rem; }
        #messages { display:grid; gap:1rem; margin-top:1.5rem; }
        .message { white-space:pre-wrap; overflow-wrap:anywhere; padding:1rem; border-radius:14px; line-height:1.6; }
        .user { background:#123653; border:1px solid #245879; } .assistant { background:#10283a; border:1px solid #24506b; }
        .hint { color:#89a5bf; font-size:.85rem; margin-top:1rem; line-height:1.5; }
        @media (max-width:600px) { header { align-items:flex-start; flex-direction:column; } main { margin:2rem auto; } }
    </style>
</head>
<body>
<header><div class="brand">NEXORA</div><div class="tag">NexCore AI · Shared intelligence workspace</div></header>
<main>
    <div class="tag">SECURE WORKSPACE</div>
    <h1>Think clearly. Build boldly.</h1>
    <p class="intro">Ask NexCore AI for practical help across the Nexora ecosystem. Your request is processed within your authenticated organization context. AI responses are advisory and cannot approve or execute privileged actions.</p>
    <section class="panel" aria-label="NexCore AI chat">
        <label for="feature">Choose an assistant</label>
        <select id="feature">
            <option value="general">Nexora Group assistant</option>
            <option value="nexisp.support">NexISP support assistant</option>
            <option value="loopza.marketing">Loopza marketing assistant</option>
        </select>
        <form id="chat-form">
            <label for="message" style="margin-top:1rem">Your message</label>
            <textarea id="message" minlength="2" maxlength="4000" required placeholder="What would you like help with?"></textarea>
            <button id="send" type="submit">Send to NexCore AI</button>
        </form>
        <p id="status" role="status" aria-live="polite"></p>
        <div id="messages" aria-live="polite"></div>
        <p class="hint">Do not enter passwords, payment PINs, one-time codes, router administrator credentials, or confidential customer data. This first chat interface keeps the conversation in this browser page only; it does not save message content to the server.</p>
    </section>
</main>
<script>
(() => {
    const form = document.getElementById('chat-form');
    const input = document.getElementById('message');
    const feature = document.getElementById('feature');
    const send = document.getElementById('send');
    const status = document.getElementById('status');
    const messages = document.getElementById('messages');
    const token = document.querySelector('meta[name="csrf-token"]').content;
    function addMessage(kind, value) {
        const item = document.createElement('div');
        item.className = 'message ' + kind;
        item.textContent = value;
        messages.appendChild(item);
    }
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = input.value.trim();
        if (message.length < 2) return;
        send.disabled = true;
        status.textContent = 'NexCore AI is thinking…';
        addMessage('user', message);
        input.value = '';
        try {
            const response = await fetch('/ai/assist', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ feature: feature.value, message })
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'The request could not be completed (HTTP ' + response.status + ').');
            addMessage('assistant', payload.data?.answer || 'The assistant returned no answer.');
            status.textContent = 'Response received · Request ' + (payload.data?.request_id || 'recorded');
        } catch (error) {
            status.textContent = error.message || 'Unable to reach NexCore AI. Please try again.';
        } finally {
            send.disabled = false;
            input.focus();
        }
    });
})();
</script>
</body>
</html>
