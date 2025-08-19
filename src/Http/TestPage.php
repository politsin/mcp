<?php

declare(strict_types=1);

namespace Politsin\Mcp\Http;

/**
 *
 */
final class TestPage {

  /**
   * Возвращает HTML-страницу теста SSE/MCP для браузера.
   */
  public function render(string $basePath = '/mcp'): string {
    $base = rtrim($basePath, '/');
    $html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>MCP/SSE Test</title>
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
  label.switch { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
  label.switch input { width: 16px; height: 16px; }
  .grid-col { display: flex; flex-direction: column; gap: 12px; }
  .hint { color: #94a3b8; font-size: 12px; }
  .btns { display: flex; gap: 8px; }
  pre { margin: 0; }
</style>
<body>
  <header>
    <div class="row">
      <div class="badge mono">MCP/SSE Test</div>
      <div class="muted">Базовый путь: <span class="mono">$base</span></div>
      <label class="switch mono"><input id="mode" type="checkbox"> MCP mode</label>
    </div>
  </header>
  <main>
    <div class="grid-col">
      <div class="card">
        <div class="section-title">SSE</div>
        <div class="row mt8"><input id="sseUrl" class="mono" type="text" value="$base/sse" /></div>
        <div class="btns mt8"><button id="sseConnect">Подключиться</button><button id="sseDisconnect">Отключиться</button></div>
        <div class="hint mt8">Подписка через EventSource на <span class="mono">text/event-stream</span>.</div>
      </div>
      <div class="card">
        <div class="section-title">MCP API (GET)</div>
        <div class="row mt8"><input id="mcpUrl" class="mono" type="text" value="$base/api?hello=world" /></div>
        <div class="btns mt8"><button id="mcpSend">GET</button></div>
        <div class="hint mt8">GET JSON на MCP API endpoint.</div>
      </div>
    </div>
    <div class="card">
      <div class="section-title">Лог</div>
      <pre id="log" class="mono"></pre>
    </div>
  </main>
  <script>
    const logEl = document.getElementById('log');
    function logLine(kind, ...args) {
      const line = '[' + new Date().toISOString() + '] ' + kind + ' ' + args.join(' ');
      console.log(line);
      logEl.textContent += line + '\n';
      logEl.scrollTop = logEl.scrollHeight;
    }

    // Toggle режимов.
    const modeEl = document.getElementById('mode');
    const sseUrlEl = document.getElementById('sseUrl');
    const mcpUrlEl = document.getElementById('mcpUrl');
    let es = null;

    function sseConnect() {
      const url = sseUrlEl.value || '$base/sse';
      try { if (es) { es.close(); } } catch (e) {}
      logLine('connect', 'SSE ->', url);
      es = new EventSource(url, { withCredentials: true });
      es.onopen = () => logLine('evt', 'open');
      es.onmessage = (e) => logLine('msg', e.data);
      es.onerror = (e) => logLine('err', JSON.stringify(e));
      es.addEventListener('manifest', (e) => logLine('evt', 'manifest', e.data));
      es.addEventListener('ready', (e) => logLine('evt', 'ready', e.data));
      es.addEventListener('tools', (e) => logLine('evt', 'tools', e.data));
    }
    function sseDisconnect() {
      if (es) { es.close(); logLine('disconnect', 'SSE'); }
    }
    document.getElementById('sseConnect').addEventListener('click', sseConnect);
    document.getElementById('sseDisconnect').addEventListener('click', sseDisconnect);

    async function mcpSend() {
      const url = mcpUrlEl.value || '$base/api';
      try {
        logLine('GET', url);
        const res = await fetch(url, { method: 'GET' });
        const text = await res.text();
        logLine('RES', res.status + ':', text.slice(0, 1000));
      } catch (e) { logLine('err', String(e)); }
    }
    document.getElementById('mcpSend').addEventListener('click', () => mcpSend());

    // Старт: если включён MCP mode — ничего не делаем автоматически; иначе автоподключение к SSE.
    if (!modeEl.checked) { sseConnect(); }
  </script>
HTML;
    return $html;
  }

}
