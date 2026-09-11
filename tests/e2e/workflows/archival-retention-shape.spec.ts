import type { APIRequestContext } from '@playwright/test'
import type { SeededRegister } from '../_fixtures.ts'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE `_retention` BLOCK READS THE SAME ON EVERY VERB.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * `GET /api/objects/...` runs its whole response through
 * `ObjectsController::stripEmptyValues()` unless `_empty=true` is passed.
 * Create, update and patch answer unstripped. The archival resolver used to
 * emit nulls, so one object carried two different `@self._retention` blocks
 * depending on the verb that returned it. The costly case was
 * `annotation.matchedRule: null`: it means "no rule in the schema's
 * `x-openregister-archival` block fired, the default applied", and on GET it
 * vanished, so a records officer could no longer tell "no rule fired" from
 * "this reader does not know".
 *
 * Every unit test drives the resolver or the strip on its own. Only an HTTP
 * round trip can show the two verbs disagreeing, so that is what this file
 * asserts: the create response and a fresh GET, compared as serialised JSON.
 *
 * It is a separate file from `archival-retention.spec.ts` (PR #3619) on
 * purpose, so the two changes cannot conflict.
 *
 * SELF-CLEANING, UNLIKE ITS SIBLING
 * ---------------------------------
 * An object on a schema declaring `x-openregister-archival` refuses every HTTP
 * delete. The refusal's own hint names the way out: drop the annotation from
 * the schema first. `afterAll` does exactly that, then deletes the objects,
 * the schema and the register, so this file leaves nothing behind.
 */
import { expect, test } from '@playwright/test'
import {
	createRegister,
	deleteRegister,
	deleteSchema,
	linkSchemaToRegister,
	makeRunId,
	objectId,
} from '../_fixtures.ts'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}
const REQUEST_TIMEOUT = 30_000
const RUN = makeRunId()

/** Schema properties: a title and the field the annotation's rule tests. */
const PROPERTIES = {
	title: { type: 'string', title: 'Title' },
	statusCode: { type: 'integer', title: 'Status code' },
}

/** One rule and a default, so a row either matches rule 0 or falls through. */
const ARCHIVAL = {
	retention: {
		default: 'P30D',
		rules: [
			{
				condition: 'statusCode < 400',
				retention: 'PT1H',
				reason: 'successful calls expire within the hour',
			},
		],
	},
}

let register: SeededRegister | undefined
let schemaId = 0
let schemaSlug = ''
const createdIds: string[] = []

/**
 * Every path inside a value that holds `null`.
 *
 * @param  {unknown} value The value to walk.
 * @param  {string}  at    The path so far, for the failure message.
 * @return {string[]}      The paths of every null found.
 */
function nullPaths(value: unknown, at: string): string[] {
	if (value === null) {
		return [at]
	}
	if (Array.isArray(value)) {
		return value.flatMap((item, i) => nullPaths(item, `${at}[${i}]`))
	}
	if (typeof value === 'object' && value !== undefined) {
		return Object.entries(value as Record<string, unknown>).flatMap(
			([key, item]) => nullPaths(item, `${at}.${key}`),
		)
	}
	return []
}

/**
 * Create one object, then GET it, and return both `_retention` blocks.
 *
 * @param  {APIRequestContext} request    The request context.
 * @param  {number}            statusCode The value the annotation's rule tests.
 * @return {Promise<{id: string, created: object, read: object}>} The id and both blocks.
 */
async function createAndReadBack(
	request: APIRequestContext,
	statusCode: number,
): Promise<{ id: string; created: Record<string, any>; read: Record<string, any> }> {
	const resp = await request.post(`${API}/objects/${register?.id}/${schemaId}`, {
		headers: JSON_HEADERS,
		data: { title: `${RUN} status ${statusCode}`, statusCode },
		timeout: REQUEST_TIMEOUT,
	})
	expect(resp.status(), 'the create must succeed').toBe(201)
	const body = await resp.json()
	const id = objectId(body)
	expect(id, 'the created object must have an id').toBeTruthy()
	createdIds.push(id as string)

	const created = body?.['@self']?._retention
	expect(
		created,
		'an annotated schema must put a decision in the create response',
	).toBeTruthy()

	const read = await retentionOnGet(request, id as string, '')

	return { id: id as string, created, read }
}

/**
 * GET one object and return its `@self._retention`.
 *
 * @param  {APIRequestContext} request The request context.
 * @param  {string}            id      The object id.
 * @param  {string}            query   A query string, or ''.
 * @return {Promise<Record<string, any>>} The block the read answered.
 */
async function retentionOnGet(
	request: APIRequestContext,
	id: string,
	query: string,
): Promise<Record<string, any>> {
	const resp = await request.get(
		`${API}/objects/${register?.id}/${schemaId}/${id}${query}`,
		{ headers: { Accept: 'application/json' }, timeout: REQUEST_TIMEOUT },
	)
	expect(resp.status(), `GET ${id}${query}`).toBe(200)
	const body = await resp.json()
	return body?.['@self']?._retention
}

