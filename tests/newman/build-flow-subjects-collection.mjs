/**
 * Generate `openregister-flow-subjects.postman_collection.json`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Run:  node tests/newman/build-flow-subjects-collection.mjs
 *
 * WHAT THIS COVERS
 * ----------------
 * A run declares what it is working on, and a task hangs on it. The whole
 * scene, over the live HTTP API: a case is created and recorded under the role
 * `case`, the case is locked, a person is asked with `attachTo: case`, they
 * answer with a form value, and the answer routes.
 *
 * WHY NEWMAN AND NOT PLAYWRIGHT
 * -----------------------------
 * These are HTTP/JSON contract assertions, and `playwright.config.ts` excludes
 * `**\/api-direct/**` from every project — so a Playwright spec here would run
 * only when a developer invoked an ad-hoc config by hand, and CI would execute
 * none of it. A green spec and zero CI coverage look identical from the
 * outside. Same reasoning as the `delegation` and `register-descriptors`
 * collections, which were moved here for exactly this.
 *
 * WHY IT IS NOT A UNIT TEST
 * -------------------------
 * Every part of this has unit tests, and they all passed while the parts did
 * not meet. The recorder writes onto the run ROW, which only exists once a run
 * has been persisted; `attachTo` reads that row back in a LATER step of the
 * same run; and the anchor it produces has to survive the task bridge, the task
 * row and the inbox read. A unit test can assert each hand-off and cannot
 * assert that the chain holds end to end, which is the only thing an author
 * cares about.
 *
 * The generated file IS committed: Newman consumes it directly and CI must not
 * depend on a build step.
 */

import { writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))

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
 *
 * @return The Postman item.
 */
