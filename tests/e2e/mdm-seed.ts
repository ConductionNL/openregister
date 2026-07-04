/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Self-seeding MDM fixture for the deep, data-dependent e2e layer
 * (mdm-frontend / mdm-merge-ui / mdm-survivorship-override).
 *
 * The three committed MDM spec-coverage suites drive the REAL steward UI
 * (RegisterSchemaSelector → Duplicate Candidates / Master entities / merge
 * wizard / conflict-resolution modal). Without survivorship data with
 * disagreeing sources and a genuine duplicate pair, those suites can only
 * assert empty states and then `test.skip()`. This module seeds exactly that
 * data through the real OpenRegister REST API so the full
 * duplicate→merge→reverse and conflict-resolution chains actually run.
 *
 * Contract:
 *   - Discovers the `pipelinq` register + its `masterEntity` (and
 *     `sourceRecord` / `mergeOperation`) schemas at runtime. On an instance
 *     WITHOUT pipelinq installed it no-ops and returns null (specs then keep
 *     their existing skip fallback).
 *   - Seeds master entities whose `sourceRecords[].mappedAttributes` +
 *     self-consistent `goldenRecord` / `attributeProvenance` pass the
 *     masterEntity validation (non-empty goldenRecord + provenance required)
 *     AND survive the SurvivorshipRecomputeListener recompute-on-save (which,
 *     when present, recomputes goldenRecord from the source records — the
 *     mappedAttributes are authored to match the supplied goldenRecord so the
 *     result is identical either way).
 *   - Idempotent: every seeded masterId is prefixed with `e2e-mdm-` and prior
 *     `e2e-mdm-` rows are removed before re-seeding.
 *   - Writes the discovered ids + seeded uuids to `tests/e2e/.mdm-seed.json`
 *     for the specs to read.
 *
 * All requests use Playwright's APIRequestContext (Basic-auth admin/admin via
 * the caller's context), so seeding works without a browser session.
 */
import { type APIRequestContext } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const API = '/index.php/apps/openregister/api'
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' }

/** Run marker prefixed onto every seeded masterId for idempotent cleanup. */
export const MDM_MARKER = 'e2e-mdm-'

/** Where the discovered ids + seeded uuids are written for the specs to read. */
export const MDM_SEED_FILE = path.resolve(__dirname, '.mdm-seed.json')

export interface MdmSeed {
	/** pipelinq register id. */
	register: number
	/** masterEntity schema id. */
	masterEntitySchema: number
	/** sourceRecord schema id (informational; may be null). */
	sourceRecordSchema: number | null
	/** mergeOperation schema id (informational; may be null). */
	mergeOperationSchema: number | null
	/** The two master-entity uuids forming the duplicate candidate pair. */
	dupPair: [string, string]
	/** The multi-source conflict master-entity uuid. */
	conflictUuid: string
}

interface DiscoveredIds {
	register: number
	masterEntitySchema: number
	sourceRecordSchema: number | null
	mergeOperationSchema: number | null
}

/** Normalise a list endpoint's envelope to a plain array. */
function rows(body: unknown): Array<Record<string, any>> {
	if (Array.isArray(body)) return body as Array<Record<string, any>>
	const results = (body as Record<string, any>)?.results
	return Array.isArray(results) ? results : []
}

/** Extract the canonical object id from either the `@self` envelope or a flat body. */
function objectId(body: Record<string, any>): string | null {
	return body?.['@self']?.id ?? body?.id ?? null
}

/**
 * Discover the pipelinq register + its masterEntity / sourceRecord /
 * mergeOperation schema ids. Returns null when pipelinq (or its masterEntity
 * schema) is not installed.
 */
async function discover(request: APIRequestContext): Promise<DiscoveredIds | null> {
	const regResp = await request.get(`${API}/registers?_limit=500`, { headers: { Accept: 'application/json' } })
	if (!regResp.ok()) return null
	const register = rows(await regResp.json()).find((r) => r.slug === 'pipelinq')
	if (!register?.id) return null

	const schemaResp = await request.get(`${API}/registers/${register.id}/schemas`, { headers: { Accept: 'application/json' } })
	if (!schemaResp.ok()) return null
	const schemas = rows(await schemaResp.json())
	const bySlug = (slug: string): number | null => schemas.find((s) => s.slug === slug)?.id ?? null

	const masterEntitySchema = bySlug('masterEntity')
	if (!masterEntitySchema) return null

	return {
		register: register.id,
		masterEntitySchema,
		sourceRecordSchema: bySlug('sourceRecord'),
		mergeOperationSchema: bySlug('mergeOperation'),
	}
}

