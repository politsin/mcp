<?php

/**
 * @file
 * Runner.
 */

declare(strict_types=1);

/**
 * Демон для запуска React MCP сервера.
 */

// Запускаем команду напрямую.
$_SERVER['argv'] = ['./symfony', 'app:react'];
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../../app/symfony';
$_SERVER['SHELL'] = TRUE;
require_once __DIR__ . '/../../app/symfony';
