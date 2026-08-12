/**
 * The eight flows the engine coverage runs, and what each must actually DO.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This is the source of truth for `openregister-flow-engine.postman_collection.json`.
 * Edit here, then regenerate:
 *
 *     node tests/newman/build-flow-engine-collection.mjs
 *
 * ## Why every case asserts an EFFECT, never just a status
 *
 * A flow run reports `completed` when every token reached a terminal place —
 * including when each step processed ZERO items. During development one of
 * these very flows reported `completed` with `explode: in=1 out=0` because its
 * path was `body` instead of `response.body`: green run, nothing written, and a
 * status-only assertion would have shipped it. So each case below declares the
 * rows it must leave behind, and the collection checks those.
 *
 * ## Placeholders
 *
 * `{{register}}` `{{schema}}` `{{extSource}}` `{{ncSource}}` `{{agent}}` are
 * Postman collection variables filled by the setup folder at run time.
 */

/**
 * `stopped` is a SUCCESS terminal state: the End node halts the token
 * deliberately. `completed` means every token reached a terminal place without
 * an explicit End. Both are healthy; only `failed` is not.
 */
export const TERMINAL_OK = ['stopped', 'completed']

export const CASES = [
	{
		key: 'api-sync',
		title: '1 — Synchronise objects from an external API',
		description: 'Fetch a collection from an external API and write each item as a local object.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'call1', type: 'openconnector.source-call', config: { method: 'GET', source: '{{extSource}}', endpoint: '/users' } },
			{ id: 'x1', type: 'openregister.explode', config: { path: 'response.body', as: 'item', keepRecord: false } },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: '{{ item.name }}', sourceId: '{{ item.username }}', status: 'synced' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'call1' }, { id: 'b', from: 'call1', to: 'x1' }, { id: 'c', from: 'x1', to: 'w1' }, { id: 'd', from: 'w1', to: 'e1' }],
		// jsonplaceholder /users is a fixed fixture of 10.
		expect: { status: 'synced', atLeast: 10 },
	},
	{
		key: 'notify-on-change',
		title: '2 — Alter an object and notify a human',
		description: 'Read a synced object, move it to reviewed, and raise a Nextcloud notification.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'r1', type: 'openregister.object-read', config: { register: '{{register}}', schema: '{{schema}}', filters: { status: 'synced' }, limit: 1, fanOut: true } },
			{ id: 's1', type: 'openregister.set-fields', config: { set: { status: 'reviewed' } } },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'update', match: [{ property: 'sourceId', value: '{{ sourceId }}' }], fields: { status: 'reviewed' } } },
			{ id: 'n1', type: 'openconnector.source-call', config: { method: 'POST', source: '{{ncSource}}', endpoint: '/ocs/v2.php/apps/notifications/api/v2/admin_notifications/admin', body: { shortMessage: 'Flow engine test: an object was reviewed' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'r1' }, { id: 'b', from: 'r1', to: 's1' }, { id: 'c', from: 's1', to: 'w1' }, { id: 'd', from: 'w1', to: 'n1' }, { id: 'e', from: 'n1', to: 'e1' }],
		expect: { status: 'reviewed', atLeast: 1 },
	},
	{
		key: 'mailbox-summary-ai',
		title: '3 — Summarise a mailbox with an AI agent',
		description: 'Fetch messages, hand them to an agent for a one-line summary, store the result.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'call1', type: 'openconnector.source-call', config: { method: 'GET', source: '{{extSource}}', endpoint: '/posts?_limit=3' } },
			{ id: 'a1', type: 'hermiq.agent-step', config: { agent: '{{agent}}', prompt: 'Summarise these messages in one short sentence.' } },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'mailbox-summary', status: 'summarised' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'call1' }, { id: 'b', from: 'call1', to: 'a1' }, { id: 'c', from: 'a1', to: 'w1' }, { id: 'd', from: 'w1', to: 'e1' }],
		expect: { status: 'summarised', atLeast: 1 },
		// The only case that needs a model server. Skipped, loudly, when absent —
		// see the collection's pre-request guard.
		requires: 'agent',
	},
	{
		key: 'data-quality-sweep',
		title: '4 — Scheduled data-quality sweep',
		description: 'Walk the register on a schedule and flag every record that fails a rule.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-schedule', config: { cron: '0 3 * * *' } },
			{ id: 'r1', type: 'openregister.object-read', config: { register: '{{register}}', schema: '{{schema}}', limit: 100, fanOut: true } },
			// JsonLogic, NOT a template string. `'{{ status == "synced" }}'` is
			// accepted by the config guard and then keeps EVERY item, so the
			// sweep flagged all eleven objects including one with no sourceId —
			// which case 7 could not then delete by identity. A filter that
			// silently filters nothing is the quietest way to break a pipeline.
			{ id: 'f1', type: 'openregister.filter', config: { condition: { '==': [{ var: 'json.status' }, 'synced'] } } },
			// Carry the source record's own id onto the finding. Case 7 then has
			// something UNIQUE to delete by: object-write refuses a delete whose
			// match resolves to more than one row, so a finding identified only
			// by `status: flagged` could never be swept one at a time.
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'quality-finding', status: 'flagged', sourceId: '{{ sourceId }}' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'r1' }, { id: 'b', from: 'r1', to: 'f1' }, { id: 'c', from: 'f1', to: 'w1' }, { id: 'd', from: 'w1', to: 'e1' }],
		expect: { status: 'flagged', atLeast: 1 },
	},
	{
		key: 'enrichment',
		title: '5 — Enrich an object from an external register',
		description: 'Look a record up in an external source and store the derived field.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'call1', type: 'openconnector.source-call', config: { method: 'GET', source: '{{extSource}}', endpoint: '/users/1' } },
			// set-fields, NOT `map`: the map node resolves a STORED openconnector
			// Mapping by id/uuid/slug, so an inline object is rejected with
			// 'No mapping matches "Array"'. Inline derivation is set-fields.
			{ id: 'm1', type: 'openregister.set-fields', config: { set: { note: '{{ response.body.company.name }}' } } },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'enriched', status: 'enriched', note: '{{ note }}' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'call1' }, { id: 'b', from: 'call1', to: 'm1' }, { id: 'c', from: 'm1', to: 'w1' }, { id: 'd', from: 'w1', to: 'e1' }],
		expect: { status: 'enriched', atLeast: 1 },
	},
	{
		key: 'approval-routing',
		title: '6 — Route an object for approval',
		description: 'Branch on a record state and raise an approval task down the matching path.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'r1', type: 'openregister.object-read', config: { register: '{{register}}', schema: '{{schema}}', filters: { status: 'reviewed' }, limit: 5, fanOut: true } },
			{ id: 'sw1', type: 'openregister.switch', config: {} },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'approval-task', status: 'awaiting-approval' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'r1' }, { id: 'b', from: 'r1', to: 'sw1' }, { id: 'c', from: 'sw1', to: 'w1' }, { id: 'd', from: 'w1', to: 'e1' }],
		expect: { status: 'awaiting-approval', atLeast: 1 },
		// Consumes what case 2 produces, so it must run after it.
		after: 'notify-on-change',
	},
	{
		key: 'retention-sweep',
		title: '7 — Retention sweep',
		description: 'Delete records that are past their keep-by state.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'r1', type: 'openregister.object-read', config: { register: '{{register}}', schema: '{{schema}}', filters: { status: 'flagged' }, limit: 100, fanOut: true } },
			// BOTH pairs are load-bearing. `sourceId` alone matches the synced
			// record as well as its finding; `status` alone matches all eleven
			// findings. object-write refuses any delete whose match resolves to
			// more than one row — a deliberate mass-delete guard — so the pair
			// together is what makes this a per-item sweep rather than a refusal.
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'delete', match: [{ property: 'sourceId', value: '{{ sourceId }}' }, { property: 'status', value: 'flagged' }], confirmDelete: true } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'r1' }, { id: 'b', from: 'r1', to: 'w1' }, { id: 'c', from: 'w1', to: 'e1' }],
		// The only case whose effect is a DISAPPEARANCE, so it asserts the
		// inverse: what case 4 flagged must be gone.
		expect: { status: 'flagged', exactly: 0 },
		after: 'data-quality-sweep',
	},
	{
		key: 'paginated-sync',
		title: '9 — Page an API until it runs out, syncing each page',
		description: 'Loops pages of an external API and hands each page to a sync sub-flow, upserting so a re-run converges.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{
				id: 'pages1',
				type: 'openregister.iterate',
				config: {
					// The SOURCE is a sub-flow, and it has to be. A loop ends when
					// its source returns NO items, and a source-call always returns
					// exactly one response item — empty page or not — so a bare
					// source-call can never signal "exhausted". The sub-flow fetches
					// AND explodes, so a page past the end yields zero items, which
					// is the one termination rule.
					source: { type: 'openregister.sub-flow', config: { flowId: '{{pagerFlow}}', wait: true } },
					body: [{ type: 'openregister.sub-flow', config: { flowId: '{{syncFlow}}', wait: true } }],
					maxIterations: 15,
					onLimit: 'fail',
				},
			},
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'pages1' }, { id: 'b', from: 'pages1', to: 'e1' }],
		// 100 records over 11 pages of 10. The count is the claim: a loop that
		// fetched page one eleven times would also report success, and would also
		// leave objects behind — just the same ten, over and over.
		expect: { status: 'paged', atLeast: 100 },
	},
	{
		key: 'route-and-merge',
		title: '11 — Split records down two paths, then bring them back together',
		description: 'Routes each record to a different branch by a rule, writes a different outcome on each, and merges the branches back into one list.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'r1', type: 'openregister.object-read', config: { register: '{{register}}', schema: '{{schema}}', filters: { status: 'paged' }, limit: 100, fanOut: true } },
			// A router's `output` is the ID OF THE TARGET NODE — placement asks
			// `itemsForOutput(items, output: $to)`. Naming a branch anything else
			// sends its items nowhere, silently.
			{
				id: 'route1',
				type: 'openregister.route',
				config: {
					rules: [{ condition: { '>': [{ var: 'json.externalId' }, 50] }, output: 'hi1' }],
					default: 'lo1',
				},
			},
			{ id: 'hi1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'high', status: 'routed-high' } } },
			{ id: 'lo1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'low', status: 'routed-low' } } },
			{ id: 'merge1', type: 'openregister.merge', config: { mode: 'append' } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [
			{ id: 'a', from: 't1', to: 'r1' },
			{ id: 'b', from: 'r1', to: 'route1' },
			{ id: 'c', from: 'route1', to: 'hi1', title: 'over 50' },
			{ id: 'd', from: 'route1', to: 'lo1', title: 'the rest' },
			{ id: 'e', from: 'hi1', to: 'merge1' },
			{ id: 'f', from: 'lo1', to: 'merge1' },
			{ id: 'g', from: 'merge1', to: 'e1' },
		],
		// BOTH branches, because either one alone proves nothing: a router that
		// sent everything down one path would satisfy a single-branch assertion
		// while doing no routing at all. The split is the claim.
		expect: { status: 'routed-high', atLeast: 1, also: { status: 'routed-low', atLeast: 1 } },
		after: 'paginated-sync',
	},
	{
		key: 'sync-cursor',
		title: '10 — Remember a sync cursor between runs',
		description: 'Stores where the last sync got to, reads it back, and records it — the state a real incremental sync keeps.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'put1', type: 'openregister.flow-state', config: { operation: 'set', key: 'lastSyncedId', value: 100 } },
			{ id: 'get1', type: 'openregister.flow-state', config: { operation: 'get', key: 'lastSyncedId', as: 'cursor', default: 0 } },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'cursor', status: 'cursor-stored', externalId: '{{ cursor }}' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'put1' }, { id: 'b', from: 'put1', to: 'get1' }, { id: 'c', from: 'get1', to: 'w1' }, { id: 'd', from: 'w1', to: 'e1' }],
		// The WRITTEN value is the assertion, not the fact that the steps ran: a
		// state node that stored nothing and read back its default would also
		// report `completed`, and would write the default instead.
		// The stored VALUE, not merely that the steps ran: a state node that
		// stored nothing and read back its default would also report
		// `completed`, and would write 0 here instead of 100.
		expect: { status: 'cursor-stored', atLeast: 1, field: { name: 'externalId', equals: 100 } },
	},
	{
		key: 'batch-export',
		title: '8 — Batch export to an external endpoint',
		description: 'Read local objects and POST them outward in fixed-size batches.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 'r1', type: 'openregister.object-read', config: { register: '{{register}}', schema: '{{schema}}', limit: 10, fanOut: true } },
			{ id: 'b1', type: 'openregister.batch', config: { batchSize: 5 } },
			{ id: 'call1', type: 'openconnector.source-call', config: { method: 'POST', source: '{{extSource}}', endpoint: '/posts' } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'r1' }, { id: 'b', from: 'r1', to: 'b1' }, { id: 'c', from: 'b1', to: 'call1' }, { id: 'd', from: 'call1', to: 'e1' }],
		// Its effect is outbound, so there are no local rows to count. The
		// assertion is that the batch node actually BATCHED — 10 records in,
		// fewer calls out — which is the thing that would silently regress.
		expect: { batched: { node: 'b1', maxOut: 3 } },
	},
	{
		key: 'map-transform',
		title: '12 — Reshape items through a stored mapping',
		description: 'Feed fields into a stored mapping and write the RESHAPED result, proving the map node transformed rather than passed through.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			{ id: 's1', type: 'openregister.set-fields', config: { set: { first: 'Ada', last: 'Lovelace', n: '7' } } },
			// Named by UUID, not by the numeric id: a flow definition is portable
			// between instances where the id differs, and the uuid path is the one
			// that used to throw. MappingMapper::find() put `id = '<uuid>'` into the
			// disjunction, which on Postgres raises "invalid input syntax for type
			// bigint" and takes the whole lookup down — so every by-uuid resolve
			// failed with "No mapping matches", as though the row were absent.
			{ id: 'm1', type: 'openregister.map', config: { mapping: '{{mappingUuid}}' } },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: '{{ fullName }}', sourceId: '{{ kept }}', status: 'mapped' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 's1' }, { id: 'b', from: 's1', to: 'm1' }, { id: 'c', from: 'm1', to: 'w1' }, { id: 'd', from: 'w1', to: 'e1' }],
		// `fullName` exists ONLY as the mapping's output. Asserting the row's name
		// equals 'Ada Lovelace' is what separates a real transform from a map node
		// that quietly handed the items back unchanged — which is exactly what the
		// node used to do before it learned to throw on an unresolvable mapping.
		expect: { status: 'mapped', atLeast: 1, text: { name: 'name', equals: 'Ada Lovelace' } },
	},
	{
		key: 'trigger-object',
		title: '13 — An object-event trigger, run by hand',
		description: 'A flow whose entry point is an object event still runs when triggered manually.',
		nodes: [
			{ id: 'to1', type: 'openregister.trigger-object', config: { event: 'created', register: '{{register}}', schema: '{{schema}}' } },
			{ id: 'w1', type: 'openregister.object-write', config: { register: '{{register}}', schema: '{{schema}}', operation: 'create', fields: { name: 'event-triggered', status: 'evented' } } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 'to1', to: 'w1' }, { id: 'b', from: 'w1', to: 'e1' }],
		expect: { status: 'evented', atLeast: 1 },
	},
	{
		key: 'wait-suspends',
		title: '14 — A wait step suspends the run',
		description: 'A wait step parks the token instead of finishing, and says so.',
		nodes: [
			{ id: 't1', type: 'openregister.trigger-manual', config: {} },
			// `for` takes seconds as a bare number, or anything strtotime() can read
			// as a relative phrase. NOT an ISO-8601 duration: `PT1H` is the obvious
			// thing to write and strtotime('+PT1H') is false, whereupon the node
			// passes the items straight through — a wait that silently does not
			// wait, and a run that looks perfectly healthy afterwards.
			{ id: 'wa1', type: 'openregister.wait', config: { for: '1 hour' } },
			{ id: 'e1', type: 'openregister.end', config: {} },
		],
		edges: [{ id: 'a', from: 't1', to: 'wa1' }, { id: 'b', from: 'wa1', to: 'e1' }],
		// `suspended` is this case's SUCCESS. A wait that came back `completed`
		// would mean the step waited for nothing, and asserting the usual terminal
		// set would have demanded exactly that broken behaviour.
		expect: { terminal: ['suspended'], step: { node: 'wa1', status: 'suspended' } },
	},
]