/** Delete any prior `e2e-mdm-` master entities so re-seeding is idempotent. */
async function cleanPriorSeed(request: APIRequestContext, register: number, schema: number): Promise<void> {
	const resp = await request.get(`${API}/objects/${register}/${schema}?_limit=500`, { headers: { Accept: 'application/json' } })
	if (!resp.ok()) return
	for (const obj of rows(await resp.json())) {
		const masterId = String(obj.masterId ?? '')
		const id = objectId(obj)
		if (masterId.startsWith(MDM_MARKER) && id) {
			await request.delete(`${API}/objects/${register}/${schema}/${id}`).catch(() => {})
		}
	}
}

/** Create one master entity and return its uuid. */
async function createMaster(
	request: APIRequestContext,
	register: number,
	schema: number,
	data: Record<string, unknown>,
): Promise<string> {
	const resp = await request.post(`${API}/objects/${register}/${schema}`, { headers: JSON_HEADERS, data })
	if (resp.status() > 201) {
		throw new Error(`mdm-seed: createMaster(${String(data.masterId)}) failed ${resp.status()}: ${await resp.text()}`)
	}
	const id = objectId(await resp.json())
	if (!id) throw new Error(`mdm-seed: createMaster(${String(data.masterId)}) returned no id`)
	return id
}

/** Build a source-record entry (sourceSystem + mappedAttributes + freshness anchor). */
function source(sourceSystem: string, lastChange: string, mapped: Record<string, unknown>): Record<string, unknown> {
	return { sourceSystem, lastChange, mappedAttributes: mapped }
}

/** Build a minimal, self-consistent provenance map for a golden record. */
function provenance(sourceSystem: string, golden: Record<string, unknown>): Record<string, unknown> {
	const out: Record<string, unknown> = {}
	for (const [attribute, value] of Object.entries(golden)) {
		out[attribute] = { value, sourceSystem, trustTier: 'bronze' }
	}
	return out
}

/**
 * Poll the duplicate-detection endpoint until it reports at least one pair
 * (the seeded Rijkswaterstaat pair scores ~0.78 against the schema's 0.7
 * threshold). Best-effort: returns false rather than throwing so a transient
 * or route-missing instance does not abort globalSetup.
 */
async function verifyDuplicates(request: APIRequestContext, register: number, schema: number): Promise<boolean> {
	for (let attempt = 0; attempt < 6; attempt++) {
		const resp = await request.get(`${API}/objects/duplicates/${register}/${schema}?limit=50`, { headers: { Accept: 'application/json' } })
		if (resp.ok()) {
			const body = await resp.json().catch(() => ({}))
			const items = Array.isArray(body?.items) ? body.items : rows(body)
			if (items.length >= 1) return true
		}
		await new Promise((r) => setTimeout(r, 1000))
	}
	return false
}

/**
 * Seed the MDM fixture and write `tests/e2e/.mdm-seed.json`. Returns the seed
 * descriptor, or null when pipelinq / masterEntity is not installed (the
 * caller then leaves the specs to their skip fallback).
 */
