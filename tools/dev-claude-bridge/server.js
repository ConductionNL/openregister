// Local-dev Claude CLI bridge for OpenRegister chat.
//
// Spawns ONE persistent `claude -p --input-format stream-json
// --output-format stream-json` subprocess with `--mcp-config` pointing
// at OR's /api/mcp HTTP endpoint, so Claude calls OR's tools (registers,
// schemas, objects, plus every app's IMcpToolProvider) directly. The
// expensive CLI bootstrap (MCP discovery + OAuth handshake, ~10s) is
// paid once at bridge startup; per-turn latency is ~2-3s.
//
// LIMITATIONS (read tools/dev-claude-bridge/README.md before relying on this):
//   - Single shared Claude session across all incoming requests.
//   - All tool calls run under whatever credentials the bridge uses
//     (OR_USER / OR_PASS env vars, defaults admin:admin) — local dev only.
//   - Subscription quota burn per turn.

const http = require('node:http');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

const PORT = Number(process.env.PORT || 11500);
const CLAUDE_BIN = process.env.CLAUDE_BIN || 'claude';
const DEFAULT_MODEL = process.env.CLAUDE_MODEL || 'sonnet';
const OR_BASE_URL = process.env.OR_BASE_URL || 'http://localhost:8080';
const OR_USER = process.env.OR_USER || 'admin';
const OR_PASS = process.env.OR_PASS || 'admin';
const OR_MCP_URL = `${OR_BASE_URL}/index.php/apps/openregister/api/mcp`;
const OR_AUTH = `Basic ${Buffer.from(`${OR_USER}:${OR_PASS}`).toString('base64')}`;

// Built-in Claude Code tools we DON'T want the LLM reaching for. We allow
// the discovered `mcp__openregister__*` tools explicitly via --allowedTools;
// everything else gets denied. Keep this list narrow — anything not on
// either list defaults to allowed, which means a CLI upgrade adding new
// built-ins could surprise us.
const BUILTIN_DISALLOW = [
	'Bash', 'Read', 'Write', 'Edit', 'NotebookEdit',
	'Task', 'TaskOutput', 'TaskStop',
	'WebFetch', 'WebSearch',
	'ToolSearch', 'Skill', 'TodoWrite',
	'ScheduleWakeup', 'PushNotification', 'RemoteTrigger',
	'CronCreate', 'CronList', 'CronDelete',
	'AskUserQuestion', 'EnterPlanMode', 'ExitPlanMode',
	'EnterWorktree', 'ExitWorktree',
	'ShareOnboardingGuide',
	'ListMcpResourcesTool', 'ReadMcpResourceTool',
];

/**
 * Hit OR's MCP HTTP endpoint to discover available tools so we can pass
 * each one explicitly to --allowedTools. Returns an array of full tool
 * names like ["mcp__openregister__registers", ...]. Falls back to an
 * empty list (i.e., no MCP tools available) on error — bridge still boots.
 */
async function discoverOrTools() {
	try {
		const initBody = {
			jsonrpc: '2.0', id: 1, method: 'initialize',
			params: {
				protocolVersion: '2024-11-05',
				capabilities: {},
				clientInfo: { name: 'claude-bridge', version: '1.0' },
			},
		};
		const initRes = await fetch(OR_MCP_URL, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Authorization': OR_AUTH,
				'OCS-APIREQUEST': 'true',
				'Mcp-Session-Id': 'claude-bridge-discover',
			},
			body: JSON.stringify(initBody),
		});
		if (!initRes.ok) throw new Error(`init HTTP ${initRes.status}`);
		const sessionId = initRes.headers.get('mcp-session-id') || 'claude-bridge-discover';

		const listRes = await fetch(OR_MCP_URL, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Authorization': OR_AUTH,
				'OCS-APIREQUEST': 'true',
				'Mcp-Session-Id': sessionId,
			},
			body: JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list' }),
		});
		if (!listRes.ok) throw new Error(`tools/list HTTP ${listRes.status}`);
		const listJson = await listRes.json();
		// OR's MCP server now emits `name` as a slugified, MCP-valid
		// identifier (no spaces, no dots — first `_` reversible back to
		// `.` for canonical lookup). We use that name as-is to build
		// Claude's `mcp__<server>__<name>` allowlist entry.
		const tools = (listJson.result?.tools || []).map(t => `mcp__openregister__${t.name}`);
		console.log(`[discover] OR MCP exposes ${tools.length} tools: ${tools.slice(0,4).join(', ')}…`);
		return tools;
	} catch (e) {
		console.error(`[discover] FAILED — Claude will run without OR tools: ${e.message}`);
		return [];
	}
}

