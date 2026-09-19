import type { APIRequestContext, Locator, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE ARCHIVAL DECISION, ON SCREEN, IN OPENREGISTER'S OWN OBJECT VIEW.
 *
 * openregister resolves an archival decision for every object that has one and
 * serves it at `@self._retention`. Until the object detail view mounted
 * `CnObjectMetadataWidget` (its Metadata tab), none of that reached a screen in
 * this app: `git grep` over `src/` found no `_retention`, `recordState`,
 * `disposalDate` or `appraisal`, so a records officer could read a disposal
 * date over the API and nowhere in the app that owns it. The API-level suite
 * cannot see that gap, because the API was never the part that was missing.
 *
 * So this spec seeds a schema that declares `x-openregister-archival`, creates
 * one object on it, opens that object through the deep-link route and reads the
 * widget's Archiving group back off the page:
 *
 *   - three POPULATED rows: appraisal, retention period and disposal date, each
 *     with its rendered value and without the empty modifier;
 *   - one BLANK row: the object carries no `archiefstatus`, so its record state
 *     is a fact it lacks, and nextcloud-vue 2.47.0+ keeps that core row as a
 *     dash marked `cn-detail-grid__value--empty` rather than dropping it;
 *   - exactly one blank row in the group, so a panel that renders every row as
 *     a dash cannot pass by being uniformly empty.
 *
 * The expected values are NOT read from `_retention`. The appraisal and the
 * period are what the fixture itself declares, and the disposal date is derived
 * from the row's own `@self.created`. A test that took its expectations from
 * the decision it is checking would agree with any decision, including none.
 *
 * LANGUAGE. The first test matches every label in English OR Dutch, so it holds
 * whichever language the instance's admin reads. The second one creates a
 * throwaway user whose language is Dutch and pins the Dutch labels exactly,
 * because a union pattern alone would pass forever on an app that never
 * registered the library's Dutch catalogue.
 *
 * HERMETIC AND SELF-CLEANING (the CI allow-list's criteria 1 and 2). Every
 * entity is created here through the REST and OCS APIs and carries the
 * `e2e-<timestamp>` prefix. An object on an archival schema refuses DELETE with
 * a 403, by design, and that refusal is decided from the schema's CURRENT
 * annotation, so `afterAll` first takes the annotation off the fixture schema
 * and then removes the object (soft, then hard through /api/deleted), the
 * schema, the register and the user. Nothing survives the run.
 */
import { seedFirstVisitOverlaysSeen } from '@conduction/nextcloud-vue/testing/playwright'
import { expect, request as pwRequest, test } from '@playwright/test'
import {
	createObject,
	createRegister,
	createSchema,
	getObject,
	linkSchemaToRegister,
	makeRunId,
	updateSchema,
} from './_fixtures.ts'
import { resolveBaseUrl } from './base-url.ts'

const BASE = resolveBaseUrl()
const API = '/index.php/apps/openregister/api'
const OCS_USERS = '/ocs/v2.php/cloud/users'
const RUN = makeRunId()

const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A reader whose Nextcloud language is Dutch. Created and removed by this file. */
const DUTCH_USER = `${RUN}-nl`
const DUTCH_PASS = 'E2e-Archival-Pass-4821!'

/** The schema's properties: a title plus the ZGW appraisal field the resolver reads. */
const PROPERTIES = {
	title: { type: 'string', title: 'Title' },
	archiefnominatie: { type: 'string', title: 'Archiefnominatie' },
}

/**
 * The archival annotation. A bare ten-year default and no rules: the fixture
 * needs a period and a derived date, not the rule engine (that is
 * archival-retention.spec.ts's subject).
 */
const ARCHIVAL_CONFIGURATION = {
	'x-openregister-archival': { retention: { default: 'P10Y' } },
}

/** Every label and value this spec reads, in both languages it must hold in. */
const LABELS = {
	group: { en: 'Archiving', nl: 'Archivering' },
	appraisal: { en: 'Appraisal', nl: 'Waardering' },
	retentionPeriod: { en: 'Retention period', nl: 'Bewaartermijn' },
	disposalDate: { en: 'Disposal date', nl: 'Archiefactiedatum' },
	recordState: { en: 'Record state', nl: 'Archiefstatus' },
}
const VALUES = {
	// `archiefnominatie: vernietigen` is resolved to the MDTO appraisal `destroy`.
	appraisal: { en: 'Destroy', nl: 'Vernietigen' },
	// `P10Y`, through the widget's plural-aware duration formatter.
	retentionPeriod: { en: '10 years', nl: '10 jaar' },
}

type Language = 'en' | 'nl'

/**
 * A pattern that matches the WHOLE text of an element, in any of the languages.
 *
 * Anchored, so "Record state" cannot match inside a longer label, and tolerant
 * of the whitespace a template's line breaks put around the text.
 *
 * @param entry     The per-language strings.
 * @param languages The languages to accept.
 * @return The anchored pattern.
 */
function whole(entry: Record<Language, string>, languages: Language[]): RegExp {
	const escaped = languages.map((lang) =>
		entry[lang].replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
	)
	return new RegExp(`^\\s*(?:${escaped.join('|')})\\s*$`)
}

/** Build an API context authenticated as one user. */
async function contextFor(
	user: string,
	password: string,
): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/**
 * Close any modal still covering the page after the first-visit seed.
 *
 * Fails loudly if a mask survives, instead of proceeding into a click that
 * cannot land. Escape rather than a synthetic event: a real user meets these
 * overlays too, and bypassing hit-testing would hide an overlay regression.
 *
 * @param page The page.
 */
async function dismissBlockingModals(page: Page): Promise<void> {
	const mask = page.locator('.modal-mask:visible')
	for (let attempt = 0; attempt < 3; attempt++) {
		if ((await mask.count()) === 0) {
			return
		}
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)
	}
	await expect(
		mask,
		'a modal is still covering the page after three Escapes; it will swallow the tab click',
	).toHaveCount(0)
}

/**
 * Open the object's detail view, select its Metadata tab and return the
 * Archiving group.
 *
 * The deep-link route `/objects/:register/:schema/:id` is the one declared in
 * `src/manifest.json`. Inactive AppTab panels are not rendered at all, so a
 * locator scoped to the Metadata panel can only find what is on screen.
 *
 * @param page      The page.
 * @param registerId The register id.
 * @param schemaId  The schema id.
 * @param uuid      The object id.
 * @param languages Which languages the group heading may be in.
 * @return The Archiving group's root element.
 */
async function openArchivingGroup(
	page: Page,
	registerId: number,
	schemaId: number,
	uuid: string,
	languages: Language[],
): Promise<Locator> {
	await seedFirstVisitOverlaysSeen(page, 'openregister')
	await page.goto(
		`/index.php/apps/openregister/objects/${registerId}/${schemaId}/${uuid}`,
		{ waitUntil: 'domcontentloaded', timeout: 60_000 },
	)

	const tab = page.getByRole('tab', { name: 'Metadata', exact: true })
	await expect(
		tab,
		'no Metadata tab on the object detail view: CnObjectMetadataWidget is not mounted, '
			+ 'or the deep-linked object never reached the view',
	).toBeVisible({ timeout: 45_000 })

	await dismissBlockingModals(page)
	await tab.click()

	const panel = page.getByRole('tabpanel', { name: 'Metadata', exact: true })
	const group = panel.locator('.cn-object-metadata__group').filter({
		has: page.locator('.cn-object-metadata__group-title', {
			hasText: whole(LABELS.group, languages),
		}),
	})
	await expect(
		group,
		'the Metadata tab rendered no Archiving group: the object reached the widget '
			+ 'without an archival decision, or the installed nextcloud-vue predates the group',
	).toHaveCount(1, { timeout: 20_000 })

	return group
}

/**
 * The value cell of one row in a group, found by its label.
 *
 * @param group The group root.
 * @param label The label pattern.
 * @return The row's value cell.
 */
function valueOf(group: Locator, label: RegExp): Locator {
	return group
		.locator('.cn-detail-grid__item')
		.filter({
			has: group.page().locator('.cn-detail-grid__label', { hasText: label }),
		})
		.locator('.cn-detail-grid__value')
}

/**
 * The disposal date as the widget renders it: the row's creation instant plus
 * ten years, formatted in the page with the widget's own date options, so the
 * browser's locale and time zone cancel out.
 *
 * @param page    The page.
 * @param created The object's `@self.created`.
 * @return The expected cell text.
 */
async function expectedDisposalText(page: Page, created: string): Promise<string> {
	return page.evaluate((iso) => {
		const date = new Date(iso)
		date.setUTCFullYear(date.getUTCFullYear() + 10)
		return date.toLocaleDateString(undefined, {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
		})
	}, created)
}

/**
 * Assert the four core rows of the Archiving group.
 *
 * The populated rows come first, so a panel that drew nothing fails on the
 * first value it cannot show rather than on the blank row it trivially "has".
 *
 * @param page      The page.
 * @param group     The Archiving group.
 * @param created   The object's `@self.created`.
 * @param languages The languages the labels and values may be in.
 */
async function assertCoreRows(
	page: Page,
	group: Locator,
	created: string,
	languages: Language[],
): Promise<void> {
	const appraisal = valueOf(group, whole(LABELS.appraisal, languages))
	await expect(appraisal, 'the appraisal row').toHaveText(
		whole(VALUES.appraisal, languages),
	)
	await expect(appraisal).not.toHaveClass(/cn-detail-grid__value--empty/)

	const period = valueOf(group, whole(LABELS.retentionPeriod, languages))
	await expect(
		period,
		"the retention period row: the annotation's P10Y default, rendered as a period",
	).toHaveText(whole(VALUES.retentionPeriod, languages))
	await expect(period).not.toHaveClass(/cn-detail-grid__value--empty/)

	const disposal = valueOf(group, whole(LABELS.disposalDate, languages))
	await expect(
		disposal,
		"the disposal date row: the row's creation plus the ten-year period",
	).toHaveText(await expectedDisposalText(page, created))
	await expect(disposal).not.toHaveClass(/cn-detail-grid__value--empty/)

	// The fact the object lacks. It has no `archiefstatus`, so the decision
	// carries no record state, and the core row stays as a marked dash rather
	// than disappearing: "no state yet" is itself the answer.
	const state = valueOf(group, whole(LABELS.recordState, languages))
	await expect(
		state,
		'the record state row must be present and blank: nextcloud-vue keeps the four core '
			+ 'rows whenever a decision exists',
	).toHaveText(/^\s*-\s*$/)
	await expect(state).toHaveClass(/cn-detail-grid__value--empty/)

	// And it is the ONLY blank one. A group that marked every row empty would
	// have passed the line above.
	await expect(
		group.locator('.cn-detail-grid__value--empty'),
		'exactly one Archiving row may be blank: the record state',
	).toHaveCount(1)
}

test.describe('the object detail view shows the archival decision', () => {
	// A cold deep link costs 13-23s on a loaded box, and each test here makes
	// one plus a handful of API calls. The config's 30s/45s budgets hold about
	// one such load, and a budget overrun reports a bare "Test timeout" that
	// names nothing.
	test.describe.configure({ mode: 'serial', timeout: 150_000 })

	let admin: APIRequestContext
	let registerId: number | null = null
	let registerSlug = ''
	let schemaId: number | null = null
	let schemaSlug = ''
	let schemaTitle = ''
	let objectUuid: string | null = null
	let created = ''
	let dutchUserCreated = false

	test.beforeAll(async ({ request }) => {
		admin = await contextFor(ADMIN, ADMIN_PASS)

		const register = await createRegister(request, RUN, 'meta-reg')
		registerId = register.id
		registerSlug = register.slug
		const schema = await createSchema(request, RUN, 'meta-sch', PROPERTIES)
		schemaId = schema.id
		schemaSlug = schema.slug
		schemaTitle = schema.title
		await updateSchema(request, schema, PROPERTIES, {
			configuration: ARCHIVAL_CONFIGURATION,
		})
		await linkSchemaToRegister(request, register, [schema.id])

		const object = await createObject(request, register.id, schema.id, {
			title: `${RUN} archival metadata`,
			archiefnominatie: 'vernietigen',
		})
		objectUuid = object.id

		const read = await getObject(request, register.id, schema.id, object.id)
		expect(read.status, 'the seeded object must read back').toBe(200)
		created = String(read.body?.['@self']?.created ?? '')
		expect(created, 'the object must report its creation instant').not.toBe('')

		// The Dutch reader. `language` on the OCS create sets the user's own
		// Nextcloud language, which is what the page is rendered in.
		const user = await admin.post(OCS_USERS, {
			form: { userid: DUTCH_USER, password: DUTCH_PASS, language: 'nl' },
		})
		expect(
			user.ok(),
			`creating the Dutch user failed: ${await user.text()}`,
		).toBeTruthy()
		dutchUserCreated = true
	})

	test.afterAll(async () => {
		// Take the annotation off first: the delete refusal is decided from the
		// schema's current configuration, so this is what makes the archival
		// object removable at all. Every step is best-effort and reports what it
		// could not do, so one failure does not strand the rest.
		const warn = (step: string, status: number) =>
			console.warn(
				`[object-metadata-archival] teardown ${step} answered ${status}`,
			)

		if (schemaId !== null) {
			const strip = await admin.put(`${API}/schemas/${schemaId}`, {
				data: {
					slug: schemaSlug,
					title: schemaTitle,
					properties: PROPERTIES,
					configuration: {},
				},
			})
			if (!strip.ok()) warn('strip annotation', strip.status())
		}
		if (registerId !== null && schemaId !== null && objectUuid !== null) {
			const del = await admin.delete(
				`${API}/objects/${registerId}/${schemaId}/${objectUuid}`,
			)
			if (!del.ok()) warn('object delete', del.status())
			const hard = await admin.delete(`${API}/deleted/${objectUuid}`)
			if (!hard.ok()) warn('object hard delete', hard.status())
		}
		if (schemaId !== null) {
			const del = await admin.delete(`${API}/schemas/${schemaId}`)
			if (!del.ok()) warn('schema delete', del.status())
		}
		if (registerId !== null) {
			const del = await admin.delete(`${API}/registers/${registerId}`)
			if (!del.ok()) warn(`register delete (${registerSlug})`, del.status())
		}
		if (dutchUserCreated) {
			const del = await admin.delete(`${OCS_USERS}/${DUTCH_USER}`)
			if (!del.ok()) warn('Dutch user delete', del.status())
		}
		await admin.dispose()
	})

	test('the Archiving group shows the decision, and a blank row for the fact the object lacks', async ({
		page,
	}) => {
		const group = await openArchivingGroup(
			page,
			registerId as number,
			schemaId as number,
			objectUuid as string,
			['en', 'nl'],
		)
		await assertCoreRows(page, group, created, ['en', 'nl'])
	})

	test('a Dutch reader gets the Archiving group in Dutch', async ({ browser }) => {
		const context = await browser.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${DUTCH_USER}:${DUTCH_PASS}`).toString('base64')}`,
			},
		})
		const page = await context.newPage()
		try {
			// WHO IS THIS PAGE, and in what language? Asserted, not assumed: if
			// the header override stopped taking effect this would silently
			// become the admin's English page, and the Dutch pins below would be
			// measuring nothing.
			const group = await openArchivingGroup(
				page,
				registerId as number,
				schemaId as number,
				objectUuid as string,
				['nl'],
			)
			const who = await page.evaluate(() => ({
				uid: (window as any).OC?.getCurrentUser?.()?.uid ?? null,
				lang: document.documentElement.lang,
			}))
			expect(who, 'the page must belong to the Dutch reader').toEqual({
				uid: DUTCH_USER,
				lang: 'nl',
			})

			await assertCoreRows(page, group, created, ['nl'])

			// Nothing in the Metadata panel may still be in English: a
			// half-registered catalogue would translate some labels and leave
			// the rest.
			const panel = page.getByRole('tabpanel', {
				name: 'Metadata',
				exact: true,
			})
			for (const entry of Object.values(LABELS)) {
				await expect(
					panel.getByText(whole(entry, ['en'])),
					`"${entry.en}" is still English on a Dutch page`,
				).toHaveCount(0)
			}
		} finally {
			await context.close()
		}
	})
})
