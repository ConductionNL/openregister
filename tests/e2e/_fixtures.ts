import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Seeded-fixture helper for the deep, data-dependent e2e layer.
 *
 * These helpers create and tear down real OpenRegister entities through the
 * actual OR REST API so the CRUD / workflow specs are deterministic and
 * self-cleaning:
 *
 *   - Every entity is namespaced with a per-run prefix
 *     `e2e-<Date.now()>-<n>` (see makeRunId) so parallel test agents never
 *     collide and a leaked fixture is trivially identifiable.
 *   - `beforeAll` seeds; `afterAll` deletes exactly what was seeded.
 *
 * The verbs map 1:1 onto the documented OR controllers — NO invented methods:
 *   Registers  POST/GET/PUT/DELETE  /api/registers[/{id}]
 *   Schemas    POST/GET/PUT/DELETE  /api/schemas[/{id}]
 *   Objects    POST/GET/PUT/DELETE  /api/objects/{register}/{schema}[/{id}]
 *   (findAll = GET list with the standard {results,total} envelope.)
 *
 * All requests use Playwright's `APIRequestContext`, which inherits the
 * Basic-auth `extraHTTPHeaders` from playwright.config.ts (admin/admin), so
 * fixture setup works without a browser session.
 */
import { expect } from '@playwright/test'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = {
	'Content-Type': 'application/json',
	Accept: 'application/json',
}

/** A run-unique prefix. Call once per spec file (top-level const). */
export function makeRunId(): string {
	return `e2e-${Date.now()}`
}

export interface SeededRegister {
	id: number
	slug: string
	title: string
}

export interface SeededSchema {
	id: number
	slug: string
	title: string
}

export interface SeededObject {
	id: string
	[key: string]: unknown
}

/** Extract the canonical object id from either the `@self` envelope or a flat body. */
export function objectId(body: Record<string, any>): string | null {
	return body?.['@self']?.id ?? body?.id ?? null
}

// ─────────────────────────────────────────────────────────────────────────────
// Registers
// ─────────────────────────────────────────────────────────────────────────────

export async function createRegister(
	request: APIRequestContext,
	runId: string,
	suffix = 'reg',
	overrides: Record<string, unknown> = {},
): Promise<SeededRegister> {
	const slug = `${runId}-${suffix}`
	const resp = await request.post(`${API}/registers`, {
		headers: JSON_HEADERS,
		data: {
			slug,
			title: `E2E ${suffix}`,
			description: 'fixture register',
			...overrides,
		},
	})
	expect(resp.status(), `createRegister(${slug})`).toBeLessThanOrEqual(201)
	const body = await resp.json()
	return { id: body.id, slug: body.slug ?? slug, title: body.title }
}

export async function updateRegister(
	request: APIRequestContext,
	register: SeededRegister,
	patch: Record<string, unknown>,
): Promise<Record<string, any>> {
	const resp = await request.put(`${API}/registers/${register.id}`, {
		headers: JSON_HEADERS,
		data: { slug: register.slug, title: register.title, ...patch },
	})
	expect(resp.status(), `updateRegister(${register.id})`).toBe(200)
	return resp.json()
}

export async function deleteRegister(
	request: APIRequestContext,
	id: number,
): Promise<void> {
	await request.delete(`${API}/registers/${id}`).catch(() => {})
}

// ─────────────────────────────────────────────────────────────────────────────
// Schemas
// ─────────────────────────────────────────────────────────────────────────────

/** A small, valid two-property schema (title:string, count:integer). */
export function twoPropertySchema(): Record<string, unknown> {
	return {
		title: { type: 'string', title: 'Title', description: 'Object title' },
		count: { type: 'integer', title: 'Count', description: 'A numeric value' },
	}
}

export async function createSchema(
	request: APIRequestContext,
	runId: string,
	suffix = 'sch',
	properties: Record<string, unknown> = twoPropertySchema(),
): Promise<SeededSchema> {
	const slug = `${runId}-${suffix}`
	const resp = await request.post(`${API}/schemas`, {
		headers: JSON_HEADERS,
		data: {
			slug,
			title: `E2E ${suffix}`,
			description: 'fixture schema',
			properties,
		},
	})
	expect(resp.status(), `createSchema(${slug})`).toBeLessThanOrEqual(201)
	const body = await resp.json()
	return { id: body.id, slug: body.slug ?? slug, title: body.title }
}

