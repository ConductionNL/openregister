import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A FORM SUBMITS INTO ITS DESTINATION OBJECT (decision 179, ADR-117), end to
 * end through the HTTP API a portal or a form designer uses.
 *
 * @e2e form-destination::a-required-property-with-no-source-is-reported
 * @e2e form-destination::a-property-a-listener-fills-is-not-reported
 * @e2e form-destination::a-form-with-one-finding-is-not-saved
 * @e2e form-destination::a-schema-without-hard-validation-is-still-validated
 * @e2e form-destination::a-repeated-idempotency-key-repeats-the-answer
 * @e2e form-destination::a-refused-second-write-deletes-the-first
 * @e2e form-and-journey-registry::a-partial-failure-leaves-nothing-behind
 * @e2e form-destination::an-oversized-file-is-refused-at-upload
 * @e2e form-destination::create-and-update-refuse-alike
 *
 * WHAT IS DELIBERATELY NOT CLAIMED HERE. The listener-computed confirmation,
 * the schema-save unpublish and the flow task form need an owning app's
 * listener or a flow; the unit tests prove those with a fake listener and say
 * so. This file keeps no anchor for them rather than a misleading one.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, schemas, form object
 * and objects, and removes the register. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'

/** An API context, signed in or anonymous. */
async function contextFor(
	user?: string,
	password?: string,
): Promise<APIRequestContext> {
	const headers: Record<string, string> = {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
	if (user !== undefined && password !== undefined) {
		headers.Authorization = `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`
	}

	return pwRequest.newContext({ baseURL: BASE, extraHTTPHeaders: headers })
}

/** The uuid an object create answers with, whichever shape it uses. */
function uuidOf(body: Record<string, unknown>): string {
	const self = (body['@self'] ?? {}) as Record<string, unknown>

	return String(self.id ?? body.id ?? body.uuid)
}

test.describe.configure({ mode: 'serial' })

test.describe('a form submits into its destination object', () => {
	let admin: APIRequestContext
	let visitor: APIRequestContext
	let registerId: string
	let caseSchemaId: string
	let orgSchemaId: string
	let contactSchemaId: string
	let formSchemaId: string
	let caseFormId: string
	let journeyFormId: string

	/** Create a schema in the test register and return its id. */
	async function schema(data: Record<string, unknown>): Promise<string> {
		const res = await admin.post(`${API}/schemas`, {
			data: { description: 'e2e', ...data },
		})
		expect(res.ok(), `schema create failed: ${await res.text()}`).toBeTruthy()

		return String((await res.json()).id)
	}

	/** Store a published form object and return its uuid. */
	async function form(body: Record<string, unknown>): Promise<string> {
		const res = await admin.post(
			`${API}/objects/${registerId}/${formSchemaId}`,
			{ data: { status: 'published', ...body } },
		)
		expect(res.ok(), `form create failed: ${await res.text()}`).toBeTruthy()

		return uuidOf(await res.json())
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		visitor = await contextFor()

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e form destination ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const open = {
			read: ['public'],
			create: ['public'],
			update: ['authenticated'],
			delete: ['authenticated'],
		}
		caseSchemaId = await schema({
			title: `e2e case ${RUN}`,
			hardValidation: false,
			required: ['title', 'caseType'],
			authorization: open,
			properties: {
				title: { type: 'string', maxLength: 200 },
				caseType: { type: 'string' },
				identifier: {
					type: 'string',
					'x-openregister': {
						serverSet: true,
						reference: true,
						confirmation: true,
					},
				},
				bijlage: { type: 'file', maxSize: 1024 },
			},
		})
		orgSchemaId = await schema({
			title: `e2e org ${RUN}`,
			required: ['name'],
			authorization: open,
			properties: { name: { type: 'string' } },
		})
		contactSchemaId = await schema({
			title: `e2e contact ${RUN}`,
			required: ['email'],
			authorization: open,
			configuration: { unique: ['email'] },
			properties: {
				email: { type: 'string' },
				organisation: { type: 'string' },
			},
		})
		formSchemaId = await schema({
			title: `e2e form ${RUN}`,
			properties: {
				status: { type: 'string' },
				audience: { type: 'string' },
				destination: { type: 'object' },
				mapping: { type: 'object' },
				writes: { type: 'array' },
			},
		})

		caseFormId = await form({
			destination: { register: registerId, schema: caseSchemaId },
			mapping: {
				fields: [
					{ field: 'onderwerp', property: 'title' },
					{ field: 'bewijs', property: 'bijlage' },
				],
				fixed: { caseType: 'ct-1' },
			},
		})
		journeyFormId = await form({
			writes: [
				{
					as: 'org',
					register: registerId,
					schema: orgSchemaId,
					mapping: { fields: [{ field: 'naam', property: 'name' }] },
				},
				{
					as: 'contact',
					register: registerId,
					schema: contactSchemaId,
					mapping: {
						fields: [{ field: 'mail', property: 'email' }],
						fixed: { organisation: { $write: 'org' } },
					},
				},
			],
		})
	})

	test.afterAll(async () => {
		await admin.delete(`${API}/registers/${registerId}`)
	})

	test('validate names the required property no field fills, and not the one the server fills', async () => {
		const res = await admin.post(`${API}/forms/validate`, {
			data: {
				destination: { register: registerId, schema: caseSchemaId },
				mapping: {
					fields: [
						{
							field: 'a',
							property: 'title',
							type: 'text',
							maxLength: 100,
						},
					],
				},
			},
		})
		expect(res.status()).toBe(200)
		const body = await res.json()
		expect(body.accepted).toBe(false)
		expect(
			body.findings.map(
				(f: { property: string; code: string }) => `${f.property}:${f.code}`,
			),
		).toEqual(['caseType:required-unmapped'])

		const fixed = await admin.post(`${API}/forms/validate`, {
			data: {
				destination: { schema: caseSchemaId },
				mapping: {
					fields: [
						{
							field: 'a',
							property: 'title',
							type: 'text',
							maxLength: 100,
						},
					],
					fixed: { caseType: 'ct-1' },
				},
			},
		})
		expect((await fixed.json()).accepted).toBe(true)
	})

	test('a submit missing a required answer is refused with 422 and creates nothing, hard validation off', async () => {
		const before = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}?_limit=100`,
		)
		const countBefore = (await before.json()).total
		const res = await visitor.post(`${API}/forms/${caseFormId}/submit`, {
			data: {},
		})
		expect(res.status()).toBe(422)
		expect((await res.json()).findings[0]).toMatchObject({
			property: 'title',
			code: 'required',
		})
		const after = await admin.get(
			`${API}/objects/${registerId}/${caseSchemaId}?_limit=100`,
		)
		expect((await after.json()).total).toBe(countBefore)
	})

	test('a repeated Idempotency-Key repeats the first answer and creates one object', async () => {
		const key = `k-${RUN}`
		const first = await visitor.post(`${API}/forms/${caseFormId}/submit`, {
			data: { onderwerp: 'Kapvergunning' },
			headers: { 'Idempotency-Key': key },
		})
		expect(first.status()).toBe(201)
		const second = await visitor.post(`${API}/forms/${caseFormId}/submit`, {
			data: { onderwerp: 'Kapvergunning' },
			headers: { 'Idempotency-Key': key },
		})
		expect(second.status()).toBe(201)
		expect((await second.json()).id).toBe((await first.json()).id)
	})

	test('a refused second write deletes the first', async () => {
		const ok = await visitor.post(`${API}/forms/${journeyFormId}/submit`, {
			data: { naam: 'De Korst', mail: `info-${RUN}@dekorst.nl` },
		})
		expect(ok.status(), await ok.text()).toBe(201)
		const orgsBefore = (
			await (
				await admin.get(
					`${API}/objects/${registerId}/${orgSchemaId}?_limit=100`,
				)
			).json()
		).total

		const dup = await visitor.post(`${API}/forms/${journeyFormId}/submit`, {
			data: { naam: 'De Korst 2', mail: `info-${RUN}@dekorst.nl` },
		})
		expect(dup.status()).toBe(422)
		expect((await dup.json()).findings[0].write).toBe('contact')
		const orgsAfter = (
			await (
				await admin.get(
					`${API}/objects/${registerId}/${orgSchemaId}?_limit=100`,
				)
			).json()
		).total
		expect(orgsAfter).toBe(orgsBefore)
	})

	test('an oversized file is refused at upload and no token is issued', async () => {
		const res = await visitor.post(`${API}/forms/${caseFormId}/uploads`, {
			multipart: {
				property: 'bijlage',
				file: {
					name: 'groot.pdf',
					mimeType: 'application/pdf',
					buffer: Buffer.alloc(4096, 1),
				},
			},
		})
		expect(res.status()).toBe(422)
		const body = await res.json()
		expect(body.findings[0].code).toBe('file-too-large')
		expect(body.token).toBeUndefined()
	})

	test('an unknown form is a 404, the same as an unpublished one', async () => {
		const unknown = await visitor.post(
			`${API}/forms/00000000-0000-4000-8000-000000000000/submit`,
			{ data: {} },
		)
		expect(unknown.status()).toBe(404)
	})

	test('create and update refuse alike, per property', async () => {
		const hard = await schema({
			title: `e2e hard ${RUN}`,
			hardValidation: true,
			required: ['title'],
			properties: { title: { type: 'string' }, note: { type: 'string' } },
		})
		const created = await admin.post(`${API}/objects/${registerId}/${hard}`, {
			data: { note: 'x' },
		})
		const good = await admin.post(`${API}/objects/${registerId}/${hard}`, {
			data: { title: 't' },
		})
		const updated = await admin.put(
			`${API}/objects/${registerId}/${hard}/${uuidOf(await good.json())}`,
			{ data: { note: 'y' } },
		)

		expect(created.status()).toBe(updated.status())
		expect(Array.isArray((await created.json()).errors)).toBe(true)
		expect(Array.isArray((await updated.json()).errors)).toBe(true)
	})
})
