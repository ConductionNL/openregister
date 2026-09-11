import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ARCHIVAL AND RETENTION e2e.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A substantial archival implementation shipped with unit tests only. Before
 * this file, nothing in `tests/e2e/**` exercised it end to end:
 * `workflows/archival-transfer-hardening.spec.ts` is about the e-Depot
 * transfer, and all three of its tests skip unless `OR_EDEPOT_*_FIXTURE`
 * environment variables name pre-existing rows, so on a stock instance it
 * executes nothing. The resolved archival decision at `@self._retention`, the
 * record-state vocabulary and the Retention settings section were covered by
 * no executable end-to-end test at all.
 *
 * WHY TWO OF THE THREE GROUPS ASSERT OVER THE API
 * ----------------------------------------------
 * There is no UI that renders an archival decision. `git grep` over `src/`
 * finds no reference to `_retention`, `recordState`, `disposalDate` or
 * `appraisal` in any `.vue` or `.js` file, so a records officer cannot see a
 * disposal date, an appraisal or a transferred record's immutability anywhere
 * in the app. The decision is currently an API-only contract, and an e2e test
 * cannot assert on screen what the app never draws. The settings group below
 * IS driven through the browser, because that surface does exist.
 *
 * The alternative — filing these under `tests/e2e/api-direct/` per the gate-19
 * convention — would mean they never execute: `playwright.config.ts` excludes
 * that directory from the `chromium` project. A test that does not run is the
 * coverage we already had.
 *
 * FIXTURE CLEANUP IS DELIBERATELY PARTIAL
 * --------------------------------------
 * `DELETE /api/objects/...` on a schema that declares `x-openregister-archival`
 * is refused with HTTP 403, which is the whole point of the annotation, so the
 * seeded archival rows CANNOT be removed over HTTP by any caller — the only
 * sanctioned path is `occ openregister:objects:purge --apply --force`, which a
 * remote spec run has no access to. The refusal is asserted as a test below
 * rather than swallowed in teardown, so the rows that stay behind are evidence
 * rather than litter, and every entity this file creates carries the suite's
 * run-unique `e2e-<timestamp>` prefix so a leaked fixture is identifiable.
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import {
	createObject,
	createRegister,
	createSchema,
	deleteObject,
	deleteRegister,
	deleteSchema,
	linkSchemaToRegister,
	makeRunId,
} from './_fixtures.ts'

const STORAGE_STATE = path.resolve(__dirname, '.auth/admin.json')
const API = '/index.php/apps/openregister/api'
const RUN = makeRunId()

/** Slack allowed when comparing a derived disposal date to the row's creation. */
const DATE_TOLERANCE_MS = 5 * 60_000

// ─────────────────────────────────────────────────────────────────────────────
// The record-state vocabulary, read from its own authority.
//
// `lib/Service/Archival/RecordState.php` says, in its own words, that "READS
// ACCEPT THE OLD SPELLINGS" and that each state "carries an alias list holding
// its English name and the Dutch spellings it replaces". That file is the
// authority for WHICH spellings a stored record may use, so this spec reads the
// lists out of it rather than restating them: a spelling added there is then
// covered here the moment it lands, and a spelling REMOVED there reddens this
// test instead of silently narrowing what the guard recognises.
//
// Parsing throws rather than skipping. A skip and a pass look identical in a
// summary, and a vocabulary test that quietly stopped reading its vocabulary is
// exactly the failure this file is meant to catch.
// ─────────────────────────────────────────────────────────────────────────────

const RECORD_STATE_PHP = path.resolve(
	__dirname,
	'..',
	'..',
	'lib',
	'Service',
	'Archival',
	'RecordState.php',
)

/**
 * Read one `public const <NAME> = ['a', 'b'];` string list out of RecordState.php.
 *
 * @param  {string} source   The file contents.
 * @param  {string} constant The constant name.
 * @return {string[]}        Every single-quoted member, in declaration order.
 */
function aliasList(source: string, constant: string): string[] {
	const block = new RegExp(`const\\s+${constant}\\s*=\\s*\\[([^\\]]*)\\]`).exec(
		source,
	)
	if (block === null) {
		throw new Error(
			`RecordState.php no longer declares ${constant}; the record-state `
				+ 'vocabulary moved and this spec is reading a stale authority.',
		)
	}
	return [...block[1].matchAll(/'([^']+)'/g)].map((m) => m[1])
}