export async function seedMdm(request: APIRequestContext): Promise<MdmSeed | null> {
	const ids = await discover(request)
	if (ids === null) {
		// Not a pipelinq instance — remove any stale seed file so specs skip.
		fs.rmSync(MDM_SEED_FILE, { force: true })
		return null
	}

	const { register, masterEntitySchema } = ids
	await cleanPriorSeed(request, register, masterEntitySchema)

	// ── Duplicate pair: identical kvkNumber + email, slightly different name. ──
	const dupGoldenA = { kvkNumber: '77777777', email: 'info@rijkswaterstaat.nl', name: 'Rijkswaterstaat' }
	const dupA = await createMaster(request, register, masterEntitySchema, {
		masterId: `${MDM_MARKER}dup-a`,
		entityType: 'account',
		status: 'active',
		goldenRecord: dupGoldenA,
		attributeProvenance: provenance('kvk', dupGoldenA),
		sourceRecords: [source('kvk', '2026-01-10T00:00:00Z', dupGoldenA)],
		lastSourceUpdate: '2026-06-01T00:00:00Z',
	})

	const dupGoldenB = { kvkNumber: '77777777', email: 'info@rijkswaterstaat.nl', name: 'Rijkswaterstaat B.V.' }
	const dupB = await createMaster(request, register, masterEntitySchema, {
		masterId: `${MDM_MARKER}dup-b`,
		entityType: 'account',
		status: 'active',
		goldenRecord: dupGoldenB,
		attributeProvenance: provenance('kamer', dupGoldenB),
		sourceRecords: [source('kamer', '2026-02-10T00:00:00Z', dupGoldenB)],
		lastSourceUpdate: '2026-06-01T00:00:00Z',
	})

	// ── Multi-source conflict: two sources disagree on `name`, agree on email. ──
	const conflictGolden = { kvkNumber: '88888888', email: 'contact@acme.nl', name: 'ACME NV' }
	const conflictUuid = await createMaster(request, register, masterEntitySchema, {
		masterId: `${MDM_MARKER}conflict`,
		entityType: 'account',
		status: 'active',
		goldenRecord: conflictGolden,
		attributeProvenance: provenance('crm', conflictGolden),
		sourceRecords: [
			source('crm', '2026-03-01T00:00:00Z', { kvkNumber: '88888888', email: 'contact@acme.nl', name: 'ACME NV' }),
			source('erp', '2026-03-05T00:00:00Z', { kvkNumber: '88888888', email: 'contact@acme.nl', name: 'ACME B.V.' }),
		],
		lastSourceUpdate: '2026-06-01T00:00:00Z',
	})

	// ── A few plain scored entities (good / fair / poor completeness). ──
	const goodGolden = { kvkNumber: '12345678', email: 'hello@complete.example', name: 'Complete Data BV' }
	await createMaster(request, register, masterEntitySchema, {
		masterId: `${MDM_MARKER}score-good`,
		entityType: 'account',
		status: 'active',
		goldenRecord: goodGolden,
		attributeProvenance: provenance('kvk', goodGolden),
		sourceRecords: [source('kvk', '2026-06-20T00:00:00Z', goodGolden)],
		lastSourceUpdate: '2026-06-20T00:00:00Z',
	})

	const fairGolden = { kvkNumber: 'not-valid', email: 'partial@fair.example', name: 'Partial Data BV' }
	await createMaster(request, register, masterEntitySchema, {
		masterId: `${MDM_MARKER}score-fair`,
		entityType: 'account',
		status: 'active',
		goldenRecord: fairGolden,
		attributeProvenance: provenance('crm', fairGolden),
		sourceRecords: [source('crm', '2026-01-01T00:00:00Z', fairGolden)],
		lastSourceUpdate: '2026-01-01T00:00:00Z',
	})

	const poorGolden = { kvkNumber: 'xx', email: 'not-an-email', name: 'Sparse Record' }
	await createMaster(request, register, masterEntitySchema, {
		masterId: `${MDM_MARKER}score-poor`,
		entityType: 'account',
		status: 'active',
		goldenRecord: poorGolden,
		attributeProvenance: provenance('legacy', poorGolden),
		sourceRecords: [source('legacy', '2023-01-01T00:00:00Z', poorGolden)],
		lastSourceUpdate: '2023-01-01T00:00:00Z',
	})

	// Best-effort verification that the pair is now detectable.
	await verifyDuplicates(request, register, masterEntitySchema)

	const seed: MdmSeed = {
		register,
		masterEntitySchema,
		sourceRecordSchema: ids.sourceRecordSchema,
		mergeOperationSchema: ids.mergeOperationSchema,
		dupPair: [dupA, dupB],
		conflictUuid,
	}
	fs.writeFileSync(MDM_SEED_FILE, JSON.stringify(seed, null, 2))
	return seed
}

/** Read the seed descriptor written by {@see seedMdm}, or null when absent. */
export function readMdmSeed(): MdmSeed | null {
	try {
		if (!fs.existsSync(MDM_SEED_FILE)) return null
		return JSON.parse(fs.readFileSync(MDM_SEED_FILE, 'utf-8')) as MdmSeed
	} catch {
		return null
	}
}