class ClaudeWorker {
	constructor(orToolNames) {
		this.proc = null;
		this.sessionId = null;
		this.buffer = '';
		this.queue = [];
		this.current = null;
		this.startedAt = null;
		this.orToolNames = orToolNames;
		this.mcpConfigPath = this.writeMcpConfig();
		this.spawn();
	}

	writeMcpConfig() {
		const config = {
			mcpServers: {
				openregister: {
					type: 'http',
					url: OR_MCP_URL,
					headers: {
						'Authorization': OR_AUTH,
						'OCS-APIREQUEST': 'true',
					},
				},
			},
		};
		const tmpPath = path.join(os.tmpdir(), 'claude-bridge-mcp.json');
		fs.writeFileSync(tmpPath, JSON.stringify(config, null, 2));
		console.log(`[mcp-config] wrote ${tmpPath}`);
		return tmpPath;
	}

	spawn() {
		const allowed = this.orToolNames;
		const args = [
			'-p',
			'--no-session-persistence',
			'--model', DEFAULT_MODEL,
			'--strict-mcp-config',
			'--mcp-config', this.mcpConfigPath,
			'--input-format', 'stream-json',
			'--output-format', 'stream-json',
			'--verbose',
		];
		if (allowed.length > 0) {
			args.push('--allowedTools', ...allowed);
		}
		if (BUILTIN_DISALLOW.length > 0) {
			args.push('--disallowedTools', ...BUILTIN_DISALLOW);
		}
		console.log(`[claude] spawning: ${CLAUDE_BIN} ${args.join(' ')}`);
		this.startedAt = Date.now();
		this.proc = spawn(CLAUDE_BIN, args, { stdio: ['pipe', 'pipe', 'pipe'] });

		this.proc.stdout.on('data', (chunk) => {
			this.buffer += chunk.toString('utf8');
			let nl;
			while ((nl = this.buffer.indexOf('\n')) >= 0) {
				const line = this.buffer.slice(0, nl).trim();
				this.buffer = this.buffer.slice(nl + 1);
				if (line) this.handleLine(line);
			}
		});
		this.proc.stderr.on('data', (c) => {
			process.stderr.write(`[claude stderr] ${c}`);
		});
		this.proc.on('exit', (code) => {
			console.log(`[claude] subprocess exited code=${code}, respawning in 1s`);
			this.proc = null;
			if (this.current) {
				this.current.reject(new Error(`claude subprocess died (code ${code})`));
				this.current = null;
			}
			setTimeout(() => this.spawn(), 1000);
		});
	}

	handleLine(line) {
		let evt;
		try { evt = JSON.parse(line); } catch { return; }
		if (evt.type === 'system' && evt.subtype === 'init') {
			this.sessionId = evt.session_id;
			const mcp = evt.mcp_servers || [];
			console.log(`[claude session] mcp_servers=${JSON.stringify(mcp)}, allowedTools=${(evt.tools || []).length}`);
		}
		// Collect tool_use / tool_result events for diagnostics & for the
		// final response envelope.
		if (this.current && evt.type === 'assistant') {
			for (const block of (evt.message?.content || [])) {
				if (block.type === 'tool_use') {
					this.current.toolCalls.push({
						id: block.id,
						name: block.name,
						arguments: block.input || {},
					});
				}
			}
		}
		if (this.current && evt.type === 'user') {
			// claude relays tool_result content back into the stream as a
			// "user" message — surface those too.
			for (const block of (evt.message?.content || [])) {
				if (block.type === 'tool_result') {
					const matching = this.current.toolCalls.find(t => t.id === block.tool_use_id);
					const name = matching?.name || 'unknown';
					this.current.toolResults.push({
						id: block.tool_use_id,
						name,
						isError: !!block.is_error,
						content: block.content,
					});
				}
			}
		}
		if (this.current && evt.type === 'result') {
			const { resolve } = this.current;
			const final = {
				...evt,
				toolCalls: this.current.toolCalls,
				toolResults: this.current.toolResults,
			};
			this.current = null;
			resolve(final);
			this.pump();
		}
	}