interface StateCase {
	/** The spelling as it would be found in stored data. */
	alias: string
	/** The canonical English record state it must resolve to. */
	canonical: string
	/** Whether a record in that state may no longer be changed. */
	immutable: boolean
}

/**
 * Build one case per accepted spelling, straight from RecordState.php.
 *
 * @return {StateCase[]} Every alias of every state.
 */
function recordStateCases(): StateCase[] {
	const source = fs.readFileSync(RECORD_STATE_PHP, 'utf8')
	const lists: Array<[string, string, boolean]> = [
		['ACTIVE_ALIASES', 'active', false],
		['SEMI_STATIC_ALIASES', 'semi_static', false],
		['TRANSFERRED_ALIASES', 'transferred', true],
		['DESTROYED_ALIASES', 'destroyed', true],
	]

	const cases: StateCase[] = []
	for (const [constant, canonical, immutable] of lists) {
		const aliases = aliasList(source, constant)
		// A list that parsed to nothing would make the loop below a no-op and
		// the whole test vacuously green, which is the shape this suite's own
		// history warns about. Demand the canonical name plus at least one
		// superseded spelling — the reason the alias list exists.
		if (aliases.includes(canonical) === false || aliases.length < 2) {
			throw new Error(
				`RecordState::${constant} parsed as [${aliases.join(', ')}] — expected `
					+ `'${canonical}' plus at least one superseded spelling.`,
			)
		}
		for (const alias of aliases) {
			cases.push({ alias, canonical, immutable })
		}
	}
	return cases
}

/**
 * Spellings `ArchivalDecisionResolver::STATE_ALIASES` does not translate.
 *
 * `gearchiveerd` is in `RecordState::SEMI_STATIC_ALIASES` but is absent from the
 * resolver's own map, so `@self._retention.recordState` answers the stored Dutch
 * word instead of `semi_static`. Its immutability is still correct, which is why
 * this is a legibility gap and not a retention one, and it is reported on the PR
 * rather than fixed here (this branch may not touch `lib/**`).
 *
 * The list is NAMED rather than the assertion loosened: a spelling that starts
 * failing to translate and is not on this list still reddens the test.
 */
const UNTRANSLATED_BY_RESOLVER = ['gearchiveerd']

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/** The archival annotation the fixture schema declares. */
function archivalAnnotation(): Record<string, unknown> {
	return {
		configuration: {
			'x-openregister-archival': {
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
			},
		},
	}
}

/** Schema properties: a title plus the ZGW archival fields the resolver reads. */
function archivalProperties(): Record<string, unknown> {
	return {
		title: { type: 'string', title: 'Title' },
		statusCode: { type: 'integer', title: 'Status code' },
		// `archiefnominatie` / `archiefstatus` are the ZGW contract an app
		// implementing the zaak API declares as ordinary schema properties;
		// ArchivalDecisionResolver::declaredArchivalFields() reads them.
		archiefnominatie: { type: 'string', title: 'Archiefnominatie' },
		archiefstatus: { type: 'string', title: 'Archiefstatus' },
	}
}

/** Fetch one object and return its resolved `@self._retention`, or null. */
async function retentionOf(
	request: APIRequestContext,
	registerId: number,
	schemaId: number,
	id: string,
): Promise<Record<string, any> | null> {
	const resp = await request.get(
		`${API}/objects/${registerId}/${schemaId}/${id}`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.status(), `GET object ${id}`).toBe(200)
	const body = await resp.json()
	return body?.['@self']?._retention ?? null
}

/** The row's own creation instant, as milliseconds. */
async function createdAtOf(
	request: APIRequestContext,
	registerId: number,
	schemaId: number,
	id: string,
): Promise<number> {
	const resp = await request.get(
		`${API}/objects/${registerId}/${schemaId}/${id}`,
		{ headers: { Accept: 'application/json' } },
	)
	expect(resp.status(), `GET object ${id}`).toBe(200)
	const body = await resp.json()
	const created = body?.['@self']?.created
	expect(created, 'the row must report its creation instant').toBeTruthy()
	return Date.parse(created)
}

// ─────────────────────────────────────────────────────────────────────────────
// Seeded fixtures: one archival schema, one plain schema, in one register.
// ─────────────────────────────────────────────────────────────────────────────

let registerId = 0
let archivalSchemaId = 0
let plainSchemaId = 0
let plainObjectId = ''