export async function updateSchema(
	request: APIRequestContext,
	schema: SeededSchema,
	properties: Record<string, unknown>,
	patch: Record<string, unknown> = {},
): Promise<Record<string, any>> {
	const resp = await request.put(`${API}/schemas/${schema.id}`, {
		headers: JSON_HEADERS,
		data: { slug: schema.slug, title: schema.title, properties, ...patch },
	})
	expect(resp.status(), `updateSchema(${schema.id})`).toBe(200)
	return resp.json()
}

export async function deleteSchema(
	request: APIRequestContext,
	id: number,
): Promise<void> {
	await request.delete(`${API}/schemas/${id}`).catch(() => {})
}

/** Attach a schema to a register so objects can be created under register+schema. */
export async function linkSchemaToRegister(
	request: APIRequestContext,
	register: SeededRegister,
	schemaIds: number[],
): Promise<void> {
	const resp = await request.put(`${API}/registers/${register.id}`, {
		headers: JSON_HEADERS,
		data: { slug: register.slug, title: register.title, schemas: schemaIds },
	})
	expect(resp.status(), `linkSchemaToRegister(${register.id})`).toBe(200)
}

// ─────────────────────────────────────────────────────────────────────────────
// Objects
// ─────────────────────────────────────────────────────────────────────────────

export async function createObject(
	request: APIRequestContext,
	registerId: number,
	schemaId: number,
	data: Record<string, unknown>,
): Promise<SeededObject> {
	const resp = await request.post(`${API}/objects/${registerId}/${schemaId}`, {
		headers: JSON_HEADERS,
		data,
	})
	expect(resp.status(), 'createObject').toBeLessThanOrEqual(201)
	const body = await resp.json()
	const id = objectId(body)
	expect(id, 'created object must have an id').toBeTruthy()
	return { id: id as string, ...body }
}

export async function getObject(
	request: APIRequestContext,
	registerId: number,
	schemaId: number,
	id: string,
): Promise<{ status: number; body: Record<string, any> | null }> {
	const resp = await request.get(
		`${API}/objects/${registerId}/${schemaId}/${id}`,
		{
			headers: { Accept: 'application/json' },
		},
	)
	const body = resp.ok() ? await resp.json().catch(() => null) : null
	return { status: resp.status(), body }
}

export async function updateObject(
	request: APIRequestContext,
	registerId: number,
	schemaId: number,
	id: string,
	data: Record<string, unknown>,
): Promise<Record<string, any>> {
	const resp = await request.put(
		`${API}/objects/${registerId}/${schemaId}/${id}`,
		{
			headers: JSON_HEADERS,
			data,
		},
	)
	expect(resp.status(), 'updateObject').toBe(200)
	return resp.json()
}

export async function deleteObject(
	request: APIRequestContext,
	registerId: number,
	schemaId: number,
	id: string,
): Promise<void> {
	await request
		.delete(`${API}/objects/${registerId}/${schemaId}/${id}`)
		.catch(() => {})
}

/** List objects under register+schema (findAll). Returns the {results,total} envelope. */
export async function listObjects(
	request: APIRequestContext,
	registerId: number,
	schemaId: number,
	limit = 50,
): Promise<{ results: Array<Record<string, any>>; total: number }> {
	const resp = await request.get(
		`${API}/objects/${registerId}/${schemaId}?_limit=${limit}`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.status(), 'listObjects').toBe(200)
	const body = await resp.json()
	return { results: body.results ?? [], total: body.total ?? 0 }
}

// ─────────────────────────────────────────────────────────────────────────────
// Composite seed: a register + linked schema, ready for object CRUD.
// ─────────────────────────────────────────────────────────────────────────────

export interface SeededTarget {
	register: SeededRegister
	schema: SeededSchema
}

export async function seedRegisterWithSchema(
	request: APIRequestContext,
	runId: string,
	properties: Record<string, unknown> = twoPropertySchema(),
): Promise<SeededTarget> {
	const register = await createRegister(request, runId)
	const schema = await createSchema(request, runId, 'sch', properties)
	await linkSchemaToRegister(request, register, [schema.id])
	return { register, schema }
}

export async function teardownTarget(
	request: APIRequestContext,
	target: SeededTarget,
): Promise<void> {
	// Objects are removed with the register/schema teardown; delete the pair.
	await deleteSchema(request, target.schema.id)
	await deleteRegister(request, target.register.id)
}
