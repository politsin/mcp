<?php

declare(strict_types=1);

namespace Politsin\Mcp\Http;

/**
 * Тестовая HTML-страница для проверки MCP/SSE функциональности.
 */
final class TestPage {

  /**
   * Возвращает HTML-страницу теста SSE/MCP для браузера.
   */
  public function render(string $basePath = '/mcp', ?string $domain = NULL): string {
    $base = rtrim($basePath, '/');
    $domain = $domain ?: 'politsin.ru';
    $html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>MCP/SSE Test v0.1.22</title>
<style>
  html, body { height: 100%; margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
  body { display: flex; flex-direction: column; }
  header { padding: 12px 16px; border-bottom: 1px solid #e5e7eb; }
  main { flex: 1; padding: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  #log { background: #0b1220; color: #d1e7ff; padding: 12px; border-radius: 8px; white-space: pre-wrap; word-break: break-word; max-height: 70vh; overflow: auto; }
  .muted { color: #94a3b8; }
  button { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; cursor: pointer; }
  button:hover { background: #f8fafc; }
  .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  input[type="text"], textarea { width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
  small { color: #64748b; }
  .badge { font-size: 12px; padding: 2px 6px; border-radius: 4px; background: #e2e8f0; color: #334155; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  .mt8 { margin-top: 8px; }
  .mt12 { margin-top: 12px; }
  .mt16 { margin-top: 16px; }
  .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; }
  .section-title { font-weight: 600; margin-bottom: 8px; }
  .grid-col { display: flex; flex-direction: column; gap: 12px; }
  .hint { color: #94a3b8; font-size: 12px; }
  .btns { display: flex; gap: 8px; }
  pre { margin: 0; }
</style>
<body>
  <header>
    <div class="row">
      <div class="badge mono">MCP/SSE Test <span class="ok">v0.1.22</span></div>
      <div class="muted">Базовый путь: <span class="mono">$base</span></div>
    </div>
    <div class="row mt8">
      <label class="mono dim">Домен:</label>
      <input id="domainInput" class="mono" type="text" value="$domain" style="width: 200px;" />
      <button id="updateDomain">Обновить</button>
    </div>
  </header>
  <main>
    <div class="grid-col">
      <div class="card">
        <div class="section-title">HTTP Stream (NDJSON)</div>
        <div class="row mt8"><input id="httpUrl" class="mono" type="text" value="$base/http" /></div>
        <div class="btns mt8"><button id="httpConnect">Подключиться</button><button id="httpDisconnect">Отключиться</button></div>
        <div class="hint mt8">GET поток <span class="mono">application/x-ndjson</span>.</div>
      </div>
      <div class="card">
        <div class="section-title">SSE</div>
        <div class="row mt8"><input id="sseUrl" class="mono" type="text" value="$base/sse" /></div>
        <div class="btns mt8"><button id="sseConnect">Подключиться</button><button id="sseDisconnect">Отключиться</button></div>
        <div class="hint mt8">Подписка через <span class="mono">EventSource</span> на <span class="mono">text/event-stream</span>.</div>
      </div>
      <div class="card">
        <div class="section-title">MCP API (GET)</div>
        <div class="row mt8"><input id="apiUrl" class="mono" type="text" value="$base/api?hello=world" /></div>
        <div class="btns mt8"><button id="apiSend">GET</button></div>
        <div class="hint mt8">GET JSON на MCP API endpoint.</div>
      </div>
    </div>
    <div class="card">
      <div class="row">
        <div class="section-title">Лог</div>
        <button id="clearLog" style="margin-left: auto;">Очистить лог</button>
      </div>
      <pre id="log" class="mono" style="height: 70vh;"></pre>
    </div>
  </main>
  <script>
    const logEl = document.getElementById('log');
    const domainInput = document.getElementById('domainInput');
    // VERSION MARKER
    try { console.log('TEST_PAGE_VERSION v0.1.22'); } catch (e) {}

    // Функция для формирования полного URL с доменом
    function buildUrl(path) {
      const domain = domainInput.value || '$domain';
      const protocol = window.location.protocol;
      return protocol + '//' + domain + path;
    }
    function logLine(kind, ...args) {
      const line = '[' + new Date().toISOString() + '] ' + kind + ' ' + args.join(' ');
      try { console.log(line); } catch(e) {}
      try {
        // Безопасное добавление строки в лог.
        logEl.appendChild(document.createTextNode(line + '\\n'));
        logEl.scrollTop = logEl.scrollHeight;
      } catch(e) {}
    }

    // HTTP stream (NDJSON)
    const httpUrlEl = document.getElementById('httpUrl');
    let httpAbort = null;
    async function httpConnect() {
      const url = buildUrl(httpUrlEl.value || '$base/http');
      logLine('connect', 'HTTP ->', url);
      try {
        httpAbort = new AbortController();
        const res = await fetch(url, { signal: httpAbort.signal });
        if (!res.body) { logLine('err', 'no body'); return; }
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let leftover = '';
        while (true) {
          const { value, done } = await reader.read();
          if (done) break;
          const chunk = decoder.decode(value, { stream: true });
          const text = leftover + chunk;
          const lines = text.split('\\n');
          leftover = lines.pop() || '';
          for (const line of lines) {
            if (line.trim() !== '') logLine('ndjson', line);
          }
        }
        if (leftover.trim() !== '') logLine('ndjson', leftover);
      } catch (e) { logLine('err', String(e)); }
    }
    function httpDisconnect() { try { if (httpAbort) httpAbort.abort(); } catch(e) {}; logLine('disconnect', 'HTTP'); }
    document.getElementById('httpConnect').addEventListener('click', httpConnect);
    document.getElementById('httpDisconnect').addEventListener('click', httpDisconnect);

    // SSE
    const sseUrlEl = document.getElementById('sseUrl');
    let es = null;
    function sseConnect() {
      const url = buildUrl(sseUrlEl.value || '$base/sse');
      try { if (es) es.close(); } catch(e) {}
      logLine('connect', 'SSE ->', url);
      
      // Сначала проверяем CORS заголовки
      fetch(url, { method: 'HEAD' })
        .then(res => {
          logLine('CORS-CHECK', 'Status:', res.status);
          logLine('CORS-CHECK', 'Access-Control-Allow-Origin:', res.headers.get('Access-Control-Allow-Origin') || 'NOT SET');
          logLine('CORS-CHECK', 'Access-Control-Allow-Credentials:', res.headers.get('Access-Control-Allow-Credentials') || 'NOT SET');
          logLine('CORS-CHECK', 'Access-Control-Allow-Headers:', res.headers.get('Access-Control-Allow-Headers') || 'NOT SET');
          logLine('CORS-CHECK', 'Access-Control-Allow-Methods:', res.headers.get('Access-Control-Allow-Methods') || 'NOT SET');
          logLine('CORS-CHECK', 'Access-Control-Expose-Headers:', res.headers.get('Access-Control-Expose-Headers') || 'NOT SET');
        })
        .catch(e => {
          logLine('CORS-ERROR', 'HEAD request failed:', String(e));
        });
      
      es = new EventSource(url, { withCredentials: true });
      es.onopen = () => logLine('evt', 'open');
      es.onmessage = (e) => logLine('msg', e.data);
      es.onerror = (e) => {
        logLine('err', 'SSE Error:', JSON.stringify(e));
        // Дополнительная диагностика CORS
        if (e.target && e.target.readyState === EventSource.CONNECTING) {
          logLine('CORS-ERROR', 'Connection failed - likely CORS issue');
          logLine('CORS-ERROR', 'Check browser console for detailed CORS error');
        }
      };
      es.addEventListener('manifest', (e) => logLine('evt', 'manifest', e.data));
      es.addEventListener('ready', (e) => logLine('evt', 'ready', e.data));
      es.addEventListener('tools', (e) => logLine('evt', 'tools', e.data));
    }
    function sseDisconnect() { try { if (es) es.close(); } catch(e) {} logLine('disconnect', 'SSE'); }
    document.getElementById('sseConnect').addEventListener('click', sseConnect);
    document.getElementById('sseDisconnect').addEventListener('click', sseDisconnect);

    // API GET
    const apiUrlEl = document.getElementById('apiUrl');
    async function apiSend() {
      const url = buildUrl(apiUrlEl.value || '$base/api');
      logLine('GET', url);
      try {
        const res = await fetch(url, { method: 'GET' });
        const text = await res.text();
        logLine('RES', res.status + ':', text.slice(0, 1000));
      } catch (e) { logLine('err', String(e)); }
    }
    document.getElementById('apiSend').addEventListener('click', apiSend);

    // Обработчик обновления домена
    document.getElementById('updateDomain').addEventListener('click', function() {
      logLine('info', 'Домен обновлен на:', domainInput.value);
      // Переподключаем SSE с новым доменом
      sseConnect();
    });

    // Обработчик очистки лога
    document.getElementById('clearLog').addEventListener('click', function() {
      logEl.textContent = '';
      logLine('info', 'Лог очищен');
    });

    // Автоподключение SSE при загрузке
    sseConnect();
  </script>
HTML;
    return $html;
  }

}