test.describe('archival — the resolved decision on an annotated schema', () => {
	test.use({ storageState: STORAGE_STATE })

	test.beforeAll(async ({ request }) => {
		const register = await createRegister(request, RUN, 'arch-reg')
		const archival = await createSchema(
			request,
			RUN,
			'arch-sch',
			archivalProperties(),
			archivalAnnotation(),
		)
		const plain = await createSchema(request, RUN, 'plain-sch', {
			title: { type: 'string', title: 'Title' },
		})
		await linkSchemaToRegister(request, register, [archival.id, plain.id])

		registerId = register.id
		archivalSchemaId = archival.id
		plainSchemaId = plain.id
	})

	test.afterAll(async ({ request }) => {
		// Best-effort, and deliberately incomplete. The archival rows refuse to
		// be deleted (asserted below), so the schema and the register refuse to
		// go with them; the plain schema's own object does come out.
		if (plainObjectId !== '') {
			await deleteObject(request, registerId, plainSchemaId, plainObjectId)
		}
		await deleteSchema(request, plainSchemaId)
		await deleteRegister(request, registerId)
	})

	/**
	 * The schema annotation alone establishes a decision: no ZGW field on the
	 * record, so the retention period, the disposal date and the basis all come
	 * from `x-openregister-archival`, and the rule that fired is named.
	 *
	 * @e2e openspec/specs/archival-annotation-vocabulary/spec.md#archival-row-read-shows-the-resolved-decision
	 */
	test('a matched annotation rule sets the retention period and derives the disposal date', async ({
		request,
	}) => {
		const object = await createObject(request, registerId, archivalSchemaId, {
			title: `${RUN} annotation only`,
			statusCode: 200,
		})
		const createdAt = await createdAtOf(
			request,
			registerId,
			archivalSchemaId,
			object.id,
		)
		const decision = await retentionOf(
			request,
			registerId,
			archivalSchemaId,
			object.id,
		)

		expect(
			decision,
			'an annotated schema must produce a decision',
		).not.toBeNull()
		// `statusCode: 200` matches `statusCode < 400`, so the rule's PT1H wins
		// over the annotation's P30D default.
		expect(decision?.retentionPeriod).toBe('PT1H')
		expect(decision?.basis).toBe('schema_annotation')
		// The rule index is what makes a wrong disposal date traceable.
		expect(decision?.annotation?.matchedRule).toBe(0)
		expect(decision?.annotation?.effectiveRetention).toBe('PT1H')

		// The disposal date is DERIVED, not stored: creation plus one hour.
		expect(
			decision?.disposalDate,
			'a decision must carry a disposal date',
		).toBeTruthy()
		const disposal = Date.parse(decision?.disposalDate)
		expect(Math.abs(disposal - (createdAt + 3_600_000))).toBeLessThan(
			DATE_TOLERANCE_MS,
		)
	})

	/**
	 * The appraisal comes from the record's own ZGW field, the retention period
	 * from the annotation's default once no rule matches, and both land in the
	 * one resolved block.
	 *
	 * @e2e openspec/specs/archival-annotation-vocabulary/spec.md#archival-row-read-shows-the-resolved-decision
	 */
	test('an unmatched rule falls back to the default, and the appraisal is normalised', async ({
		request,
	}) => {
		const object = await createObject(request, registerId, archivalSchemaId, {
			title: `${RUN} appraised`,
			statusCode: 500,
			archiefnominatie: 'vernietigen',
		})
		const createdAt = await createdAtOf(
			request,
			registerId,
			archivalSchemaId,
			object.id,
		)
		const decision = await retentionOf(
			request,
			registerId,
			archivalSchemaId,
			object.id,
		)

		expect(decision).not.toBeNull()
		// MDTO concepts in English: `vernietigen` is the stored spelling, and
		// the abstract answer is `destroy`.
		expect(decision?.appraisal).toBe('destroy')
		// No rule matched (500 is not < 400), so the default applies.
		expect(decision?.retentionPeriod).toBe('P30D')
		// "No rule matched" reaches the wire in two shapes: the CREATE response
		// carries `matchedRule: null`, the READ response drops the key entirely
		// (null-valued keys are stripped on GET). Both mean the same thing and
		// both must NOT be a rule index, which is what this asserts. The shape
		// difference between the two responses is reported on the PR.
		expect(decision?.annotation?.matchedRule ?? null).toBeNull()

		const disposal = Date.parse(decision?.disposalDate)
		expect(Math.abs(disposal - (createdAt + 30 * 86_400_000))).toBeLessThan(
			DATE_TOLERANCE_MS,
		)
	})

	/**
	 * Silence is a distinct answer from "nothing to keep": a schema with no
	 * archival annotation and a record with no archival fields must carry no
	 * `_retention` key at all.
	 *
	 * @e2e openspec/specs/archival-annotation-vocabulary/spec.md#non-archival-schema-read-does-not-show-retention
	 */
	test('a schema without the annotation produces no decision at all', async ({
		request,
	}) => {
		const object = await createObject(request, registerId, plainSchemaId, {
			title: `${RUN} plain`,
		})
		plainObjectId = object.id

		const resp = await request.get(
			`${API}/objects/${registerId}/${plainSchemaId}/${object.id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		// Absent, not empty. An empty `_retention: {}` would read as "we looked
		// and there is no obligation", which is a different fact.
		expect(Object.hasOwn(body['@self'], '_retention')).toBe(false)
	})

	/**
	 * The annotation is not only descriptive: it takes the delete away. This is
	 * also why the fixtures above cannot be torn down over HTTP.
	 *
	 * @e2e openspec/specs/archival-annotation-vocabulary/spec.md#admin-delete-on-an-archival-schema-is-rejected
	 */
	test('an archival row refuses to be deleted, and survives the attempt', async ({
		request,
	}) => {
		const object = await createObject(request, registerId, archivalSchemaId, {
			title: `${RUN} undeletable`,
			statusCode: 204,
		})

		const del = await request.delete(
			`${API}/objects/${registerId}/${archivalSchemaId}/${object.id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(del.status(), 'an archival row may not be deleted by a user').toBe(
			403,
		)
		const body = await del.json()
		// NOTE the shape: the spec requires the structured body
		// `{error: "SCHEMA_ARCHIVAL_IMMUTABLE", message, schema, operation, hint}`
		// that `ArchivalImmutableException::toResponseBody()` builds, and this
		// route does not use it — `error` is the whole prose sentence with the
		// code glued to its front. Asserted as a substring so this test stays
		// green both before and after that is corrected; the divergence is
		// reported on the PR rather than fixed here.
		expect(String(body?.error)).toContain('SCHEMA_ARCHIVAL_IMMUTABLE')

		// The row is still there, which is the assertion that matters.
		const after = await request.get(
			`${API}/objects/${registerId}/${archivalSchemaId}/${object.id}`,
			{ headers: { Accept: 'application/json' } },
		)
		expect(after.status(), 'the refused row must still exist').toBe(200)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// The record-state vocabulary, in every spelling stored data may carry.
// ─────────────────────────────────────────────────────────────────────────────

test.describe('archival — the record-state vocabulary honours both spellings', () => {
	test.use({ storageState: STORAGE_STATE })

	let stateRegisterId = 0
	let stateSchemaId = 0

	test.beforeAll(async ({ request }) => {
		const register = await createRegister(request, RUN, 'state-reg')
		const schema = await createSchema(
			request,
			RUN,
			'state-sch',
			archivalProperties(),
			archivalAnnotation(),
		)
		await linkSchemaToRegister(request, register, [schema.id])
		stateRegisterId = register.id
		stateSchemaId = schema.id
	})

	/**
	 * Every spelling RecordState accepts must reach the same verdict about
	 * whether the record may still be changed.
	 *
	 * This is the assertion that matters most. Matching only the English
	 * spellings would make every record written before the vocabulary landed
	 * look live: a transferred record would read as editable and a destroyed one
	 * as sweepable, which is the direction that keeps personal data past its
	 * lawful term. The cases come from RecordState.php itself, so the coverage
	 * cannot drift away from the authority.
	 *
	 * @e2e openspec/specs/archival-annotation-vocabulary/spec.md#archival-row-read-shows-the-resolved-decision
	 */
	test('every accepted spelling resolves to its English state with the right immutability', async ({
		request,
	}) => {
		const cases = recordStateCases()
		// Ten spellings across four states today. Guard the count so a parse
		// that silently thinned out cannot pass as a full sweep.
		expect(
			cases.length,
			'every state contributes its aliases',
		).toBeGreaterThanOrEqual(8)

		for (const { alias, canonical, immutable } of cases) {
			const object = await createObject(
				request,
				stateRegisterId,
				stateSchemaId,
				{
					title: `${RUN} state ${alias}`,
					statusCode: 500,
					archiefstatus: alias,
				},
			)
			const decision = await retentionOf(
				request,
				stateRegisterId,
				stateSchemaId,
				object.id,
			)

			expect(decision, `'${alias}' must establish a decision`).not.toBeNull()

			// The safety-critical half: a transferred or destroyed record is
			// final, whichever spelling it was stored under, and a live one is
			// not. This holds for every alias.
			expect(
				decision?.immutable,
				`'${alias}' means ${canonical}, so immutable must be ${immutable}`,
			).toBe(immutable)

			// The legibility half: the abstract layer answers in English. One
			// spelling is not yet translated by the resolver (see
			// UNTRANSLATED_BY_RESOLVER); accept the stored word for THOSE only,
			// so a new untranslated spelling still fails here.
			const acceptable = UNTRANSLATED_BY_RESOLVER.includes(alias)
				? [canonical, alias]
				: [canonical]
			expect(
				acceptable,
				`'${alias}' must resolve to the canonical '${canonical}'`,
			).toContain(decision?.recordState)
		}
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// The Retention settings section — the one archival surface that has a UI.
// ─────────────────────────────────────────────────────────────────────────────

test.describe('retention — the admin settings section persists a change', () => {
	test.use({ storageState: STORAGE_STATE })
	// Two full loads of the admin settings page plus two round trips to the
	// settings API. The config-wide 30s budget covers roughly one such load on a
	// loaded box, and a test cancelled by the budget reports a bare "Test
	// timeout" that names nothing — the failure mode this whole suite's comments
	// warn about. Give the browser leg room the API legs do not need.
	test.describe.configure({ timeout: 180_000 })

	const ADMIN_SETTINGS = '/index.php/settings/admin/openregister'
	const FIELD = '#retention-object-archive'

	/**
	 * Open the Retention section, change the soft-delete-after-inactivity
	 * period, save, RELOAD, and read the field back off the reloaded page.
	 *
	 * The reload is the whole test: without it the assertion would only prove
	 * the input still holds what was typed into it, which a store that never
	 * reached the server would satisfy just as well.
	 *
	 * @e2e openspec/specs/retention-management/spec.md#update-retention-settings
	 * @e2e openspec/specs/retention-management/spec.md#read-retention-settings
	 */
	test('changing a retention period survives a reload', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS, {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})

		const section = page
			.locator('.settings-section')
			.filter({ hasText: 'Configure data and log retention policies' })
			.first()
		await expect(section, 'the Retention section must render').toBeVisible({
			timeout: 45_000,
		})

		const field = section.locator(FIELD)
		await expect(field).toBeVisible({ timeout: 30_000 })
		const original = await field.inputValue()
		expect(original, 'the field must load a stored value').not.toBe('')

		// The probe is DERIVED from what was there, never a fixed literal. A
		// literal that happened to equal the instance's stored value would make
		// this test pass while proving nothing — it would read back a value
		// nobody wrote. Adding to the stored number can never collide with it.
		const probe = String(Number(original) + 1234)
		expect(probe, 'the probe must differ from the stored value').not.toBe(
			original,
		)

		await field.fill(probe)

		// Wait on the PUT itself rather than a toast: the toast is the store's
		// report of the write, and this test is about the write.
		const saved = page.waitForResponse(
			(r) =>
				r.url().includes('/api/settings/retention')
				&& r.request().method() === 'PUT',
			{ timeout: 45_000 },
		)
		await section.getByRole('button', { name: 'Save' }).click()
		expect((await saved).status(), 'the save must succeed').toBe(200)

		await page.reload({ waitUntil: 'domcontentloaded', timeout: 60_000 })
		const reloaded = page
			.locator('.settings-section')
			.filter({ hasText: 'Configure data and log retention policies' })
			.first()
		await expect(reloaded.locator(FIELD)).toHaveValue(probe, {
			timeout: 45_000,
		})

		// Put the instance back the way it was found — this section is instance
		// state, not a per-run fixture, so leaving a probe value behind would
		// change the retention policy of whatever instance ran the suite.
		const restored = page.waitForResponse(
			(r) =>
				r.url().includes('/api/settings/retention')
				&& r.request().method() === 'PUT',
			{ timeout: 45_000 },
		)
		await reloaded.locator(FIELD).fill(original)
		await reloaded.getByRole('button', { name: 'Save' }).click()
		expect((await restored).status(), 'the restore must succeed').toBe(200)
	})
})