function req(name, method, path, body, test) {
	return {
		name,
		event: [{ listen: 'test', script: { type: 'text/javascript', exec: test } }],
		request: {
			method,
			header: HEADERS,
			...(body === undefined
				? {}
				: { body: { mode: 'raw', raw: JSON.stringify(body, null, 2) } }),
			url: {
				raw: `{{baseUrl}}${path}`,
				host: ['{{baseUrl}}'],
				path: path.replace(/^\//, '').split('/'),
			},
		},
	}
}

/** The object-write step, recording under `role` when one is given. */
function createCase(id, role) {
	const config = {
		register: '{{registerSlug}}',
		schema: '{{schemaSlug}}',
		operation: 'create',
		fields: { name: '{{ name }}' },
	}
	if (role !== null) {
		config.subjectRole = role
	}
	return { id, type: 'openregister.object-write', config, position: { x: 0, y: 0 } }
}

/** The lock step, holding whatever object it receives. */
function lockCase(id) {
	return {
		id,
		type: 'openregister.lock-object',
		config: { process: 'flow-subjects {{stamp}}' },
		position: { x: 0, y: 0 },
	}
}

/** The asking step, optionally attaching its task to a declared role. */
function ask(id, attachTo, extra = {}) {
	const config = {
		title: 'flow-subjects {{stamp}} approve {{ name }}',
		assignee: '{{username}}',
		outcomes: 'approved, rejected',
		...extra,
	}
	if (attachTo !== null) {
		config.attachTo = attachTo
	}
	return { id, type: 'openregister.user-task', config, position: { x: 0, y: 0 } }
}

function setFields(id, set) {
	return { id, type: 'openregister.set-fields', config: { set }, position: { x: 0, y: 0 } }
}

/** A flow-creation request that stores the new flow's uuid under `varName`. */
function createFlow(name, varName, nodes, edges, extraTests = []) {
	return req(
		`create flow — ${name}`,
		'POST',
		'/apps/openregister/api/flows',
		{
			name: `flow-subjects {{stamp}} ${name}`,
			description: 'Created by the flow-run-subjects Newman collection.',
			trigger: 'manual',
			enabled: true,
			nodes,
			edges,
		},
		[
			"pm.test('the flow was authored', () => pm.response.to.have.status(201))",
			'const flow = pm.response.json()',
			`pm.collectionVariables.set('${varName}', String(flow.uuid || ''))`,
			`pm.test('the flow has a uuid', () => pm.expect(pm.collectionVariables.get('${varName}')).to.not.eql(''))`,
			...extraTests,
		],
	)
}

/** Run a flow synchronously and store the run uuid under `varName`. */
function runFlow(name, flowVar, varName, tests) {
	return req(
		`run — ${name}`,
		'POST',
		'/apps/openregister/api/flow-runs/test',
		{
			flowId: `{{${flowVar}}}`,
			seedItems: [{ json: { name: 'flow-subjects {{stamp}} Case 7' } }],
		},
		[
			"pm.test('the run started', () => pm.response.to.have.status(200))",
			'const run = pm.response.json()',
			`pm.collectionVariables.set('${varName}', String(run.uuid || ''))`,
			...tests,
		],
	)
}

const setup = {
	name: '00 — setup',
	item: [
		req('bootstrap variables', 'GET', '/status.php', undefined, [
			"const stamp = 'fs' + Date.now().toString(36)",
			"pm.collectionVariables.set('stamp', stamp)",
			"pm.collectionVariables.set('registerSlug', 'flow-subjects-' + stamp)",
			"pm.collectionVariables.set('schemaSlug', 'flow-subjects-case-' + stamp)",
			"pm.collectionVariables.set('authToken', Buffer.from(pm.collectionVariables.get('username') + ':' + pm.collectionVariables.get('password')).toString('base64'))",
			"pm.test('instance is up', () => pm.response.to.have.status(200))",
			"pm.test('instance is NOT mid-upgrade', () => {",
			'    // needsDbUpgrade makes every /apps route answer 503, which reads',
			'    // downstream as "the flow API is broken" rather than "run occ upgrade".',
			"    pm.expect(pm.response.json().needsDbUpgrade, 'run `occ upgrade` first').to.eql(false)",
			'})',
		]),
		req(
			'create schema',
			'POST',
			'/apps/openregister/api/schemas',
			{
				title: 'flow-subjects case {{stamp}}',
				slug: '{{schemaSlug}}',
				description: 'Fixture schema for the flow-run-subjects collection.',
				properties: {
					name: { type: 'string' },
					advice: { type: 'string' },
				},
			},
			[
				"pm.collectionVariables.set('schema', String(pm.response.json().id))",
				"pm.test('schema created', () => pm.expect(pm.response.json().id).to.be.a('number'))",
			],
		),
		req(
			'create register',
			'POST',
			'/apps/openregister/api/registers',
			{
				title: 'flow-subjects register {{stamp}}',
				slug: '{{registerSlug}}',
				description: 'Fixture register for the flow-run-subjects collection.',
				schemas: ['{{schema}}'],
			},
			[
				"pm.collectionVariables.set('register', String(pm.response.json().id))",
				"pm.test('register created', () => pm.expect(pm.response.json().id).to.be.a('number'))",
			],
		),
	],
}

const scene = {
	name: '1 — a case is recorded, locked, and the task hangs on it',
	item: [
		createFlow(
			'the whole scene',
			'sceneFlow',
			[
				createCase('create', 'case'),
				lockCase('lock'),
				ask('ask', 'case', {
					formKind: 'fields',
					formSchema: '{{schemaSlug}}',
					formFields: 'advice',
				}),
				setFields('approved', { branch: 'approved' }),
				setFields('rejected', { branch: 'rejected' }),
			],
			[
				{ id: 'e1', from: 'create', to: 'lock' },
				{ id: 'e2', from: 'lock', to: 'ask' },
				{
					id: 'e3',
					from: 'ask',
					to: ['approved', 'rejected'],
					type: 'openregister.route',
					config: {
						rules: [
							{
								condition: { '==': [{ var: 'json.answers.advice' }, 'grant it'] },
								output: 'approved',
							},
						],
						default: 'rejected',
					},
				},
			],
		),
		runFlow('the whole scene', 'sceneFlow', 'sceneRun', [
			"pm.test('the step suspended waiting for its answer', () => {",
			"    pm.expect(String(run.status), 'a step that raised a task must suspend').to.eql('suspended')",
			'})',
		]),
		req(
			'the run declared the case',
			'GET',
			'/apps/openregister/api/flow-runs/{{sceneRun}}',
			undefined,
			[
				"pm.test('the run reads back', () => pm.response.to.have.status(200))",
				'const body = pm.response.json()',
				"pm.test('the run carries its declared subjects', () => {",
				"    pm.expect(body.subjects, 'subjects is an object, never a missing key').to.be.an('object')",
				'})',
				"pm.test('it holds the case its author named', () => {",
				"    const held = Object.keys(body.subjects || {}).join(', ') || 'nothing'",
				"    pm.expect(body.subjects.case, 'the run should hold a case; it holds ' + held).to.be.an('object')",
				'})',
				"pm.collectionVariables.set('caseUuid', String((body.subjects.case || {}).uuid || ''))",
				"pm.test('the recorded case has a uuid', () => {",
				"    pm.expect(pm.collectionVariables.get('caseUuid')).to.not.eql('')",
				'})',
			],
		),
		req(
			'the task hangs on the case, the run and the person',
			'GET',
			'/apps/openregister/api/flow-tasks?scope=assigned&isTerminal=false&limit=100&sort=created&direction=desc',
			undefined,
			[
				"pm.test('the inbox reads', () => pm.response.to.have.status(200))",
				'const rows = pm.response.json().results || []',
				"const run = pm.collectionVariables.get('sceneRun')",
				"const task = rows.find((r) => String(r.runUuid) === run && String(r.nodeId) === 'ask')",
				"pm.test('the step raised a task', () => pm.expect(task, 'no task for run ' + run).to.be.an('object'))",
				"pm.collectionVariables.set('sceneTask', String((task || {}).uuid || ''))",
				'// 🔴 THE ANCHOR IS THE POINT. It rides the task row\'s EXISTING object',
				'// fields, so the subject-anchored inbox read, the case sidebar and the',
				'// portal visibility rule all keep working with no change.',
				"pm.test('the task is anchored to the declared case', () => {",
				"    pm.expect(String((task || {}).objectUuid)).to.eql(pm.collectionVariables.get('caseUuid'))",
				'})',
				"pm.test('and to the case register and schema', () => {",
				"    pm.expect(String((task || {}).registerId)).to.eql(pm.collectionVariables.get('register'))",
				"    pm.expect(String((task || {}).schemaId)).to.eql(pm.collectionVariables.get('schema'))",
				'})',
				"pm.test('and it names the person who was asked', () => {",
				"    pm.expect(String((task || {}).assignee)).to.eql(pm.collectionVariables.get('username'))",
				'})',
			],
		),
		req(
			'the person answers with a form value',
			'POST',
			'/apps/openregister/api/flow-tasks/{{sceneTask}}/complete',
			{ outcome: 'approved', data: { advice: 'grant it' } },
			[
				"pm.test('the answer was accepted', () => pm.response.to.have.status(200))",
			],
		),
		req(
			'the declared set survives the answer',
			'GET',
			'/apps/openregister/api/flow-runs/{{sceneRun}}',
			undefined,
			[
				"pm.test('the run still reads', () => pm.response.to.have.status(200))",
				'const body = pm.response.json()',
				"pm.test('the run still holds the case it declared', () => {",
				'    // The declared set is the run\'s own record: answering a task does',
				'    // not un-declare what the run is working on.',
				"    pm.expect(String((body.subjects.case || {}).uuid)).to.eql(pm.collectionVariables.get('caseUuid'))",
				'})',
			],
		),
	],
}

const noRole = {
	name: '2 — a write naming no role declares nothing',
	item: [
		createFlow(
			'no role',
			'noRoleFlow',
			[createCase('create', null), setFields('done', { step: 2 })],
			[{ id: 'e1', from: 'create', to: 'done' }],
		),
		runFlow('no role', 'noRoleFlow', 'noRoleRun', []),
		req(
			'the declared set is empty, and the write still happened',
			'GET',
			'/apps/openregister/api/flow-runs/{{noRoleRun}}',
			undefined,
			[
				"pm.test('the run reads back', () => pm.response.to.have.status(200))",
				'const body = pm.response.json()',
				'// 🔴 DECLARED, NEVER DERIVED. A set that filled itself would be the',
				'// audit-derived object list again, and every entry in it would then be',
				'// addressable by `attachTo`, inventing declarations no author made.',
				"pm.test('an empty set, never a missing key', () => {",
				"    pm.expect(body.subjects, 'subjects must be present').to.be.an('object')",
				"    pm.expect(Object.keys(body.subjects || {}), 'no role was named, so nothing is declared').to.eql([])",
				'})',
			],
		),
		req(
			'the audit-derived list still shows the write',
			'GET',
			'/apps/openregister/api/flow-runs/{{noRoleRun}}/objects',
			undefined,
			[
				"pm.test('the audit reads back', () => pm.response.to.have.status(200))",
				'// The two lists answer different questions and must keep disagreeing:',
				'// the audit answers the auditor, and only a declaration answers the',
				'// author — and only a declaration carries a role.',
				"pm.test('the write itself is untouched by declaring nothing', () => {",
				'    pm.expect(JSON.stringify(pm.response.json())).to.include(\'create\')',
				'})',
			],
		),
	],
}

const unheld = {
	name: '3 — attaching to a role the run never recorded',
	item: [
		createFlow(
			'unheld role',
			'unheldFlow',
			[createCase('create', 'case'), ask('ask', 'besluit')],
			[{ id: 'e1', from: 'create', to: 'ask' }],
		),
		runFlow('unheld role', 'unheldFlow', 'unheldRun', [
			'// 🔴 THE STEP FAILS RATHER THAN CREATING AN UNATTACHED TASK. It must not',
			'// suspend, because suspending means a task was raised to wait for.',
			"pm.test('the step did not suspend waiting for an answer', () => {",
			"    pm.expect(String(run.status), 'a step attaching to an unheld role must not suspend').to.not.eql('suspended')",
			'})',
			"pm.test('the failure names the role that was asked for', () => {",
			"    pm.expect(JSON.stringify(run), 'the refusal should name `besluit`').to.include('besluit')",
			'})',
		]),
		req(
			'and no task was created',
			'GET',
			'/apps/openregister/api/flow-tasks?scope=assigned&isTerminal=false&limit=100&sort=created&direction=desc',
			undefined,
			[
				"pm.test('the inbox reads', () => pm.response.to.have.status(200))",
				'const rows = pm.response.json().results || []',
				"const run = pm.collectionVariables.get('unheldRun')",
				'// This is the assertion the whole capability is for: a task attached to',
				'// nothing looks fine in every list and is wrong in the one place it',
				'// matters, so it must never be created at all.',
				"pm.test('no task exists for a step that could not attach', () => {",
				"    const found = rows.filter((r) => String(r.runUuid) === run && String(r.nodeId) === 'ask')",
				"    pm.expect(found, 'a task was created despite an unattachable role').to.eql([])",
				'})',
			],
		),
	],
}

const teardown = {
	name: '99 — teardown',
	item: [
		req(
			'delete the scene flow',
			'DELETE',
			'/apps/openregister/api/flows/{{sceneFlow}}',
			undefined,
			[
				'// Deleting a flow cascades its runs, which releases any lock they hold.',
				'// A fixture that leaves a lock behind is not litter: the object cannot',
				'// be written, so a second run of this collection meets a state the',
				'// first never saw.',
				"pm.test('the scene flow is gone', () => pm.expect([200, 204, 404]).to.include(pm.response.code))",
			],
		),
		req(
			'delete the no-role flow',
			'DELETE',
			'/apps/openregister/api/flows/{{noRoleFlow}}',
			undefined,
			["pm.test('the no-role flow is gone', () => pm.expect([200, 204, 404]).to.include(pm.response.code))"],
		),
		req(
			'delete the unheld-role flow',
			'DELETE',
			'/apps/openregister/api/flows/{{unheldFlow}}',
			undefined,
			["pm.test('the unheld-role flow is gone', () => pm.expect([200, 204, 404]).to.include(pm.response.code))"],
		),
		req(
			'delete the fixture register',
			'DELETE',
			'/apps/openregister/api/registers/{{register}}',
			undefined,
			["pm.test('the register is gone', () => pm.expect([200, 204, 404]).to.include(pm.response.code))"],
		),
		req(
			'delete the fixture schema',
			'DELETE',
			'/apps/openregister/api/schemas/{{schema}}',
			undefined,
			["pm.test('the schema is gone', () => pm.expect([200, 204, 404]).to.include(pm.response.code))"],
		),
	],
}

const collection = {
	info: {
		name: 'OpenRegister — flow run subjects',
		description:
			'A run declares what it is working on, and a task hangs on it. '
			+ 'Generated by tests/newman/build-flow-subjects-collection.mjs; edit that, not this.',
		schema:
			'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
	},
	variable: [
		{ key: 'baseUrl', value: 'http://localhost:8080' },
		{ key: 'username', value: 'admin' },
		{ key: 'password', value: 'admin' },
		{ key: 'stamp', value: '' },
		{ key: 'authToken', value: '' },
		{ key: 'register', value: '' },
		{ key: 'schema', value: '' },
		{ key: 'registerSlug', value: '' },
		{ key: 'schemaSlug', value: '' },
		{ key: 'sceneFlow', value: '' },
		{ key: 'sceneRun', value: '' },
		{ key: 'sceneTask', value: '' },
		{ key: 'caseUuid', value: '' },
		{ key: 'noRoleFlow', value: '' },
		{ key: 'noRoleRun', value: '' },
		{ key: 'unheldFlow', value: '' },
		{ key: 'unheldRun', value: '' },
	],
	item: [setup, scene, noRole, unheld, teardown],
}

const out = join(here, 'openregister-flow-subjects.postman_collection.json')
writeFileSync(out, `${JSON.stringify(collection, null, 2)}\n`)
process.stdout.write(`wrote ${out}\n`)