test.describe('archival: _retention reads the same on create and on GET', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		register = await createRegister(request, RUN, 'shape-reg')

		schemaSlug = `${RUN}-shape-sch`
		const resp = await request.post(`${API}/schemas`, {
			headers: JSON_HEADERS,
			data: {
				slug: schemaSlug,
				title: 'E2E shape-sch',
				description: 'fixture schema with an archival annotation',
				properties: PROPERTIES,
				configuration: { 'x-openregister-archival': ARCHIVAL },
			},
			timeout: REQUEST_TIMEOUT,
		})
		expect(
			resp.status(),
			'the annotated schema must be created',
		).toBeLessThanOrEqual(201)
		const schema = await resp.json()
		schemaId = schema.id
		expect(
			schema?.configuration?.['x-openregister-archival'],
			'the schema must keep its archival annotation, or nothing below is archival',
		).toBeTruthy()

		await linkSchemaToRegister(request, register, [schemaId])
	})

	// Teardown in afterAll so it still runs when an assertion fails.
	test.afterAll(async ({ request }) => {
		if (schemaId !== 0) {
			// Drop the annotation first. Until it is gone every delete below is
			// refused, which is the archival gate working, not a teardown bug.
			await request
				.put(`${API}/schemas/${schemaId}`, {
					headers: JSON_HEADERS,
					data: {
						slug: schemaSlug,
						title: 'E2E shape-sch',
						properties: PROPERTIES,
						configuration: {},
					},
					timeout: REQUEST_TIMEOUT,
				})
				.catch(() => {})
		}
		for (const uuid of createdIds) {
			if (register?.id && schemaId !== 0) {
				await request
					.delete(`${API}/objects/${register.id}/${schemaId}/${uuid}`, {
						timeout: REQUEST_TIMEOUT,
					})
					.catch(() => {})
			}
			await request
				.delete(`${API}/deleted/${uuid}`, { timeout: REQUEST_TIMEOUT })
				.catch(() => {})
		}
		if (schemaId !== 0) {
			await deleteSchema(request, schemaId)
		}
		if (register?.id) {
			await deleteRegister(request, register.id)
		}
	})

	/**
	 * No rule matches, so the default applies, and that answer must survive the
	 * read. The whole block is compared as serialised JSON, key order included,
	 * because the defect was a difference in shape.
	 *
	 * @e2e openspec/specs/archival-annotation-vocabulary/spec.md#no-matching-rule-reads-the-same-on-create-and-on-read
	 */
	test('no matching rule: create and GET return the same block, and it says the default applied', async ({
		request,
	}) => {
		const { id, created, read } = await createAndReadBack(request, 500)

		// The answer itself: no rule fired, said with a value the read keeps.
		expect(created.annotation?.defaulted, 'the default applied').toBe(true)
		expect(
			Object.hasOwn(created.annotation ?? {}, 'matchedRule'),
			'no rule fired, so no rule index is claimed',
		).toBe(false)
		expect(created.retentionPeriod).toBe('P30D')

		// Nothing in the block is a null, because a null does not survive a GET.
		expect(
			nullPaths(created, '_retention'),
			'nulls in the create response',
		).toEqual([])

		// THE ASSERTION THIS FILE EXISTS FOR.
		expect(
			JSON.stringify(read),
			'GET must return the byte-for-byte same _retention as the create',
		).toBe(JSON.stringify(created))

		// And the block does not depend on the strip at all: asking the read
		// path to keep empty values changes nothing either.
		const unstripped = await retentionOnGet(request, id, '?_empty=true')
		expect(
			JSON.stringify(unstripped),
			'GET with _empty=true must return the same _retention too',
		).toBe(JSON.stringify(created))

		// Update and patch answer unstripped, like create, so they must agree
		// as well. Neither changes `statusCode`, so the decision is unchanged.
		const put = await request.put(
			`${API}/objects/${register?.id}/${schemaId}/${id}`,
			{
				headers: JSON_HEADERS,
				data: { title: `${RUN} status 500 updated`, statusCode: 500 },
				timeout: REQUEST_TIMEOUT,
			},
		)
		expect(put.status(), 'the update must succeed').toBe(200)
		expect(
			JSON.stringify((await put.json())?.['@self']?._retention),
			'the update response must carry the same _retention',
		).toBe(JSON.stringify(created))

		const patch = await request.patch(
			`${API}/objects/${register?.id}/${schemaId}/${id}`,
			{
				headers: JSON_HEADERS,
				data: { title: `${RUN} status 500 patched` },
				timeout: REQUEST_TIMEOUT,
			},
		)
		expect(patch.status(), 'the patch must succeed').toBe(200)
		expect(
			JSON.stringify((await patch.json())?.['@self']?._retention),
			'the patch response must carry the same _retention',
		).toBe(JSON.stringify(created))
	})

	/**
	 * A rule fires, so its index is the answer and `defaulted` is false. Rule
	 * index 0 is falsy, which is exactly the value a careless strip would lose.
	 *
	 * @e2e openspec/specs/archival-annotation-vocabulary/spec.md#archival-row-read-shows-the-resolved-decision
	 */
	test('a matched rule: create and GET return the same block, naming rule 0', async ({
		request,
	}) => {
		const { created, read } = await createAndReadBack(request, 200)

		expect(created.annotation?.matchedRule).toBe(0)
		expect(created.annotation?.defaulted).toBe(false)
		expect(created.retentionPeriod).toBe('PT1H')
		expect(created.basis).toBe('schema_annotation')
		expect(
			nullPaths(created, '_retention'),
			'nulls in the create response',
		).toEqual([])

		expect(
			JSON.stringify(read),
			'GET must return the byte-for-byte same _retention as the create',
		).toBe(JSON.stringify(created))
	})
})
