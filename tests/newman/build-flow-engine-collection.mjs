/**
 * Generate `openregister-flow-engine.postman_collection.json` from
 * `flow-engine-definitions.mjs`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Run:  node tests/newman/build-flow-engine-collection.mjs
 *
 * The collection is generated rather than hand-written because each of the
 * eight cases needs the same four-request cycle — create, run, poll to a
 * terminal state, assert the effect — and hand-maintaining ~1,500 lines of
 * Postman JSON across eight near-identical folders is how a test ends up
 * asserting something subtly different in case 6 than in case 2.
 *
 * The generated file IS committed: Newman consumes it directly and CI must not
 * depend on a build step.
 */

import { writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { CASES, TERMINAL_OK } from './flow-engine-definitions.mjs'

const here = dirname(fileURLToPath(import.meta.url))

/** Every state a run can settle in. `failed` counts: it ends the poll. */
const TERMINALS = [...TERMINAL_OK, 'failed']

/** Shared auth + JSON headers for every request. */
const HEADERS = [
	{ key: 'Content-Type', value: 'application/json' },
	{ key: 'OCS-APIRequest', value: 'true' },
	{ key: 'Authorization', value: 'Basic {{authToken}}' },
]

/**
 * Build one request item.
 *
 * @param name   Display name in the Newman run.
 * @param method HTTP verb.
 * @param path   Path below {{baseUrl}}.
 * @param body   Optional JSON body (object; serialised here).
 * @param test   Test script lines.
 * @param pre    Optional pre-request script lines.
 *
 * @return The Postman item.
 */
const req = (name, method, path, body, test, pre) => ({
	name,
	event: [
		...(pre ? [{ listen: 'prerequest', script: { type: 'text/javascript', exec: pre } }] : []),
		...(test ? [{ listen: 'test', script: { type: 'text/javascript', exec: test } }] : []),
	],
	request: {
		method,
		header: HEADERS,
		...(body === undefined ? {} : { body: { mode: 'raw', raw: JSON.stringify(body, null, 2) } }),
		url: { raw: `{{baseUrl}}${path}`, host: ['{{baseUrl}}'], path: path.replace(/^\//, '').split('/') },
	},
})

// ---------------------------------------------------------------- setup ----

const setup = {
	name: '00 — setup',
	item: [
		req('bootstrap variables', 'GET', '/status.php', undefined, [
			"const stamp = 'fe' + Date.now().toString(36)",
			"pm.collectionVariables.set('stamp', stamp)",
			"pm.collectionVariables.set('authToken', Buffer.from(pm.collectionVariables.get('username') + ':' + pm.collectionVariables.get('password')).toString('base64'))",
			"pm.test('instance is up', () => pm.response.to.have.status(200))",
			"pm.test('instance is NOT mid-upgrade', () => {",
			'    // needsDbUpgrade makes every /apps route answer 503, which reads',
			'    // downstream as "the flow API is broken" rather than "run occ upgrade".',
			"    pm.expect(pm.response.json().needsDbUpgrade, 'run `occ upgrade` first').to.eql(false)",
			'})',
		]),
		req('create register', 'POST', '/apps/openregister/api/registers', {
			title: 'flow-engine-{{stamp}}', slug: 'flow-engine-{{stamp}}', description: 'flow engine coverage',
		}, [
			"pm.collectionVariables.set('register', pm.response.json().id)",
			"pm.test('register created', () => pm.expect(pm.response.json().id).to.be.a('number'))",
		]),
		req('create schema', 'POST', '/apps/openregister/api/schemas', {
			title: 'flow-engine-item-{{stamp}}',
			slug: 'flow-engine-item-{{stamp}}',
			properties: {
				name: { type: 'string' },
				sourceId: { type: 'string' },
				// The paginated case matches on the SOURCE's own id, which is an
				// integer: `{{ item.id }}` is the whole value, so it renders as
				// the raw typed value and a string property rejects it.
				externalId: { type: 'integer' },
				status: { type: 'string' },
				note: { type: 'string' },
			},
		}, [
			"pm.collectionVariables.set('schema', pm.response.json().id)",
			"pm.test('schema created', () => pm.expect(pm.response.json().id).to.be.a('number'))",
		]),
		req('attach schema to register', 'PUT', '/apps/openregister/api/registers/{{register}}', {
			schemas: ['{{schema}}'],
		}, ["pm.test('schema attached', () => pm.response.to.have.status(200))"]),
		// The mapping the map case reshapes through. `fullName` is composed here
		// and exists nowhere else, so a row carrying it proves the transform ran.
		req('create mapping', 'POST', '/apps/openregister/api/mappings', {
			name: 'flow-engine-map-{{stamp}}',
			mapping: { fullName: '{{ first }} {{ last }}', kept: '{{ n }}' },
		}, [
			"const b = pm.response.json()",
			"pm.collectionVariables.set('mappingId', String(b.id || ''))",
			"pm.collectionVariables.set('mappingUuid', String(b.uuid || ''))",
			"pm.test('mapping created', () => pm.expect(b.uuid, JSON.stringify(b).slice(0, 200)).to.be.a('string'))",
			'// The create/read asymmetry this suite exists to keep closed: a session',
			'// with no ACTIVE organisation wrote the row with organisation NULL while',
			'// every read welded `1 = 0` onto the query, so POST answered 201 and the',
			'// very next GET answered 404 — for the same session, every time.',
		]),
		req('read the mapping back', 'GET', '/apps/openregister/api/mappings/{{mappingId}}', undefined, [
			"pm.test('a just-created mapping is readable by its own API', () => pm.response.to.have.status(200))",
		]),
		req('create external source', 'POST', '/apps/openregister/api/objects/openconnector/source', {
			name: 'flow-engine-ext-{{stamp}}',
			location: 'https://jsonplaceholder.typicode.com',
			type: 'api', isEnabled: true, version: '1.0.0',
		}, [
			"const b = pm.response.json()",
			"pm.collectionVariables.set('extSource', (b['@self'] && b['@self'].id) || b.id)",
			"pm.test('external source created', () => pm.expect(pm.collectionVariables.get('extSource')).to.be.a('string'))",
		]),
		// Auth goes in `configuration.headers`, NOT in the source's top-level
		// `headers` and NOT in `auth`/`username`/`password`. Both of those
		// persist happily and are then ignored by the call path, so the source
		// reads as correctly configured while every call it makes comes back
		// 401 — measured against the notifications endpoint.
		req('create nextcloud source', 'POST', '/apps/openregister/api/objects/openconnector/source', {
			name: 'flow-engine-nc-{{stamp}}',
			location: 'http://localhost',
			type: 'api', isEnabled: true, version: '1.0.0',
			configuration: {
				headers: {
					Authorization: 'Basic {{authToken}}',
					'OCS-APIRequest': 'true',
				},
			},
		}, [
			"const b = pm.response.json()",
			"pm.collectionVariables.set('ncSource', (b['@self'] && b['@self'].id) || b.id)",
			"pm.test('nextcloud source created', () => pm.expect(pm.collectionVariables.get('ncSource')).to.be.a('string'))",
		]),
		req('create pager sub-flow', 'POST', '/apps/openregister/api/flows', {
			name: 'flow-engine-pager-{{stamp}}',
			description: 'Fetches one page and emits an item per record. Empty when the pages run out.',
			app: 'openregister', enabled: true, executionMode: 'async',
			nodes: [
				{ id: 'p1', type: 'openregister.trigger-manual', config: {} },
				{ id: 'p2', type: 'openconnector.source-call', config: { method: 'GET', source: '{{extSource}}', endpoint: '/posts?_limit=10&_page={{ iteration.index }}' } },
				{ id: 'p3', type: 'openregister.explode', config: { path: 'response.body', as: 'item', keepRecord: false } },
				{ id: 'p4', type: 'openregister.end', config: {} },
			],
			edges: [{ id: 'a', from: 'p1', to: 'p2' }, { id: 'b', from: 'p2', to: 'p3' }, { id: 'c', from: 'p3', to: 'p4' }],
		}, [
			"pm.collectionVariables.set('pagerFlow', pm.response.json().uuid || '')",
			"pm.test('pager sub-flow created', () => pm.expect(pm.collectionVariables.get('pagerFlow')).to.be.a('string'))",
		]),
		req('create sync sub-flow', 'POST', '/apps/openregister/api/flows', {
			name: 'flow-engine-sync-{{stamp}}',
			description: 'Upserts one page of records. Called once per page by the paginated sync.',
			app: 'openregister', enabled: true, executionMode: 'async',
			nodes: [
				{ id: 's1', type: 'openregister.trigger-manual', config: {} },
				{
					id: 's2',
					type: 'openregister.object-write',
					config: {
						register: '{{register}}', schema: '{{schema}}',
						// `upsert` is its own operation — `update` + onMissing:create
						// does not exist, onMissing accepts only omit|fail.
						operation: 'upsert',
						match: { externalId: '{{ item.id }}' },
						fields: { name: '{{ item.title }}', externalId: '{{ item.id }}', status: 'paged' },
					},
				},
				{ id: 's3', type: 'openregister.end', config: {} },
			],
			edges: [{ id: 'a', from: 's1', to: 's2' }, { id: 'b', from: 's2', to: 's3' }],
		}, [
			"pm.collectionVariables.set('syncFlow', pm.response.json().uuid || '')",
			"pm.test('sync sub-flow created', () => pm.expect(pm.collectionVariables.get('syncFlow')).to.be.a('string'))",
		]),
		req('create summariser agent', 'POST', '/apps/openregister/api/objects/hermiq/agent', {
			name: 'flow-engine-summariser-{{stamp}}',
			description: 'Summarises a mailbox for the flow engine coverage',
			provider: 'ollama',
			model: '{{agentModel}}',
			prompt: 'You summarise messages in one short sentence.',
			active: true,
		}, [
			"const b = pm.response.json()",
			"pm.collectionVariables.set('agent', (b['@self'] && b['@self'].id) || b.id || '')",
			'// Not a failure when hermiq is absent: only case 3 needs it, and that',
			'// case guards on the variable being set.',
			"pm.test('agent fixture resolved (or hermiq absent)', () => pm.expect(true).to.be.true)",
		]),
	],
}

// ---------------------------------------------------------------- cases ----

/**
 * The four-request cycle for one case.
 *
 * @param c The case definition.
 *
 * @return The Postman folder.
 */
const caseFolder = (c) => {
	const v = `case_${c.key.replace(/-/g, '_')}`

	const guard = c.requires === 'agent'
		? [
			"if (!pm.collectionVariables.get('agent')) {",
			`    console.log('SKIP ${c.key}: no agent fixture (hermiq/ollama absent) — the AI case did not run')`,
			`    pm.collectionVariables.set('${v}_skipped', '1')`,
			'}',
		]
		: null

	return {
		name: c.title,
		description: c.description,
		item: [
			req(`${c.key} — create flow`, 'POST', '/apps/openregister/api/flows', {
				name: `flow-engine-${c.key}-{{stamp}}`,
				description: c.description,
				app: 'openregister',
				enabled: true,
				executionMode: 'async',
				nodes: c.nodes,
				edges: c.edges,
			}, [
				"const b = pm.response.json()",
				`pm.collectionVariables.set('${v}_uuid', b.uuid || '')`,
				`pm.collectionVariables.set('flows', (pm.collectionVariables.get('flows') || '') + ' ' + (b.uuid || ''))`,
				`pm.test('${c.key}: flow created', () => pm.expect(b.uuid, JSON.stringify(b).slice(0, 200)).to.be.a('string'))`,
			], guard),

			// `sync: true` executes inline and answers with the FINISHED run.
			// Asynchronously this needed a background worker alongside Newman
			// plus a polling loop, and on any instance whose cron is idle the
			// poll simply exhausted — reporting a perfectly healthy engine as
			// stuck, which is the most misleading failure a test can produce.
			req(`${c.key} — run flow (sync)`, 'POST', `/apps/openregister/api/flows/{{${v}_uuid}}/run`, { sync: true }, [
				"const run = pm.response.json()",
				`pm.collectionVariables.set('${v}_run', run.uuid || '')`,
				`pm.collectionVariables.set('${v}_status', String(run.status))`,
				`pm.collectionVariables.set('${v}_log', JSON.stringify(run.log || []))`,
				'',
				`pm.test('${c.key}: run executed', () => pm.expect(run.uuid, JSON.stringify(run).slice(0, 200)).to.be.a('string'))`,
				`pm.test('${c.key}: run reached a terminal state', () => {`,
				`    pm.expect(${JSON.stringify(c.expect.terminal ?? TERMINALS)}, 'a synchronous run answered ' + run.status + ' — it should never come back queued').to.include(String(run.status))`,
				'})',
			]),

			// _limit=1000, and it is load-bearing. At 200 the paginated case's
			// own 100 records plus the routed branches pushed later cases' rows
			// past the page boundary, and the assertion read "wrote nothing"
			// about a flow that had written correctly — a harness limit
			// reporting itself as a product failure.
			req(`${c.key} — assert effect`, 'GET',
				'/apps/openregister/api/objects/{{register}}/{{schema}}?_limit=1000',
				undefined,
				[
					`const steps = JSON.parse(pm.collectionVariables.get('${v}_log') || '[]')`,
					`const status = pm.collectionVariables.get('${v}_status')`,
					"const failed = steps.filter((s) => s.status === 'failed')",
					'',
					`pm.test('${c.key}: no step failed', () => {`,
					"    pm.expect(failed.map((s) => s.transition + ': ' + s.error).join(' | '), 'a step failed').to.eql('')",
					'})',
					'',
					'// The claim that matters. A run reports `completed` when every step',
					'// processed ZERO items, so status alone proves nothing — during',
					'// development one of these was green with explode in=1 out=0.',
					"const body = pm.response.json()",
					"const objects = body.results || body || []",
					...(c.expect.status !== undefined
						? [
							`const matching = objects.filter((o) => o.status === ${JSON.stringify(c.expect.status)})`,
							...(c.expect.exactly !== undefined
								? [`pm.test('${c.key}: ${c.expect.status} rows are gone', () => pm.expect(matching.length).to.eql(${c.expect.exactly}))`]
								: [`pm.test('${c.key}: wrote at least ${c.expect.atLeast} ${c.expect.status} object(s)', () => pm.expect(matching.length, 'the run was green but wrote nothing').to.be.at.least(${c.expect.atLeast}))`]),
						]
						: []),
					...(c.expect.also !== undefined
						? [
							`const other = objects.filter((o) => o.status === ${JSON.stringify(c.expect.also.status)})`,
							`pm.test('${c.key}: the other branch ran too (${c.expect.also.status})', () => {`,
							`    pm.expect(other.length, 'only one branch produced anything — nothing was actually split').to.be.at.least(${c.expect.also.atLeast})`,
							'})',
						]
						: []),
					...(c.expect.field !== undefined
						? [
							`const carrying = objects.filter((o) => o.status === ${JSON.stringify(c.expect.status)})`,
							`pm.test('${c.key}: ${c.expect.field.name} carries the stored value, not a default', () => {`,
							"    pm.expect(carrying.length, 'nothing to check the value on').to.be.above(0)",
							`    pm.expect(Number(carrying[0][${JSON.stringify(c.expect.field.name)}])).to.eql(${c.expect.field.equals})`,
							'})',
						]
						: []),
					...(c.expect.text !== undefined
						? [
							`const bearing = objects.filter((o) => o.status === ${JSON.stringify(c.expect.status)})`,
							`pm.test('${c.key}: ${c.expect.text.name} holds the TRANSFORMED value', () => {`,
							"    pm.expect(bearing.length, 'nothing to check the value on').to.be.above(0)",
							`    pm.expect(String(bearing[0][${JSON.stringify(c.expect.text.name)}])).to.eql(${JSON.stringify(c.expect.text.equals)})`,
							'})',
						]
						: []),
					...(c.expect.step !== undefined
						? [
							`const named = steps.filter((s) => s.transition === ${JSON.stringify(c.expect.step.node)})[0]`,
							`pm.test('${c.key}: ${c.expect.step.node} reported ${c.expect.step.status}', () => {`,
							"    pm.expect(named, 'that node produced no step at all — it never ran').to.not.be.undefined",
							`    pm.expect(String(named.status)).to.eql(${JSON.stringify(c.expect.step.status)})`,
							'})',
						]
						: []),
					...(c.expect.batched !== undefined
						? [
							`const batchStep = steps.filter((s) => s.transition === ${JSON.stringify(c.expect.batched.node)})[0]`,
							`pm.test('${c.key}: the batch node actually batched', () => {`,
							"    pm.expect(batchStep, 'no batch step in the log').to.not.be.undefined",
							`    pm.expect(batchStep.itemsOut, 'batching did not reduce the item count').to.be.at.most(${c.expect.batched.maxOut})`,
							'})',
						]
						: []),
				]),
		],
	}
}

// ------------------------------------------------------------- teardown ----

const teardown = {
	name: '99 — teardown',
	item: [
		req('delete flows', 'GET', '/apps/openregister/api/flows?limit=200', undefined, [
			"const body = pm.response.json()",
			"const all = body.results || body || []",
			"const stamp = pm.collectionVariables.get('stamp')",
			"const mine = all.filter((f) => String(f.name || '').indexOf(stamp) !== -1)",
			"pm.collectionVariables.set('toDelete', JSON.stringify(mine.map((f) => f.uuid)))",
			"pm.test('found this run\\'s flows to delete', () => pm.expect(mine.length, 'nothing matched the run stamp — teardown would silently no-op').to.be.above(0))",
			"const next = mine.map((f) => f.uuid)",
			"if (next.length) { pm.collectionVariables.set('deleteQueue', JSON.stringify(next)) }",
		]),
		req('delete one flow', 'DELETE', '/apps/openregister/api/flows/{{deleteHead}}', undefined, [
			"pm.test('flow deleted', () => pm.expect([200, 404]).to.include(pm.response.code))",
			"const queue = JSON.parse(pm.collectionVariables.get('deleteQueue') || '[]')",
			"if (queue.length) {",
			"    pm.collectionVariables.set('deleteHead', queue.shift())",
			"    pm.collectionVariables.set('deleteQueue', JSON.stringify(queue))",
			"    postman.setNextRequest('delete one flow')",
			'}',
		], [
			"const queue = JSON.parse(pm.collectionVariables.get('deleteQueue') || '[]')",
			"if (!pm.collectionVariables.get('deleteHead') && queue.length) {",
			"    pm.collectionVariables.set('deleteHead', queue.shift())",
			"    pm.collectionVariables.set('deleteQueue', JSON.stringify(queue))",
			'}',
		]),
		// Objects BEFORE schema and register: both refuse to be deleted while
		// they still hold rows (`register-has-objects` / `schema-has-objects`,
		// 409), and there is no bulk-delete endpoint — only one object at a
		// time. Skipping this left the fixture register behind on every run.
		req('list objects to purge', 'GET', '/apps/openregister/api/objects/{{register}}/{{schema}}?_limit=500', undefined, [
			"const body = pm.response.json()",
			"const objects = body.results || body || []",
			"const ids = objects.map((o) => (o['@self'] && o['@self'].id) || o.id).filter(Boolean)",
			"pm.collectionVariables.set('objectQueue', JSON.stringify(ids))",
			"pm.collectionVariables.set('objectHead', ids.length ? ids[0] : '')",
			"console.log('purging ' + ids.length + ' object(s)')",
			"if (!ids.length) { postman.setNextRequest('delete schema') }",
		]),
		req('delete one object', 'DELETE', '/apps/openregister/api/objects/{{register}}/{{schema}}/{{objectHead}}', undefined, [
			"pm.test('object purged', () => pm.expect([200, 204, 404]).to.include(pm.response.code))",
			"const queue = JSON.parse(pm.collectionVariables.get('objectQueue') || '[]')",
			"queue.shift()",
			"pm.collectionVariables.set('objectQueue', JSON.stringify(queue))",
			"if (queue.length) {",
			"    pm.collectionVariables.set('objectHead', queue[0])",
			"    postman.setNextRequest('delete one object')",
			'}',
		]),
		req('delete schema', 'DELETE', '/apps/openregister/api/schemas/{{schema}}', undefined, [
			"pm.test('schema deleted', () => pm.expect([200, 204, 404], JSON.stringify(pm.response.json())).to.include(pm.response.code))",
		]),
		req('delete register', 'DELETE', '/apps/openregister/api/registers/{{register}}', undefined, [
			"pm.test('register deleted', () => pm.expect([200, 204, 404], JSON.stringify(pm.response.json())).to.include(pm.response.code))",
		]),
		req('delete mapping', 'DELETE', '/apps/openregister/api/mappings/{{mappingId}}', undefined, [
			"pm.test('mapping deleted', () => pm.expect([200, 204, 404]).to.include(pm.response.code))",
		]),
		req('delete external source', 'DELETE', '/apps/openregister/api/objects/openconnector/source/{{extSource}}', undefined, [
			"pm.test('external source deleted', () => pm.expect([200, 204, 404]).to.include(pm.response.code))",
		]),
		req('delete nextcloud source', 'DELETE', '/apps/openregister/api/objects/openconnector/source/{{ncSource}}', undefined, [
			"pm.test('nextcloud source deleted', () => pm.expect([200, 204, 404]).to.include(pm.response.code))",
		]),
		req('delete agent', 'DELETE', '/apps/openregister/api/objects/hermiq/agent/{{agent}}', undefined, [
			"pm.test('agent deleted', () => pm.expect([200, 204, 404]).to.include(pm.response.code))",
		]),
		req('no run history survives its flow', 'GET', '/apps/openregister/api/flow-runs?limit=1', undefined, [
			'// Deleting a flow cascades its runs, steps and state. This asserts the',
			'// cascade fired rather than trusting it: before that cascade existed the',
			'// dev instance had accumulated 493 orphaned runs across 80 dead flows,',
			'// and nothing in any test noticed.',
			"pm.test('flow-runs endpoint still answers after teardown', () => pm.response.to.have.status(200))",
		]),
	],
}

const collection = {
	info: {
		name: 'OpenRegister — flow engine execution',
		description: 'Executes eight real flows end to end and asserts what each one CHANGED, not merely that it reported success. Needs a FlowRunWorker alongside it: use tests/newman/run-flow-engine.sh.',
		schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
	},
	variable: [
		{ key: 'baseUrl', value: 'http://localhost:8080' },
		{ key: 'username', value: 'admin' },
		{ key: 'password', value: 'admin' },
		{ key: 'agentModel', value: 'qwen2.5:3b' },
		{ key: 'stamp', value: '' },
		{ key: 'authToken', value: '' },
		{ key: 'register', value: '' },
		{ key: 'schema', value: '' },
		{ key: 'extSource', value: '' },
		{ key: 'ncSource', value: '' },
		{ key: 'agent', value: '' },
		{ key: 'mappingId', value: '' },
		{ key: 'mappingUuid', value: '' },
		{ key: 'flows', value: '' },
		{ key: 'deleteQueue', value: '[]' },
		{ key: 'deleteHead', value: '' },
		{ key: 'pagerFlow', value: '' },
		{ key: 'syncFlow', value: '' },
		{ key: 'objectQueue', value: '[]' },
		{ key: 'objectHead', value: '' },
	],
	item: [setup, ...CASES.map(caseFolder), teardown],
}

const out = join(here, 'openregister-flow-engine.postman_collection.json')
writeFileSync(out, JSON.stringify(collection, null, 2) + '\n')
console.log(`wrote ${out} — ${CASES.length} cases, ${collection.item.length} folders`)
