// Local-dev Claude CLI bridge for OpenRegister chat.
//
// Exposes an Ollama-shaped /api/chat endpoint backed by ONE persistent
// `claude -p --input-format stream-json --output-format stream-json`
// subprocess. The expensive CLI bootstrap (~10s, MCP + plugin discovery,
// OAuth handshake) is paid once at bridge startup; subsequent chat turns
// pipe NDJSON through stdin and read NDJSON from stdout — pure Claude
// API latency (~1-3s per turn).
//
// LIMITATION: a single persistent process == a single Claude session.
// All OR conversations share Claude context. Fine for testing the chat
// UI / SSE plumbing where the operator drives one conversation at a
// time. NOT a substitute for the Ollama/OpenAI provider in production.
//
// Restart the bridge (or POST /reset) to wipe Claude session state.
//
// LOCAL DEV ONLY — uses your personal Claude OAuth subscription quota.

const http = require('node:http');
const { spawn } = require('node:child_process');

const PORT = Number(process.env.PORT || 11500);
const CLAUDE_BIN = process.env.CLAUDE_BIN || 'claude';
const DEFAULT_MODEL = process.env.CLAUDE_MODEL || 'sonnet';

class ClaudeWorker {
	constructor() {
		this.proc = null;
		this.sessionId = null;
		this.buffer = '';
		this.queue = [];
		this.current = null;
		this.startedAt = null;
		this.spawn();
	}

	spawn() {
		const args = [
			'-p',
			'--no-session-persistence',
			'--model', DEFAULT_MODEL,
			'--allowedTools', '',
			'--input-format', 'stream-json',
			'--output-format', 'stream-json',
			'--verbose',
		];
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
		}
		if (this.current && evt.type === 'result') {
			const { resolve } = this.current;
			this.current = null;
			resolve(evt);
			this.pump();
		}
	}

	pump() {
		if (this.current || this.queue.length === 0 || !this.proc) return;
		const next = this.queue.shift();
		this.current = next;
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

const worker = new ClaudeWorker();

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
			status: 'ok', bin: CLAUDE_BIN, defaultModel: DEFAULT_MODEL,
			sessionId: worker.sessionId,
			uptimeMs: worker.startedAt ? Date.now() - worker.startedAt : null,
		}));
	}
	if (req.method === 'POST' && req.url === '/reset') {
		if (worker.proc) worker.proc.kill('SIGTERM');
		res.writeHead(200, { 'Content-Type': 'application/json' });
		return res.end(JSON.stringify({ reset: true }));
	}
	if (req.method !== 'POST' || req.url !== '/api/chat') {
		res.writeHead(404); return res.end('not found');
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
			_claude: {
				wall_ms: Date.now() - t0,
				duration_ms: result.duration_ms,
				cost_usd: result.total_cost_usd,
				session_id: result.session_id,
			},
		}));
	} catch (e) {
		res.writeHead(500, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: String(e.message || e) }));
	}
});

server.listen(PORT, '0.0.0.0', () => {
	console.log(`claude-bridge listening on :${PORT} (bin=${CLAUDE_BIN}, model=${DEFAULT_MODEL})`);
});