	pump() {
		if (this.current || this.queue.length === 0 || !this.proc) return;
		const next = this.queue.shift();
		this.current = { ...next, toolCalls: [], toolResults: [] };
		try {
			this.proc.stdin.write(next.line + '\n');
		} catch (e) {
			this.current = null;
			next.reject(e);
		}
	}

	async send(systemPrompt, userText) {
		const combined = systemPrompt
			? `[SYSTEM CONTEXT]\n${systemPrompt}\n\n[USER]\n${userText}`
			: userText;
		const line = JSON.stringify({
			type: 'user',
			message: {
				role: 'user',
				content: [{ type: 'text', text: combined }],
			},
		});
		return new Promise((resolve, reject) => {
			this.queue.push({ line, resolve, reject });
			this.pump();
		});
	}
}

let worker; // populated after discovery completes

function readBody(req) {
	return new Promise((resolve, reject) => {
		const chunks = [];
		req.on('data', (c) => chunks.push(c));
		req.on('end', () => {
			try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}')); }
			catch (e) { reject(e); }
		});
		req.on('error', reject);
	});
}

function flattenMessages(messages) {
	const systemParts = [];
	const transcript = [];
	for (const m of messages || []) {
		const role = typeof m.role === 'string' ? m.role : (m.role?.value || m.role?.name || '').toLowerCase();
		const content = typeof m.content === 'string' ? m.content : JSON.stringify(m.content ?? '');
		if (role === 'system') systemParts.push(content);
		else if (role === 'user') transcript.push(`User: ${content}`);
		else if (role === 'assistant') transcript.push(`Assistant: ${content}`);
	}
	return { systemPrompt: systemParts.join('\n\n'), userPrompt: transcript.join('\n\n') };
}

const server = http.createServer(async (req, res) => {
	res.setHeader('Access-Control-Allow-Origin', '*');
	if (req.method === 'GET' && req.url === '/health') {
		res.writeHead(200, { 'Content-Type': 'application/json' });
		return res.end(JSON.stringify({
			status: worker ? 'ok' : 'starting',
			bin: CLAUDE_BIN,
			defaultModel: DEFAULT_MODEL,
			sessionId: worker?.sessionId || null,
			toolCount: worker?.orToolNames.length || 0,
			uptimeMs: worker?.startedAt ? Date.now() - worker.startedAt : null,
		}));
	}
	if (req.method === 'POST' && req.url === '/reset') {
		if (worker?.proc) worker.proc.kill('SIGTERM');
		res.writeHead(200, { 'Content-Type': 'application/json' });
		return res.end(JSON.stringify({ reset: true }));
	}
	if (req.method !== 'POST' || req.url !== '/api/chat') {
		res.writeHead(404); return res.end('not found');
	}
	if (!worker) {
		res.writeHead(503, { 'Content-Type': 'application/json' });
		return res.end(JSON.stringify({ error: 'bridge still starting (MCP discovery in progress)' }));
	}
	try {
		const body = await readBody(req);
		const { systemPrompt, userPrompt } = flattenMessages(body.messages);
		const t0 = Date.now();
		const result = await worker.send(systemPrompt, userPrompt);
		res.writeHead(200, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({
			model: body.model || DEFAULT_MODEL,
			created_at: new Date().toISOString(),
			message: { role: 'assistant', content: String(result.result ?? '') },
			done: true,
			done_reason: result.stop_reason || 'stop',
			tool_calls: result.toolCalls,
			tool_results: result.toolResults,
			_claude: {
				wall_ms: Date.now() - t0,
				duration_ms: result.duration_ms,
				cost_usd: result.total_cost_usd,
				session_id: result.session_id,
				num_turns: result.num_turns,
			},
		}));
	} catch (e) {
		res.writeHead(500, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: String(e.message || e) }));
	}
});

(async () => {
	console.log('[boot] discovering OR MCP tools…');
	const tools = await discoverOrTools();
	worker = new ClaudeWorker(tools);
	server.listen(PORT, '0.0.0.0', () => {
		console.log(`claude-bridge listening on :${PORT} (bin=${CLAUDE_BIN}, model=${DEFAULT_MODEL}, or-tools=${tools.length})`);
	});
})();
