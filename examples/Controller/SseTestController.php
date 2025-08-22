<?php

declare(strict_types=1);

namespace Examples\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class SseTestController {

  /**
   * Simple page with EventSource test for /mcp/sse.
   */
  #[Route('/test/sse', name: 'sse_test', methods: ['GET'])]
  public function index(Request $request): Response {
    $domain = $request->query->get('domain', $request->getHost());
    $html = '<!doctype html><meta charset="utf-8"><title>SSE Test</title>'
      . '<h3>SSE Test</h3>'
      . '<form><label>Domain: <input name="domain" value="' . htmlspecialchars((string) $domain, ENT_QUOTES) . '"></label> <button type="submit">Update</button></form>'
      . '<pre id="out"></pre>'
      . '<script>'
      . 'const out=document.getElementById("out");'
      . 'const es=new EventSource("https://"+location.host+"/mcp/sse");'
      . 'es.addEventListener("endpoint",ev=>{out.textContent+="endpoint: "+ev.data+"\n";});'
      . 'es.addEventListener("message",ev=>{out.textContent+="message: "+ev.data+"\n";});'
      . 'es.onmessage=(ev)=>{out.textContent+="data: "+ev.data+"\n";};'
      . '</script>';
    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
  }
}


