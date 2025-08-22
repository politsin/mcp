<?php

declare(strict_types=1);

namespace Politsin\Mcp\Session;

use Politsin\Mcp\Config\McpConfig;

/**
 * Менеджер сессий MCP с поддержкой файлового и Redis хранилища.
 */
final class SessionManager {
  private McpConfig $config;
  private ?\Redis $redis = NULL;

  public function __construct(McpConfig $config) {
    $this->config = $config;
    $this->initStorage();
  }

  /**
   * Инициализирует хранилище сессий.
   */
  private function initStorage(): void {
    if ($this->config->sessionStorage === 'redis' && $this->config->redisHost !== NULL) {
      $this->redis = new \Redis();
      $this->redis->connect($this->config->redisHost, $this->config->redisPort);
      $this->redis->select($this->config->redisDb);
    }
    elseif ($this->config->sessionStorage === 'file') {
      if (!is_dir($this->config->sessionPath)) {
        mkdir($this->config->sessionPath, 0755, TRUE);
      }
    }
  }

  /**
   * Создает новую сессию.
   */
  public function createSession(string $sessionId, array $data = []): void {
    $sessionData = [
      'id' => $sessionId,
      'created' => time(),
      'last_activity' => time(),
      'data' => $data,
    ];

    if ($this->config->sessionStorage === 'redis' && $this->redis !== NULL) {
      $this->redis->setex("mcp:session:{$sessionId}", 3600, json_encode($sessionData));
    }
    else {
      $filePath = $this->config->sessionPath . "/{$sessionId}.json";
      file_put_contents($filePath, json_encode($sessionData, JSON_UNESCAPED_UNICODE));
    }
  }

  /**
   * Получает данные сессии.
   */
  public function getSession(string $sessionId): ?array {
    if ($this->config->sessionStorage === 'redis' && $this->redis !== NULL) {
      $data = $this->redis->get("mcp:session:{$sessionId}");
      if ($data === FALSE) {
        return NULL;
      }
      return json_decode($data, TRUE);
    }
    else {
      $filePath = $this->config->sessionPath . "/{$sessionId}.json";
      if (!file_exists($filePath)) {
        return NULL;
      }
      $data = file_get_contents($filePath);
      if ($data === FALSE) {
        return NULL;
      }
      return json_decode($data, TRUE);
    }
  }

  /**
   * Обновляет данные сессии.
   */
  public function updateSession(string $sessionId, array $data): bool {
    $session = $this->getSession($sessionId);
    if ($session === NULL) {
      return FALSE;
    }

    $session['last_activity'] = time();
    $session['data'] = array_merge($session['data'] ?? [], $data);

    if ($this->config->sessionStorage === 'redis' && $this->redis !== NULL) {
      $this->redis->setex("mcp:session:{$sessionId}", 3600, json_encode($session));
    }
    else {
      $filePath = $this->config->sessionPath . "/{$sessionId}.json";
      file_put_contents($filePath, json_encode($session, JSON_UNESCAPED_UNICODE));
    }

    return TRUE;
  }

  /**
   * Удаляет сессию.
   */
  public function deleteSession(string $sessionId): bool {
    if ($this->config->sessionStorage === 'redis' && $this->redis !== NULL) {
      return $this->redis->del("mcp:session:{$sessionId}") > 0;
    }
    else {
      $filePath = $this->config->sessionPath . "/{$sessionId}.json";
      if (file_exists($filePath)) {
        return unlink($filePath);
      }
      return FALSE;
    }
  }

  /**
   * Проверяет существование сессии.
   */
  public function sessionExists(string $sessionId): bool {
    if ($this->config->sessionStorage === 'redis' && $this->redis !== NULL) {
      return $this->redis->exists("mcp:session:{$sessionId}");
    }
    else {
      $filePath = $this->config->sessionPath . "/{$sessionId}.json";
      return file_exists($filePath);
    }
  }

  /**
   * Очищает устаревшие сессии (старше 1 часа).
   */
  public function cleanupOldSessions(): void {
    $cutoff = time() - 3600;

    if ($this->config->sessionStorage === 'redis' && $this->redis !== NULL) {
      $keys = $this->redis->keys('mcp:session:*');
      foreach ($keys as $key) {
        $data = $this->redis->get($key);
        if ($data !== FALSE) {
          $session = json_decode($data, TRUE);
          if ($session !== NULL && ($session['last_activity'] ?? 0) < $cutoff) {
            $this->redis->del($key);
          }
        }
      }
    }
    else {
      $files = glob($this->config->sessionPath . '/*.json');
      foreach ($files as $file) {
        $data = file_get_contents($file);
        if ($data !== FALSE) {
          $session = json_decode($data, TRUE);
          if ($session !== NULL && ($session['last_activity'] ?? 0) < $cutoff) {
            unlink($file);
          }
        }
      }
    }
  }

  /**
   * Получает статистику сессий.
   */
  public function getSessionStats(): array {
    $stats = [
      'total' => 0,
      'active' => 0,
      'storage' => $this->config->sessionStorage,
    ];

    if ($this->config->sessionStorage === 'redis' && $this->redis !== NULL) {
      $keys = $this->redis->keys('mcp:session:*');
      $stats['total'] = count($keys);
      // 5 минут
      $cutoff = time() - 300;
      foreach ($keys as $key) {
        $data = $this->redis->get($key);
        if ($data !== FALSE) {
          $session = json_decode($data, TRUE);
          if ($session !== NULL && ($session['last_activity'] ?? 0) > $cutoff) {
            $stats['active']++;
          }
        }
      }
    }
    else {
      $files = glob($this->config->sessionPath . '/*.json');
      $stats['total'] = count($files);
      // 5 минут
      $cutoff = time() - 300;
      foreach ($files as $file) {
        $data = file_get_contents($file);
        if ($data !== FALSE) {
          $session = json_decode($data, TRUE);
          if ($session !== NULL && ($session['last_activity'] ?? 0) > $cutoff) {
            $stats['active']++;
          }
        }
      }
    }

    return $stats;
  }

}
