<?php

/**
 * LLM-driven MCP persona harness.
 *
 * Runs a real LLM (Ollama) against the live OpenRegister MCP endpoint,
 * gives it a persona + a high-level journey goal, lets it call tools
 * iteratively, and asserts the journey ended in the expected DB state.
 *
 * Why this exists: scripted MCP smoke tests prove the tool surface works,
 * but they don't tell you whether an LLM can DISCOVER + USE the tools given
 * only natural language. This harness does.
 *
 * Usage:
 *   php tests/mcp-personas/harness.php scenarios/secretary-creates-meeting.json
 *
 * Scenario file shape (JSON):
 *   {
 *     "name": "Secretary creates meeting with agenda + invites",
 *     "persona": "You are a council secretary preparing tomorrow's meeting.",
 *     "goal": "Create a meeting titled '...' on 2026-06-25 at 10:00 in digital mode...",
 *     "max_turns": 8,
 *     "ollama_model": "qwen3.5-optimized:latest",
 *     "asserts": [
 *       { "type": "tool_called", "tool": "decidesk.createMeeting" },
 *       { "type": "tool_called", "tool": "decidesk.addAgendaItem", "min_calls": 2 },
 *       { "type": "tool_called", "tool": "decidesk.inviteParticipant", "min_calls": 1 },
 *       { "type": "db_row_exists", "table": "oc_openregister_table_18_145",
 *         "where": "title LIKE 'Council strategy retreat%'" }
 *     ]
 *   }
 *
 * Designed to be a single self-contained PHP script — no NC bootstrap, no
 * composer autoload. Runs against the live MCP endpoint via JSON-RPC.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Config (env-overridable).
// -----------------------------------------------------------------------------
$config = [
	'mcp_url' => getenv('MCP_URL') ?: 'http://localhost:8080/index.php/apps/openregister/api/mcp',
	'mcp_user' => getenv('MCP_USER') ?: 'admin',
	'mcp_pass' => getenv('MCP_PASS') ?: 'admin',
	'ollama_url' => getenv('OLLAMA_URL') ?: 'http://localhost:11434',
	'ollama_model' => getenv('OLLAMA_MODEL') ?: 'qwen3.5-optimized:latest',
	'pg_container' => getenv('PG_CONTAINER') ?: 'openregister-postgres',
	'pg_user' => getenv('PG_USER') ?: 'oc_admin',
	'pg_db' => getenv('PG_DB') ?: 'nextcloud',
	'pg_password' => getenv('PG_PASSWORD') ?: 'nextcloud',
	'verbose' => (bool)(getenv('VERBOSE') ?: false),
];

// -----------------------------------------------------------------------------
// Entrypoint.
// -----------------------------------------------------------------------------
$args = $argv;
array_shift($args);
if (count($args) === 0) {
	fwrite(STDERR, "usage: harness.php <scenario.json> [scenario2.json ...]\n");
	exit(2);
}

$totalFail = 0;
foreach ($args as $scenarioPath) {
	if (!is_file($scenarioPath)) {
		fwrite(STDERR, "ERROR: scenario file not found: $scenarioPath\n");
		$totalFail++;
		continue;
	}
	$scenario = json_decode(file_get_contents($scenarioPath), true);
	if (!is_array($scenario)) {
		fwrite(STDERR, "ERROR: invalid scenario JSON: $scenarioPath\n");
		$totalFail++;
		continue;
	}
	$totalFail += runScenario($scenario, $config) ? 0 : 1;
}

exit($totalFail === 0 ? 0 : 1);

// -----------------------------------------------------------------------------
// Scenario runner.
// -----------------------------------------------------------------------------

function runScenario(array $scenario, array $cfg): bool {
	$name = $scenario['name'] ?? '(unnamed)';
	echo "\n══ Scenario: $name ══\n";

	$maxTurns = (int)($scenario['max_turns'] ?? 8);
	$persona = (string)($scenario['persona'] ?? '');
	$goal = (string)($scenario['goal'] ?? '');
	$model = (string)($scenario['ollama_model'] ?? $cfg['ollama_model']);

	if ($goal === '') {
		echo "  ✗ scenario has no `goal`\n";
		return false;
	}

	// 0. Optional pre-test cleanup. Runs each scenario's `pre_sql` (a list of
	// statements) against the openregister postgres before the LLM kicks off,
	// so tests that assert "row exists with slug X" don't conflict with prior
	// runs. Failures are surfaced but don't abort the scenario — the asserts
	// will fail loudly anyway.
	$preSql = $scenario['pre_sql'] ?? [];
	if (is_array($preSql) && $preSql !== []) {
		foreach ($preSql as $stmt) {
			if (!is_string($stmt) || trim($stmt) === '') {
				continue;
			}
			$rc = pgExec($cfg, $stmt);
			echo '  ↺ pre_sql: ' . substr($stmt, 0, 80) . ($rc ? '' : ' [FAILED]') . "\n";
		}
	}

	// 1. MCP session + tools list
	$session = mcpInit($cfg);
	if ($session === null) {
		echo "  ✗ MCP init failed\n";
		return false;
	}
	echo '  ✓ MCP session: ' . substr($session, 0, 12) . "…\n";

	$tools = mcpListTools($cfg, $session);
	if ($tools === null) {
		echo "  ✗ MCP tools/list failed\n";
		return false;
	}
	// De-duplicate by id — some apps appear via both alias and FQCN.
	$byId = [];
	foreach ($tools as $t) {
		$id = (string)($t['id'] ?? $t['name'] ?? '');
		if ($id !== '') {
			$byId[$id] = $t;
		}
	}
	$tools = array_values($byId);
	echo '  ✓ tools available: ' . count($tools) . "\n";

	// 2. Build Ollama messages: system (persona) + user (goal)
	$messages = [];
	if ($persona !== '') {
		$messages[] = ['role' => 'system', 'content' => $persona];
	}
	$messages[] = ['role' => 'user', 'content' => $goal];

	$ollamaTools = mcpToolsToOllama($tools);

	// 3. Loop: LLM → tool_call(s) → execute → tool_result → LLM …
	$toolCallLog = [];
	for ($turn = 1; $turn <= $maxTurns; $turn++) {
		echo "  → turn $turn\n";
		$resp = ollamaChat($cfg, $model, $messages, $ollamaTools);
		if ($resp === null) {
			echo "    ✗ ollama call failed\n";
			return false;
		}
		$assistantMsg = $resp['message'] ?? [];
		$messages[] = $assistantMsg;

		$toolCalls = $assistantMsg['tool_calls'] ?? [];
		if (empty($toolCalls)) {
			// Final assistant text reply — end of journey.
			$text = (string)($assistantMsg['content'] ?? '');
			echo '    ↳ final reply (' . strlen($text) . ' chars): ' . substr($text, 0, 160) . (strlen($text) > 160 ? '…' : '') . "\n";
			break;
		}

		foreach ($toolCalls as $tc) {
			$fn = $tc['function'] ?? [];
			$rawName = (string)($fn['name'] ?? '');
			$rawArgs = $fn['arguments'] ?? [];
			$argsArr = is_string($rawArgs) ? (json_decode($rawArgs, true) ?? []) : (array)$rawArgs;
			$canonical = ollamaToolNameToMcp($rawName, $tools);

			echo "    ↳ tool_call $rawName → mcp:$canonical args=" . json_encode($argsArr) . "\n";

			$result = mcpCallTool($cfg, $session, $canonical, $argsArr);
			$isError = (bool)($result['isError'] ?? false);
			$resultText = json_encode($result ?? ['error' => 'no result']);
			if ($cfg['verbose'] || $isError) {
				echo '       ⇐ ' . ($isError ? '✗ ' : '✓ ') . substr($resultText, 0, 240) . "\n";
			}

			// Record call WITH success flag so asserts can distinguish
			// "tool was called" from "tool actually worked".
			$toolCallLog[] = [
				'name' => $canonical,
				'args' => $argsArr,
				'isError' => $isError,
				'result' => $result,
			];

			$messages[] = [
				'role' => 'tool',
				'content' => $resultText,
				'name' => $rawName,
			];
		}
	}

	// 4. Run asserts
	$asserts = $scenario['asserts'] ?? [];
	if (empty($asserts)) {
		echo "  ⚠ scenario has no asserts — pass by default\n";
		return true;
	}

	$ok = true;
	foreach ($asserts as $i => $a) {
		$type = (string)($a['type'] ?? '');
		$pass = false;
		$msg = '';
		switch ($type) {
			case 'tool_called':
				$tool = (string)($a['tool'] ?? '');
				$minCalls = (int)($a['min_calls'] ?? 1);
				$count = 0;
				foreach ($toolCallLog as $tc) {
					if ($tc['name'] === $tool) {
						$count++;
					}
				}
				$pass = $count >= $minCalls;
				$msg = "tool_called $tool ≥ $minCalls (got $count)";
				break;

			case 'tool_succeeded':
				// Stronger than tool_called: requires at least one call that
				// did NOT return isError. Catches the case where the LLM
				// dutifully called the tool but every call failed.
				$tool = (string)($a['tool'] ?? '');
				$minCalls = (int)($a['min_calls'] ?? 1);
				$okCalls = 0;
				$errCalls = 0;
				foreach ($toolCallLog as $tc) {
					if ($tc['name'] !== $tool) {
						continue;
					}
					if ($tc['isError']) {
						$errCalls++;
					} else {
						$okCalls++;
					}
				}
				$pass = $okCalls >= $minCalls;
				$msg = "tool_succeeded $tool ≥ $minCalls (ok=$okCalls err=$errCalls)";
				break;

			case 'no_tool_errors':
				// Catches LLM journeys that "limp through" with isError on
				// every call but still hit min_call counts. Use sparingly:
				// some scenarios expect a few graceful rejections.
				$bad = 0;
				foreach ($toolCallLog as $tc) {
					if ($tc['isError']) {
						$bad++;
					}
				}
				$pass = $bad === 0;
				$msg = "no_tool_errors (got $bad failing calls)";
				break;

			case 'db_row_exists':
				$table = (string)($a['table'] ?? '');
				$where = (string)($a['where'] ?? '1=1');
				$count = pgScalar($cfg, "SELECT COUNT(*) FROM \"$table\" WHERE $where");
				$pass = $count !== null && (int)$count > 0;
				$msg = "db_row_exists $table WHERE $where (count=$count)";
				break;

			case 'db_count':
				$table = (string)($a['table'] ?? '');
				$where = (string)($a['where'] ?? '1=1');
				$min = (int)($a['min'] ?? 1);
				$count = (int)(pgScalar($cfg, "SELECT COUNT(*) FROM \"$table\" WHERE $where") ?? 0);
				$pass = $count >= $min;
				$msg = "db_count $table WHERE $where ≥ $min (got $count)";
				break;

			default:
				$msg = "unknown assert type: $type";
				$pass = false;
				break;
		}
		echo '    ' . ($pass ? '✓' : '✗') . " [#$i] $msg\n";
		if (!$pass) {
			$ok = false;
		}
	}

	return $ok;
}

// -----------------------------------------------------------------------------
// MCP JSON-RPC client.
// -----------------------------------------------------------------------------

function mcpInit(array $cfg): ?string {
	[$status, $hdrs, $_] = httpCall(
		$cfg['mcp_url'],
		'POST',
		['Content-Type: application/json', 'OCS-APIREQUEST: true'],
		json_encode([
			'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize',
			'params' => [
				'protocolVersion' => '2024-11-05',
				'capabilities' => new stdClass(),
				'clientInfo' => ['name' => 'persona-harness', 'version' => '1'],
			],
		]),
		$cfg['mcp_user'] . ':' . $cfg['mcp_pass'],
	);
	if ($status !== 200) {
		return null;
	}
	foreach ($hdrs as $h) {
		if (stripos($h, 'Mcp-Session-Id:') === 0) {
			return trim(substr($h, strlen('Mcp-Session-Id:')));
		}
	}
	return null;
}

function mcpListTools(array $cfg, string $session): ?array {
	[$status, $_, $body] = httpCall(
		$cfg['mcp_url'],
		'POST',
		['Content-Type: application/json', 'OCS-APIREQUEST: true', "Mcp-Session-Id: $session"],
		json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
		$cfg['mcp_user'] . ':' . $cfg['mcp_pass'],
	);
	if ($status !== 200) {
		return null;
	}
	$r = json_decode($body, true);
	return $r['result']['tools'] ?? null;
}

function mcpCallTool(array $cfg, string $session, string $name, array $arguments): array {
	[$status, $_, $body] = httpCall(
		$cfg['mcp_url'],
		'POST',
		['Content-Type: application/json', 'OCS-APIREQUEST: true', "Mcp-Session-Id: $session"],
		json_encode([
			'jsonrpc' => '2.0', 'id' => random_int(100, 999999), 'method' => 'tools/call',
			'params' => ['name' => $name, 'arguments' => (object)$arguments],
		]),
		$cfg['mcp_user'] . ':' . $cfg['mcp_pass'],
	);
	if ($status !== 200) {
		return ['isError' => true, 'message' => "MCP HTTP $status: " . substr($body, 0, 200)];
	}
	$r = json_decode($body, true);
	$contentText = (string)($r['result']['content'][0]['text'] ?? '{}');
	$inner = json_decode($contentText, true);
	return is_array($inner) ? $inner : ['raw' => $contentText];
}

// -----------------------------------------------------------------------------
// Ollama tool/chat helpers.
// -----------------------------------------------------------------------------

function mcpToolsToOllama(array $mcpTools): array {
	$out = [];
	foreach ($mcpTools as $t) {
		$rawId = (string)($t['id'] ?? $t['name'] ?? '');
		if ($rawId === '') {
			continue;
		}
		// Ollama is fine with dots in function names; keep raw.
		$out[] = [
			'type' => 'function',
			'function' => [
				'name' => $rawId,
				'description' => (string)($t['description'] ?? $t['name'] ?? ''),
				'parameters' => sanitiseSchema($t['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass()]),
			],
		];
	}
	return $out;
}

function sanitiseSchema(array $schema): array {
	if (isset($schema['type']) && is_array($schema['type'])) {
		$schema['type'] = collapseType($schema['type']);
	}
	if (isset($schema['properties']) && is_array($schema['properties'])) {
		foreach ($schema['properties'] as $name => $prop) {
			if (is_array($prop) && isset($prop['type']) && is_array($prop['type'])) {
				$schema['properties'][$name]['type'] = collapseType($prop['type']);
			}
		}
	}
	return $schema;
}

function collapseType(array $types): string {
	foreach ($types as $t) {
		if (is_string($t) && $t !== 'null') {
			return $t;
		}
	}
	return 'string';
}

function ollamaToolNameToMcp(string $name, array $mcpTools): string {
	foreach ($mcpTools as $t) {
		$rawId = (string)($t['id'] ?? $t['name'] ?? '');
		if ($rawId === $name || str_replace('.', '_', $rawId) === $name) {
			return $rawId;
		}
	}
	return $name;
}

function ollamaChat(array $cfg, string $model, array $messages, array $tools): ?array {
	$body = [
		'model' => $model,
		'stream' => false,
		'messages' => $messages,
		'think' => false,
		'keep_alive' => -1,
	];
	if (!empty($tools)) {
		$body['tools'] = $tools;
	}
	[$status, $_, $resp] = httpCall(
		rtrim($cfg['ollama_url'], '/') . '/api/chat',
		'POST',
		['Content-Type: application/json'],
		json_encode($body),
		null,
	);
	if ($status !== 200) {
		fwrite(STDERR, "ollama HTTP $status: " . substr($resp, 0, 300) . "\n");
		return null;
	}
	return json_decode($resp, true);
}

// -----------------------------------------------------------------------------
// HTTP + DB helpers.
// -----------------------------------------------------------------------------

function httpCall(string $url, string $method, array $headers, string $body, ?string $userpass): array {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => $body,
		CURLOPT_TIMEOUT => 180,
	]);
	if ($userpass !== null) {
		curl_setopt($ch, CURLOPT_USERPWD, $userpass);
	}
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	if ($raw === false) {
		return [0, [], ''];
	}
	$headBlob = substr($raw, 0, $hdrSize);
	$bodyOut = substr($raw, $hdrSize);
	$hdrLines = preg_split("/\r?\n/", trim($headBlob));
	return [(int)$status, $hdrLines, $bodyOut];
}

function pgScalar(array $cfg, string $sql): ?string {
	$cmd = sprintf(
		'docker exec -e PGPASSWORD=%s %s psql -U %s -d %s -tAc %s 2>/dev/null',
		escapeshellarg($cfg['pg_password']),
		escapeshellarg($cfg['pg_container']),
		escapeshellarg($cfg['pg_user']),
		escapeshellarg($cfg['pg_db']),
		escapeshellarg($sql),
	);
	$out = shell_exec($cmd);
	return $out === null ? null : trim($out);
}

/**
 * Execute a SQL statement, return true on success.
 *
 * Used by scenario pre_sql hooks for idempotent cleanup before LLM-driven
 * test runs.
 */
function pgExec(array $cfg, string $sql): bool {
	$cmd = sprintf(
		'docker exec -e PGPASSWORD=%s %s psql -U %s -d %s -c %s >/dev/null 2>&1',
		escapeshellarg($cfg['pg_password']),
		escapeshellarg($cfg['pg_container']),
		escapeshellarg($cfg['pg_user']),
		escapeshellarg($cfg['pg_db']),
		escapeshellarg($sql),
	);
	$rc = null;
	system($cmd, $rc);
	return $rc === 0;
}
