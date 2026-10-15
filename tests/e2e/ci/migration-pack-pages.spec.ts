import type { APIRequestContext, APIResponse, Page, Request } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MIGRATION PACKS, driven through the browser and the API of a running instance.
 *
 * Two manifest pages: `migration-packs` (MigrationPacksIndex, the list) and
 * `migration-pack-detail` (MigrationPackDetail, the editor), and the
 * `/api/migration-packs` routes behind them. The Jest specs in
 * src/views/migrationPack/ drive the components' options objects against a
 * synthetic `this`, so none of them can fail when a page never renders, a
 * button is shown to the wrong user, or a save loses a key on the server. The
 * PHPUnit suite proves the controller against mocks; it cannot prove that the
 * routes are registered, that Nextcloud's middleware refuses a non-admin or a
 * missing request token, or that a stored definition comes back as sent.
 *
 * Every test names the scenario it proves with an `@e2e` anchor of the form
 * `migration-mapping-packs::<scenario-slug>`, resolved against
 * openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md
 * (and, for the four CRUD scenarios the change carries as MODIFIED, the main
 * spec). The log-line behaviour of that change has no anchor here: a browser
 * cannot read the Nextcloud log, so MigrationPacksControllerTest proves it.
 *
 * WHO THE BROWSER IS. The config authenticates every request as the admin
 * with a Basic header. The non-admin blocks override that header with the
 * seeded `e2e-other`; the CSRF block drops it and uses the cookie session
 * global-setup.ts stored, because a Basic header with `OCS-APIRequest` passes
 * Nextcloud's CSRF check and would prove nothing. The identity is asserted on
 * every page, not assumed: a lost override would turn a non-admin test into
 * an admin test that still passes.
 *
 * HERMETIC. Each test creates the packs it needs through the admin API, and
 * `afterAll` deletes them and fails when a delete fails. The one exception is
 * the last test, which deletes OpenRegister's own built-in `zgw-zaken-json`
 * pack to prove that its slug lock survives; it imports the same definition
 * back before it ends, and restores it in a `finally` when an assertion
 * failed half-way. Run it with `--grep-invert "deleted built-in"` against an
 * instance whose zgw-zaken-json row id something else relies on.
 *
 * RUN against any instance with this branch built and the seed applied
 * (tests/e2e/ci/seed.sh creates `e2e-other`):
 *
 *   PLAYWRIGHT_BASE_URL=http://localhost:<port> npx playwright test \
 *     -c tests/e2e/ci/playwright.config.ts tests/e2e/ci/migration-pack-pages.spec.ts
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** Seeded non-admin account, see tests/e2e/ci/seed.sh. */
const OTHER = 'e2e-other'
const OTHER_PASS = 'E2e-Share-Pass-123'

/** The admin cookie session global-setup.ts stores. */
const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

/** The stackiq TOPdesk packs the editor's Jest round-trip tests also load. */
const STACKIQ_FIXTURES = path.resolve(
	__dirname,
	'../../fixtures/migration-packs/stackiq',
)

/** OpenRegister's own built-in pack, seeded on install. */
const BUILTIN_SLUG = 'zgw-zaken-json'

const API = '/index.php/apps/openregister/api/migration-packs'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

/** A JSON object, as the API stores a pack definition. */
type Definition = Record<string, any>

/** A stored pack as the API answers it. */
interface StoredPack {
	id: number
	packSlug: string
	name: string
	version: string
	builtin: boolean
	updatedAt: string
	definition: Definition
}

/** The rows the editor must show, in this order. */
const MAPPINGS = [
	{ source: 'Naam', target: 'name', required: true, transform: { type: 'trim' } },
	{ source: 'Omschrijving', target: 'description' },
	{
		source: 'Status',
		target: 'status',
		transform: {
			type: 'lookup',
			map: { Actief: 'Active', Inactief: 'Inactive' },
		},
	},
]

let counter = 0

/**
 * A small pack nobody installed, with a unique slug and name.
 *
 * @param label What the test uses it for, shown in its name.
 * @return The definition.
 */
function plainPack(label: string): Definition {
	counter++
	return {
		id: `e2e-migration-pack-${RUN}-${counter}`,
		name: `e2e ${label} ${RUN}-${counter}`,
		description: 'Created by tests/e2e/ci/migration-pack-pages.spec.ts.',
		sourceFormat: 'excel',
		version: '1.0.0',
		fieldMappings: MAPPINGS,
		defaults: { type: 'Application' },
		idStrategy: { type: 'generate' },
	}
}

/**
 * A copy of one of the TOPdesk packs stackiq#1288 ships (1.0.x, taken at stackiq commit e2081bb), under its own slug and name.
 *
 * Only `id` and `name` change, in place, so the key order is the copied one.
 *
 * @param file The fixture file name, e.g. `topdesk-module.json`.
 * @return The definition.
 */
function stackiqCopy(file: string): Definition {
	counter++
	const shipped = JSON.parse(
		fs.readFileSync(path.join(STACKIQ_FIXTURES, file), 'utf8'),
	) as Definition
	return {
		...shipped,
		id: `${shipped.id}-e2e-${RUN}-${counter}`,
		name: `${shipped.name} (e2e ${RUN}-${counter})`,
	}
}

/**
 * The patch version after this one: `1.0.0` becomes `1.0.1`.
 *
 * @param version A semver string.
 * @return The next patch version.
 */
function nextPatch(version: string): string {
	const [major, minor, patch] = version.split('.').map(Number)
	return `${major}.${minor}.${patch + 1}`
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

/** A pack definition as an uploaded JSON file. */
function jsonFile(definition: Definition) {
	return {
		name: `${definition.id}.json`,
		mimeType: 'application/json',
		buffer: Buffer.from(JSON.stringify(definition, null, 2)),
	}
}

let admin: APIRequestContext
let other: APIRequestContext

/** Row ids this file created, deleted again in `afterAll`. */
const created = new Set<number>()

/**
 * Create a pack through the admin API and remember it for clean-up.
 *
 * @param definition The definition.
 * @return The stored pack.
 */
async function createPack(definition: Definition): Promise<StoredPack> {
	const response = await admin.post(API, { data: { definition } })
	expect(response.status(), `pack create failed: ${await response.text()}`).toBe(
		201,
	)
	const pack = (await response.json()) as StoredPack
	expect(String(pack.id), 'no row id came back from the pack create').toMatch(
		/^\d+$/,
	)
	created.add(pack.id)
	return pack
}

/** Read one pack as the admin. */
async function readPack(id: number): Promise<StoredPack> {
	const response = await admin.get(`${API}/${id}`)
	expect(response.status(), `pack ${id} could not be read`).toBe(200)
	return (await response.json()) as StoredPack
}

/** Every stored pack, as the admin. */
async function listPacks(): Promise<StoredPack[]> {
	const response = await admin.get(API)
	expect(response.status()).toBe(200)
	return ((await response.json()).results ?? []) as StoredPack[]
}

/** The stored pack with this slug, or undefined. */
async function packBySlug(slug: string): Promise<StoredPack | undefined> {
	return (await listPacks()).find((pack) => pack.packSlug === slug)
}

/** Assert an error answer of the shared contract (ADR-050). */
async function expectError(
	response: APIResponse,
	status: number,
	error: string,
): Promise<any> {
	const text = await response.text()
	expect(response.status(), `expected HTTP ${status} ${error}, got: ${text}`).toBe(
		status,
	)
	const body = JSON.parse(text)
	expect(body.error).toBe(error)
	expect(typeof body.message, 'the answer carries no message').toBe('string')
	expect(body.rows, 'a refused request must map nothing').toBeUndefined()
	return body
}

/**
 * Close any modal covering the page, the way a user does.
 *
 * On the first app load of a fresh instance nc-vue's support dialog opens
 * over everything and its mask swallows clicks. Fails loudly when a mask
 * survives three Escapes, instead of clicking into it.
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
		'a modal is still covering the page after three Escapes',
	).toHaveCount(0)
}

/** Assert who the browser is, so a lost header override cannot go unnoticed. */
async function expectSignedInAs(page: Page, uid: string): Promise<void> {
	const current = await page.evaluate(
		() => (window as any).OC?.getCurrentUser?.()?.uid ?? null,
	)
	expect(current, `the browser is not signed in as ${uid}`).toBe(uid)
}

/** Open the list page and wait for it. */
async function openList(page: Page, uid: string): Promise<void> {
	await page.goto('/index.php/apps/openregister/migration-packs', {
		waitUntil: 'domcontentloaded',
	})
	await expect(
		page.getByRole('heading', {
			level: 2,
			name: 'Migration packs',
			exact: true,
		}),
		'MigrationPacksIndex did not render its heading',
	).toBeVisible({ timeout: 30_000 })
	await expectSignedInAs(page, uid)
	await dismissBlockingModals(page)
}

/** Open the editor on one pack and wait for it. */
async function openEditor(page: Page, pack: StoredPack, uid: string): Promise<void> {
	await page.goto(`/index.php/apps/openregister/migration-packs/${pack.id}`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(
		page.getByRole('heading', { level: 2, name: pack.name, exact: true }),
		'MigrationPackDetail did not open the pack',
	).toBeVisible({ timeout: 30_000 })
	await expectSignedInAs(page, uid)
	await dismissBlockingModals(page)
}

/** The rows of the editor's field-mapping table. */
function mappingRows(page: Page) {
	return page.locator(
		'section[aria-labelledby="migration-pack-mappings"] tbody tr',
	)
}

/** The editor's Save button. */
function saveButton(page: Page) {
	return page.getByRole('button', { name: 'Save', exact: true })
}

/** Record every write the page sends to one pack's update route. */
function recordUpdates(page: Page, id: number): Request[] {
	const seen: Request[] = []
	page.on('request', (sent) => {
		if (
			sent.method() === 'PUT'
			&& sent.url().endsWith(`/api/migration-packs/${id}`)
		) {
			seen.push(sent)
		}
	})
	return seen
}

/**
 * Save in the editor and wait for the server's answer.
 *
 * @return The update request the editor sent.
 */
async function saveInEditor(
	page: Page,
	pack: StoredPack,
	version: string,
): Promise<Request> {
	const answer = page.waitForResponse(
		(response) =>
			response.request().method() === 'PUT'
			&& response.url().endsWith(`/api/migration-packs/${pack.id}`),
	)
	await saveButton(page).click()
	const response = await answer
	expect(response.status(), `the save was refused: ${await response.text()}`).toBe(
		200,
	)
	await expect(
		page.getByText(`Saved as version ${version}.`, { exact: true }),
	).toBeVisible()
	return response.request()
}

/** The sample-row test panel of the editor. */
function previewPanel(page: Page) {
	return page.locator('section').filter({
		has: page.getByRole('heading', {
			name: 'Test with a sample row',
			exact: true,
		}),
	})
}

test.beforeAll(async () => {
	admin = await contextFor(ADMIN, ADMIN_PASS)
	other = await contextFor(OTHER, OTHER_PASS)

	const probe = await other.get(API)
	expect(
		probe.status(),
		`seeded account '${OTHER}' cannot read the packs (${probe.status()}), did playwright-seed-command run?`,
	).toBe(200)

	// The pages are found by their English names. An account set to another
	// language fails every browser test with "did not render its heading",
	// which reads like a broken page; say what it is instead. The probe sends
	// the browser's language, so an account without a language of its own
	// resolves the way the page does.
	for (const [uid, context] of [
		[ADMIN, admin],
		[OTHER, other],
	] as const) {
		const answer = await context.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'Accept-Language': 'en-US,en;q=0.9' },
		})
		expect(answer.status(), `the account '${uid}' could not be read`).toBe(200)
		const language = (await answer.json()).ocs?.data?.language ?? ''
		expect(
			['', 'en'].includes(language) || language.startsWith('en_'),
			`the account '${uid}' uses language '${language}'; these tests read the English interface.`
				+ ` Set it to English, for example with \`occ user:setting ${uid} core lang en\`, and run again`,
		).toBe(true)
	}
})

test.afterAll(async () => {
	// A clean-up that stopped working must show, not leave packs behind: an
	// APIRequestContext does not throw on a 4xx, so the status is asserted.
	const failed: string[] = []
	for (const id of created) {
		const response = await admin.delete(`${API}/${id}`)
		if (!response.ok() && response.status() !== 404) {
			failed.push(`${id}: HTTP ${response.status()} ${await response.text()}`)
		}
	}
	await other?.dispose()
	await admin?.dispose()
	expect(failed, 'packs this file created could not be deleted').toEqual([])
})

test.describe('migration packs as an administrator', () => {
	test('the list renders with the pack in it, and the pack opens in the editor', async ({
		page,
	}) => {
		const pack = await createPack(plainPack('list'))
		await openList(page, ADMIN)

		const table = page.locator('table.migration-packs__table')
		await expect(
			table.getByRole('columnheader', { name: 'Slug', exact: true }),
		).toBeVisible()
		const link = table.getByRole('link', { name: pack.name, exact: true })
		await expect(link, 'the created pack is not in the list').toBeVisible()
		await expect(table).toContainText(pack.packSlug)
		await expect(
			page.getByRole('button', { name: 'Import JSON', exact: true }),
		).toBeVisible()

		await link.click()

		await expect(page).toHaveURL(
			new RegExp(`/apps/openregister/migration-packs/${pack.id}$`),
		)
		await expect(
			page.getByRole('heading', { level: 2, name: pack.name, exact: true }),
			'MigrationPackDetail did not open the clicked pack',
		).toBeVisible({ timeout: 30_000 })
	})

	test('the field-mapping table shows every row of the pack, in order', async ({
		page,
	}) => {
		const pack = await createPack(plainPack('table'))
		await openEditor(page, pack, ADMIN)

		await expect(mappingRows(page)).toHaveCount(MAPPINGS.length)
		for (const [index, mapping] of MAPPINGS.entries()) {
			const number = index + 1
			await expect(
				page.getByRole('textbox', {
					name: `Source column of row ${number}`,
				}),
			).toHaveValue(mapping.source)
			await expect(
				page.getByRole('textbox', {
					name: `Target field of row ${number}`,
					exact: true,
				}),
			).toHaveValue(mapping.target)
		}
		await expect(
			page.getByRole('checkbox', { name: 'Row 1 is required' }),
		).toBeChecked()
		await expect(
			page.getByRole('textbox', { name: 'Source column of row 1' }),
		).toBeEnabled()
		await expect(saveButton(page)).toBeVisible()
	})

	// @e2e migration-mapping-packs::an-administrator-changes-a-lookup-value-and-saves
	test('an administrator changes a lookup value and saves', async ({ page }) => {
		const pack = await createPack(stackiqCopy('topdesk-usage.json'))
		const before = (await readPack(pack.id)).definition
		expect(before.version).toBe('1.0.0')
		await openEditor(page, pack, ADMIN)

		await page.getByRole('button', { name: 'Transform of row 1' }).click()
		const dialog = page.getByRole('dialog', { name: 'Transform for row 1' })
		await expect(dialog).toBeVisible()
		const sources = dialog.getByRole('textbox', {
			name: /^Value in the source of row \d+$/,
		})
		const count = await sources.count()
		let number = 0
		for (let index = 0; index < count; index++) {
			if ((await sources.nth(index).inputValue()) === 'In voorraad') {
				number = index + 1
			}
		}
		expect(
			number,
			'the Applicatie Status lookup has no row for In voorraad',
		).toBeGreaterThan(0)
		await dialog
			.getByRole('textbox', {
				name: `Stored value of row ${number}`,
				exact: true,
			})
			.fill('Acquisition')
		await dialog.getByRole('button', { name: 'Apply', exact: true }).click()
		await expect(dialog).toBeHidden()

		await expect(
			page.getByText('Saving raises the version to 1.0.1.', { exact: true }),
			'the editor must say the version goes up before it saves',
		).toBeVisible()
		const sent = await saveInEditor(page, pack, '1.0.1')
		expect(sent.postDataJSON().definition.version).toBe('1.0.1')

		const expected = structuredClone(before)
		expected.fieldMappings[0].transform.map['In voorraad'] = 'Acquisition'
		expected.version = '1.0.1'
		expect((await readPack(pack.id)).definition).toEqual(expected)
	})

	// @e2e migration-mapping-packs::a-row-is-moved-and-saved-in-the-new-order
	test('a row is moved and saved in the new order', async ({ page }) => {
		const pack = await createPack(stackiqCopy('topdesk-module.json'))
		await openEditor(page, pack, ADMIN)

		await expect(
			page.getByRole('textbox', { name: 'Source column of row 4' }),
		).toHaveValue('Nickname')
		await expect(
			page.getByRole('textbox', { name: 'Source column of row 5' }),
		).toHaveValue('Roepnaam')
		for (const row of [3, 4]) {
			await expect(
				mappingRows(page).nth(row),
				'rows writing the same field must say so',
			).toContainText('Rows 4, 5 write this field')
		}

		await page.getByRole('button', { name: 'Move row 5 up' }).click()
		await expect(
			page.getByRole('textbox', { name: 'Source column of row 4' }),
		).toHaveValue('Roepnaam')
		await saveInEditor(page, pack, '1.0.1')

		const stored = (await readPack(pack.id)).definition.fieldMappings
		expect(stored[3].source).toBe('Roepnaam')
		expect(stored[4].source).toBe('Nickname')
		expect(stored[3].target).toBe('shortDescription')
		expect(stored[4].target).toBe('shortDescription')
	})

	// @e2e migration-mapping-packs::a-validation-refusal-is-shown-per-finding
	test('a validation refusal is shown per finding', async ({ page }) => {
		const pack = await createPack(plainPack('refusal'))
		await openEditor(page, pack, ADMIN)

		const findings = ['"name" is required', '"version" must be a semver string']
		await page.route(`**/api/migration-packs/${pack.id}`, async (route) => {
			if (route.request().method() !== 'PUT') {
				return route.continue()
			}
			return route.fulfill({
				status: 422,
				contentType: 'application/json',
				body: JSON.stringify({
					message: 'The pack definition is invalid',
					error: 'invalid-pack',
					details: { errors: findings },
				}),
			})
		})

		const description = page.getByRole('textbox', {
			name: 'Description',
			exact: true,
		})
		await description.fill('An unsaved description')
		await saveButton(page).click()

		for (const finding of findings) {
			await expect(
				page.getByRole('listitem').filter({ hasText: finding }),
				`the finding ${finding} is not on its own line`,
			).toHaveCount(1)
		}
		await expect(description, 'the unsaved change was dropped').toHaveValue(
			'An unsaved description',
		)
		await expect(
			saveButton(page),
			'the editor no longer holds the change',
		).toBeEnabled()
		expect((await readPack(pack.id)).definition.description).toBe(
			pack.definition.description,
		)
	})

	// @e2e migration-mapping-packs::a-save-over-someone-elses-save-is-refused
	test("a save over someone else's save is refused", async ({ page }) => {
		const pack = await createPack(plainPack('conflict'))
		await openEditor(page, pack, ADMIN)
		const updates = recordUpdates(page, pack.id)

		// updatedAt has a resolution of one second; let it move.
		await page.waitForTimeout(1_100)
		const theirs = await admin.put(`${API}/${pack.id}`, {
			data: {
				definition: {
					...pack.definition,
					description: 'Saved by someone else',
				},
			},
		})
		expect(theirs.status(), await theirs.text()).toBe(200)

		await page
			.getByRole('textbox', { name: 'Description', exact: true })
			.fill('Mine')
		await saveButton(page).click()

		await expect(
			page.getByText(/Someone else saved this pack after you opened it/),
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: 'Reload the pack', exact: true }),
		).toBeVisible()
		expect(updates, 'the editor sent its save anyway').toHaveLength(0)
		expect((await readPack(pack.id)).definition.description).toBe(
			'Saved by someone else',
		)
	})

	// @e2e migration-mapping-packs::deleting-a-built-in-pack-warns-that-it-comes-back
	test('deleting a built-in pack warns that it comes back', async ({ page }) => {
		const builtin = await packBySlug(BUILTIN_SLUG)
		expect(
			builtin,
			`the built-in pack ${BUILTIN_SLUG} is not installed`,
		).toBeDefined()
		expect(builtin!.builtin).toBe(true)
		await openList(page, ADMIN)

		await page
			.getByRole('button', { name: `Delete ${builtin!.name}`, exact: true })
			.click()
		const dialog = page.getByRole('dialog', { name: 'Delete migration pack' })
		await expect(dialog).toBeVisible()
		await expect(dialog).toContainText(builtin!.name)
		await expect(dialog).toContainText('installs it again, unedited')

		await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
		await expect(dialog).toBeHidden()
		expect((await readPack(builtin!.id)).packSlug).toBe(BUILTIN_SLUG)
	})

	// @e2e migration-mapping-packs::an-administrator-deletes-a-pack-after-confirming
	test('an administrator deletes a pack after confirming', async ({ page }) => {
		const pack = await createPack(plainPack('delete'))
		await openList(page, ADMIN)

		await page
			.getByRole('button', { name: `Delete ${pack.name}`, exact: true })
			.click()
		const dialog = page.getByRole('dialog', { name: 'Delete migration pack' })
		await expect(dialog).toContainText(pack.name)
		await expect(
			dialog,
			'a pack no app installed does not come back',
		).not.toContainText('installs it again')
		await dialog.getByRole('button', { name: 'Delete', exact: true }).click()

		await expect(
			page.getByRole('link', { name: pack.name, exact: true }),
		).toHaveCount(0)
		expect((await admin.get(`${API}/${pack.id}`)).status()).toBe(404)
	})

	// @e2e migration-mapping-packs::an-administrator-imports-a-pack-from-a-json-file
	test('an administrator imports a pack from a JSON file', async ({ page }) => {
		const definition = plainPack('import')
		await openList(page, ADMIN)

		await page
			.locator('.migration-packs__toolbar input[type="file"]')
			.setInputFiles(jsonFile(definition))

		await expect(
			page.getByText(`Imported "${definition.name}".`, { exact: true }),
		).toBeVisible()
		await expect(
			page.getByRole('link', { name: definition.name, exact: true }),
		).toBeVisible()
		const stored = await packBySlug(definition.id)
		expect(stored, 'the imported pack is not stored').toBeDefined()
		created.add(stored!.id)
		expect(stored!.definition).toEqual(definition)
	})
})

test.describe('the editor saves what it does not show', () => {
	// @e2e migration-mapping-packs::the-shipped-topdesk-packs-survive-an-unedited-save
	test('the shipped TOPdesk packs survive an unedited save', async ({ page }) => {
		test.setTimeout(180_000)
		const files = fs
			.readdirSync(STACKIQ_FIXTURES)
			.filter((file) => file.endsWith('.json'))
			.sort()
		expect(
			files,
			'the fixtures hold five TOPdesk packs (copies of stackiq#1288)',
		).toHaveLength(5)

		for (const file of files) {
			const pack = await createPack(stackiqCopy(file))
			const before = (await readPack(pack.id)).definition
			await openEditor(page, pack, ADMIN)

			await expect(
				saveButton(page),
				`${file}: an unedited pack shows unsaved changes`,
			).toBeDisabled()
			await expect(
				page.getByRole('button', { name: 'Undo changes', exact: true }),
			).toBeDisabled()

			const name = `${pack.name} renamed`
			await page.getByRole('textbox', { name: 'Name', exact: true }).fill(name)
			const version = nextPatch(before.version)
			await saveInEditor(page, pack, version)

			// Key for key and in the same order: compare the serialised text.
			const after = (await readPack(pack.id)).definition
			expect(
				JSON.stringify(after),
				`${file} changed beyond its name and version`,
			).toBe(JSON.stringify({ ...before, name, version }))
		}
	})

	// @e2e migration-mapping-packs::an-empty-default-stays-null
	// @e2e migration-mapping-packs::one-column-feeding-two-targets-keeps-both-rows
	test('the module pack keeps its null default and both Applicatiesoort rows', async ({
		page,
	}) => {
		const pack = await createPack(stackiqCopy('topdesk-module.json'))
		await openEditor(page, pack, ADMIN)
		await page
			.getByRole('textbox', { name: 'Description', exact: true })
			.fill('Saved from the editor')
		await saveInEditor(page, pack, '1.0.1')

		const rows = (await readPack(pack.id)).definition
			.fieldMappings as Definition[]
		const fromSoort = rows
			.map((row, index) => ({ ...row, index }))
			.filter((row) => row.source === 'Applicatiesoort')
		expect(fromSoort.map((row) => row.target)).toEqual([
			'applicationType',
			'cloudDienstverleningsmodel',
		])
		const lookup = fromSoort[1].transform
		expect(Object.hasOwn(lookup, 'default'), 'the default key was dropped').toBe(
			true,
		)
		expect(lookup.default).toBeNull()
	})

	// @e2e migration-mapping-packs::a-key-the-editor-does-not-know-is-kept
	test('a key the editor does not know is kept', async ({ page }) => {
		const definition = plainPack('unknown keys')
		definition['x-stackiq'] = { shippedVersion: '1.0.0', shippedHash: 'abc' }
		definition.fieldMappings = [
			{
				source: 'Naam',
				target: 'name',
				transform: { type: 'trim', futureOption: true },
			},
		]
		const pack = await createPack(definition)
		await openEditor(page, pack, ADMIN)

		await page.getByRole('button', { name: 'Transform of row 1' }).click()
		const dialog = page.getByRole('dialog', { name: 'Transform for row 1' })
		await expect(dialog).toContainText('(futureOption)')
		await expect(dialog).toContainText('They are kept when you save.')
		await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
		await expect(dialog).toBeHidden()

		await page
			.getByRole('textbox', { name: 'Name', exact: true })
			.fill(`${pack.name} renamed`)
		await saveInEditor(page, pack, '1.0.1')

		const stored = (await readPack(pack.id)).definition
		expect(stored['x-stackiq']).toEqual({
			shippedVersion: '1.0.0',
			shippedHash: 'abc',
		})
		expect(stored.fieldMappings[0].transform).toEqual({
			type: 'trim',
			futureOption: true,
		})
	})
})

test.describe('the sample-row test', () => {
	// @e2e migration-mapping-packs::a-sample-row-shows-what-the-import-would-write
	test('a sample row shows what the import would write, from the unsaved pack', async ({
		page,
	}) => {
		const pack = await createPack(stackiqCopy('topdesk-module.json'))
		await openEditor(page, pack, ADMIN)
		await page
			.getByRole('textbox', { name: 'Target field of row 1', exact: true })
			.fill('title')

		const panel = previewPanel(page)
		await panel
			.getByRole('textbox', { name: 'Sample value for Applicatie Naam' })
			.fill(' Zaaksysteem ')
		await panel
			.getByRole('textbox', { name: 'Sample value for APPID' })
			.fill('A-1')
		await panel
			.getByRole('textbox', { name: 'Sample value for Applicatiesoort' })
			.fill('Cloud')
		await panel.getByRole('button', { name: 'Test', exact: true }).click()

		const field = (name: string) =>
			panel
				.getByRole('row')
				.filter({ has: page.getByRole('cell', { name, exact: true }) })
		await expect(field('title')).toContainText('"Zaaksysteem"')
		await expect(field('cloudDienstverleningsmodel')).toContainText('null')
		await expect(
			field('name'),
			'the preview ran on the stored pack, not the edited one',
		).toHaveCount(0)
		expect(
			(await readPack(pack.id)).definition.fieldMappings[0].target,
			'the test saved the pack',
		).toBe('name')
	})

	// @e2e migration-mapping-packs::a-sample-row-with-a-missing-required-column-shows-the-error
	test('a sample row with a missing required column shows the error', async ({
		page,
	}) => {
		const pack = await createPack(stackiqCopy('topdesk-module.json'))
		await openEditor(page, pack, ADMIN)

		const panel = previewPanel(page)
		await panel
			.getByRole('textbox', { name: 'Sample value for Applicatie Naam' })
			.fill('Zaaksysteem')
		await panel.getByRole('button', { name: 'Test', exact: true }).click()

		await expect(
			panel.getByRole('heading', {
				name: 'Problems in this row',
				exact: true,
			}),
		).toBeVisible()
		await expect(
			panel
				.getByRole('row')
				.filter({
					has: page.getByRole('cell', { name: 'APPID', exact: true }),
				})
				.filter({
					has: page.getByRole('cell', {
						name: 'externalNumber',
						exact: true,
					}),
				}),
		).toHaveCount(1)
	})

	// @e2e migration-mapping-packs::an-invalid-definition-is-refused-with-its-findings
	test('a preview of an invalid definition is refused with its findings', async () => {
		const definition = plainPack('preview invalid')
		delete definition.name
		const body = await expectError(
			await admin.post(`${API}/preview`, {
				data: { definition, rows: [{ Naam: 'x' }] },
			}),
			422,
			'invalid-pack',
		)
		expect(
			body.details.errors.some((finding: string) => finding.includes('name')),
		).toBe(true)
	})

	// @e2e migration-mapping-packs::too-many-sample-rows-are-refused
	test('too many sample rows are refused', async () => {
		const rows = Array.from({ length: 21 }, (_, index) => ({
			Naam: `row ${index + 1}`,
		}))
		await expectError(
			await admin.post(`${API}/preview`, {
				data: { definition: plainPack('preview rows'), rows },
			}),
			400,
			'too-many-rows',
		)
		const twenty = await admin.post(`${API}/preview`, {
			data: { definition: plainPack('preview rows'), rows: rows.slice(0, 20) },
		})
		expect(twenty.status(), 'twenty rows is the limit, not over it').toBe(200)
		expect((await twenty.json()).rows).toHaveLength(20)
	})

	// @e2e migration-mapping-packs::a-malformed-preview-request-is-refused
	test('a malformed preview request is refused', async () => {
		const definition = plainPack('preview malformed')
		for (const data of [
			{ rows: [{ Naam: 'x' }] },
			{ definition: 'not an object', rows: [] },
			{ definition, rows: 'not a list' },
			{ definition, rows: [1, 2] },
		]) {
			await expectError(
				await admin.post(`${API}/preview`, { data }),
				400,
				'invalid-request',
			)
		}
	})

	// @e2e migration-mapping-packs::an-oversized-preview-request-is-refused
	test('an oversized preview request is refused', async () => {
		// Twenty rows of 60 KiB each: within the row limit, over 1 MiB in all.
		const wide = 'x'.repeat(60 * 1024)
		const rows = Array.from({ length: 20 }, () => ({ Naam: wide }))
		await expectError(
			await admin.post(`${API}/preview`, {
				data: { definition: plainPack('oversized'), rows },
			}),
			400,
			'invalid-request',
		)
	})

	// @e2e migration-mapping-packs::a-non-admin-cannot-run-a-preview
	test('a non-admin cannot run a preview', async () => {
		const response = await other.post(`${API}/preview`, {
			data: {
				definition: plainPack('preview non-admin'),
				rows: [{ Naam: 'x' }],
			},
		})
		expect(response.status()).toBe(403)
		expect((await response.json()).rows).toBeUndefined()
	})
})

test.describe('the API contract', () => {
	// @e2e migration-mapping-packs::an-envelope-update-keeps-the-route-id-and-the-slug
	test('an envelope update keeps the route id and the slug', async () => {
		const pack = await createPack(plainPack('envelope'))
		const response = await admin.put(`${API}/${pack.id}`, {
			data: {
				definition: {
					...pack.definition,
					description: 'Updated through the envelope',
				},
			},
		})
		expect(response.status(), await response.text()).toBe(200)
		const stored = await readPack(pack.id)
		expect(stored.id).toBe(pack.id)
		expect(stored.packSlug).toBe(pack.packSlug)
		expect(stored.definition.description).toBe('Updated through the envelope')
	})

	// @e2e migration-mapping-packs::a-raw-body-still-creates-a-pack
	test('a raw body still creates a pack', async () => {
		const definition = plainPack('raw body')
		const response = await admin.post(API, { data: definition })
		expect(response.status(), await response.text()).toBe(201)
		const pack = (await response.json()) as StoredPack
		created.add(pack.id)
		expect(pack.packSlug).toBe(definition.id)
		expect(pack.definition).toEqual(definition)
	})

	// @e2e migration-mapping-packs::a-definition-the-validator-refuses-is-answered-with-its-findings
	test('a definition the validator refuses is answered with its findings', async () => {
		const definition = plainPack('invalid create')
		delete definition.name
		const body = await expectError(
			await admin.post(API, { data: { definition } }),
			422,
			'invalid-pack',
		)
		expect(
			body.details.errors.some((finding: string) => finding.includes('name')),
		).toBe(true)
		expect(
			await packBySlug(definition.id),
			'a refused create stored a pack',
		).toBeUndefined()
	})

	// @e2e migration-mapping-packs::an-update-of-a-pack-that-does-not-exist-is-answered-404
	test('an update of a pack that does not exist is answered 404', async () => {
		await expectError(
			await admin.put(`${API}/999999999`, {
				data: { definition: plainPack('missing') },
			}),
			404,
			'pack-not-found',
		)
	})

	// @e2e migration-mapping-packs::an-update-based-on-an-older-read-is-refused
	test('an update based on an older read is refused', async () => {
		const pack = await createPack(plainPack('older read'))
		await expectError(
			await admin.put(`${API}/${pack.id}`, {
				data: {
					definition: {
						...pack.definition,
						description: 'Based on an older read',
					},
					expectedUpdatedAt: '2000-01-01T00:00:00+00:00',
				},
			}),
			409,
			'pack-changed',
		)
		const unchanged = await readPack(pack.id)
		expect(unchanged.definition).toEqual(pack.definition)
		expect(unchanged.updatedAt).toBe(pack.updatedAt)

		const current = await admin.put(`${API}/${pack.id}`, {
			data: {
				definition: {
					...pack.definition,
					description: 'Based on the current read',
				},
				expectedUpdatedAt: pack.updatedAt,
			},
		})
		expect(current.status(), await current.text()).toBe(200)
		expect((await readPack(pack.id)).definition.description).toBe(
			'Based on the current read',
		)
	})

	// @e2e migration-mapping-packs::renaming-a-built-in-pack-through-the-api-is-refused
	test('renaming a built-in pack through the API is refused', async () => {
		const builtin = await packBySlug(BUILTIN_SLUG)
		expect(
			builtin,
			`the built-in pack ${BUILTIN_SLUG} is not installed`,
		).toBeDefined()
		await expectError(
			await admin.put(`${API}/${builtin!.id}`, {
				data: { definition: { ...builtin!.definition, id: 'my-zgw' } },
			}),
			422,
			'builtin-slug-locked',
		)
		const stored = await readPack(builtin!.id)
		expect(stored.packSlug).toBe(BUILTIN_SLUG)
		expect(stored.definition).toEqual(builtin!.definition)
	})

	// @e2e migration-mapping-packs::pack-json-exportimport-round-trips-between-instances
	test('a pack exported and imported again updates its row', async () => {
		const pack = await createPack(plainPack('round trip'))
		const exported = await admin.get(`${API}/${pack.id}/export`)
		expect(exported.status()).toBe(200)
		const document = JSON.parse(await exported.text()) as Definition
		expect(document.id).toBe(pack.packSlug)

		const response = await admin.post(`${API}/import`, {
			multipart: {
				file: jsonFile({ ...document, description: 'Imported again' }),
			},
		})
		expect(response.status(), await response.text()).toBe(201)
		expect((await response.json()).id, 'the import created a second row').toBe(
			pack.id,
		)
		expect((await readPack(pack.id)).definition.description).toBe(
			'Imported again',
		)
		expect(
			(await listPacks()).filter(
				(stored) => stored.packSlug === pack.packSlug,
			),
		).toHaveLength(1)
	})

	// @e2e migration-mapping-packs::anonymous-callers-are-rejected-everywhere
	test('anonymous callers are rejected everywhere', async () => {
		const pack = await createPack(plainPack('anonymous'))
		const anonymous = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: { Accept: 'application/json' },
		})
		try {
			const definition = plainPack('anonymous write')
			const calls: Array<[string, Promise<APIResponse>]> = [
				['index', anonymous.get(API)],
				['show', anonymous.get(`${API}/${pack.id}`)],
				['export', anonymous.get(`${API}/${pack.id}/export`)],
				['create', anonymous.post(API, { data: { definition } })],
				[
					'update',
					anonymous.put(`${API}/${pack.id}`, { data: { definition } }),
				],
				['destroy', anonymous.delete(`${API}/${pack.id}`)],
				[
					'import',
					anonymous.post(`${API}/import`, {
						multipart: { file: jsonFile(definition) },
					}),
				],
				[
					'preview',
					anonymous.post(`${API}/preview`, {
						data: { definition, rows: [] },
					}),
				],
			]
			for (const [route, call] of calls) {
				expect(
					(await call).status(),
					`${route} answered an anonymous caller`,
				).toBe(401)
			}
		} finally {
			await anonymous.dispose()
		}
		expect((await readPack(pack.id)).definition).toEqual(pack.definition)
	})
})

test.describe('migration packs as an ordinary user', () => {
	/*
	 * Overrides the config's admin header for every request this page makes,
	 * page loads included, the same way object-shares-tab.spec.ts does.
	 */
	test.use({
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${OTHER}:${OTHER_PASS}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
		},
	})

	// @e2e migration-mapping-packs::a-non-admin-who-opens-the-editor-by-url-can-only-read
	test('the editor opened by URL reads the pack and offers no change', async ({
		page,
	}) => {
		const pack = await createPack(plainPack('read only'))
		await openEditor(page, pack, OTHER)

		await expect(
			page.getByText(
				'Only administrators can change migration packs. You can read this one.',
			),
		).toBeVisible()
		await expect(mappingRows(page)).toHaveCount(MAPPINGS.length)
		await expect(
			page.getByRole('textbox', { name: 'Source column of row 1' }),
		).toHaveValue(MAPPINGS[0].source)
		await expect(
			page
				.locator('.migration-pack')
				.locator('input:enabled, select:enabled, textarea:enabled'),
			'a field a non-admin can change',
		).toHaveCount(0)
		for (const name of [
			'Save',
			'Delete',
			'Import JSON',
			'Add field mapping',
			'Test',
		]) {
			await expect(
				page.getByRole('button', { name, exact: true }),
				`a non-admin must not be offered ${name}`,
			).toHaveCount(0)
		}
		await expect(
			page.getByRole('heading', {
				name: 'Test with a sample row',
				exact: true,
			}),
			'a non-admin must not get the sample-row test',
		).toHaveCount(0)

		// The transform dialog opens to read, without Apply.
		await page.getByRole('button', { name: 'Transform of row 3' }).click()
		const dialog = page.getByRole('dialog', { name: 'Transform for row 3' })
		await expect(dialog).toBeVisible()
		await expect(
			dialog.getByRole('button', { name: 'Apply', exact: true }),
		).toHaveCount(0)
	})

	// @e2e migration-mapping-packs::a-non-admin-who-opens-the-list-can-only-read
	test('the list reads the packs and offers no import or delete', async ({
		page,
	}) => {
		const pack = await createPack(plainPack('list read only'))
		await openList(page, OTHER)

		await expect(
			page.getByText('Only administrators can change migration packs.', {
				exact: true,
			}),
		).toBeVisible()
		await expect(
			page.getByRole('link', { name: pack.name, exact: true }),
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: 'Import JSON', exact: true }),
		).toHaveCount(0)
		await expect(page.getByRole('button', { name: /^Delete / })).toHaveCount(0)
		await expect(page.locator('.migration-packs__toolbar')).toHaveCount(0)
	})

	// @e2e migration-mapping-packs::a-non-admin-does-not-see-the-menu-entry
	test('the navigation has no Migration packs entry for a non-admin', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/openregister/', {
			waitUntil: 'domcontentloaded',
		})
		const nav = page.locator('.app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 30_000 })
		await expectSignedInAs(page, OTHER)
		await dismissBlockingModals(page)

		// Open the group the entry sits in, and prove it is open by a sibling.
		const sibling = nav.getByRole('link', { name: 'Data sources', exact: true })
		if (!(await sibling.isVisible())) {
			await nav.getByText('Integration', { exact: true }).first().click()
		}
		await expect(sibling, 'the Integration group did not open').toBeVisible()
		await expect(
			nav.getByRole('link', { name: 'Migration packs', exact: true }),
		).toHaveCount(0)
	})

	// @e2e migration-mapping-packs::a-non-admins-write-is-refused-by-the-server
	test("a non-admin's write is refused by the server", async () => {
		const pack = await createPack(plainPack('non-admin put'))
		const response = await other.put(`${API}/${pack.id}`, {
			data: {
				definition: {
					...pack.definition,
					description: 'Changed by a non-admin',
				},
			},
		})
		expect(response.status()).toBe(403)
		expect((await readPack(pack.id)).definition).toEqual(pack.definition)
	})

	// @e2e migration-mapping-packs::non-admin-cannot-mutate-packs
	test('a non-admin cannot create, change, delete, import or preview a pack', async () => {
		const pack = await createPack(plainPack('non-admin writes'))
		const definition = plainPack('non-admin create')
		const calls: Array<[string, Promise<APIResponse>]> = [
			['create', other.post(API, { data: { definition } })],
			[
				'update',
				other.put(`${API}/${pack.id}`, {
					data: { definition: { ...pack.definition, name: 'x' } },
				}),
			],
			['destroy', other.delete(`${API}/${pack.id}`)],
			[
				'import',
				other.post(`${API}/import`, {
					multipart: { file: jsonFile(definition) },
				}),
			],
			[
				'preview',
				other.post(`${API}/preview`, { data: { definition, rows: [] } }),
			],
		]
		for (const [route, call] of calls) {
			expect((await call).status(), `${route} let a non-admin through`).toBe(
				403,
			)
		}
		expect((await readPack(pack.id)).definition).toEqual(pack.definition)
		expect(
			await packBySlug(definition.id),
			'a non-admin created a pack',
		).toBeUndefined()
	})

	// @e2e migration-mapping-packs::any-authenticated-user-can-browse-and-export-packs
	test('a non-admin can list and export packs', async () => {
		const pack = await createPack(plainPack('non-admin export'))
		const list = await other.get(API)
		expect(list.status()).toBe(200)
		expect(
			((await list.json()).results as StoredPack[]).map((stored) => stored.id),
		).toContain(pack.id)

		const exported = await other.get(`${API}/${pack.id}/export`)
		expect(exported.status()).toBe(200)
		expect(JSON.parse(await exported.text())).toEqual(pack.definition)
	})
})

test.describe('the admin menu entry', () => {
	// @e2e migration-mapping-packs::a-non-admin-does-not-see-the-menu-entry
	test('the navigation has a Migration packs entry for an administrator', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/openregister/', {
			waitUntil: 'domcontentloaded',
		})
		const nav = page.locator('.app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 30_000 })
		await expectSignedInAs(page, ADMIN)
		await dismissBlockingModals(page)

		const entry = nav.getByRole('link', { name: 'Migration packs', exact: true })
		if (!(await entry.isVisible())) {
			await nav.getByText('Integration', { exact: true }).first().click()
		}
		await expect(entry).toBeVisible()
		await entry.click()
		await expect(
			page.getByRole('heading', {
				level: 2,
				name: 'Migration packs',
				exact: true,
			}),
		).toBeVisible({ timeout: 30_000 })
	})
})

test.describe('the built-in slug lock in the editor', () => {
	// @e2e migration-mapping-packs::renaming-a-built-in-pack-in-the-json-view-is-stopped-in-the-editor
	test('renaming a built-in pack in the JSON view is stopped in the editor', async ({
		page,
	}) => {
		const builtin = await packBySlug(BUILTIN_SLUG)
		expect(
			builtin,
			`the built-in pack ${BUILTIN_SLUG} is not installed`,
		).toBeDefined()
		await openEditor(page, builtin!, ADMIN)
		await expect(
			page.getByRole('textbox', { name: 'Slug', exact: true }),
			'the slug of a built-in pack must be read-only',
		).toBeDisabled()
		const updates = recordUpdates(page, builtin!.id)

		await page
			.getByRole('button', { name: 'Advanced (JSON)', exact: true })
			.click()
		await page.locator('.cm-content').click()
		await page.keyboard.press('ControlOrMeta+a')
		await page.keyboard.insertText(
			JSON.stringify({ ...builtin!.definition, id: 'my-zgw' }, null, 2),
		)

		const refusal = page.getByText(
			`The slug of a built-in pack cannot change. Keep "id" as ${BUILTIN_SLUG}.`,
			{ exact: true },
		)
		await page.getByRole('button', { name: 'Form', exact: true }).click()
		await expect(
			refusal,
			'going back to the form took the new slug',
		).toBeVisible()
		await expect(
			page.getByRole('heading', { name: 'Advanced (JSON)', level: 3 }),
		).toBeVisible()

		await saveButton(page).click()
		await expect(refusal).toBeVisible()
		expect(updates, 'the editor sent the renamed pack').toHaveLength(0)
		expect((await readPack(builtin!.id)).packSlug).toBe(BUILTIN_SLUG)
	})
})

test.describe('with a browser session instead of Basic auth', () => {
	/*
	 * The cookie session global-setup.ts stored, and no Authorization or
	 * OCS-APIRequest header: what a browser tab sends.
	 */
	test.use({ storageState: STORAGE_STATE, extraHTTPHeaders: {} })

	// @e2e migration-mapping-packs::a-cross-site-write-is-refused
	test('a write without the request token is refused', async ({ page }) => {
		const pack = await createPack(plainPack('csrf'))

		const read = await page.request.get(`${API}/${pack.id}`, {
			headers: { Accept: 'application/json' },
		})
		expect(
			read.status(),
			'the stored session does not authenticate; the refusal below would prove nothing',
		).toBe(200)

		const write = await page.request.delete(`${API}/${pack.id}`, {
			headers: { Accept: 'application/json' },
			maxRedirects: 0,
		})
		expect(
			write.status(),
			'Nextcloud accepted a write without the request token',
		).toBe(412)
		expect((await readPack(pack.id)).packSlug).toBe(pack.packSlug)
	})

	// @e2e migration-mapping-packs::the-editors-own-writes-pass
	test("the editor's own writes carry the request token and pass", async ({
		page,
	}) => {
		const pack = await createPack(plainPack('csrf editor'))
		await openEditor(page, pack, ADMIN)

		await page
			.getByRole('textbox', { name: 'Description', exact: true })
			.fill('Saved in a browser session')
		const sent = await saveInEditor(page, pack, '1.0.1')
		const headers = await sent.allHeaders()
		expect(
			headers.requesttoken,
			'the editor sent its save without the request token',
		).toBeTruthy()
		expect(
			headers.authorization,
			'this test must run on the cookie session',
		).toBeUndefined()
		expect((await readPack(pack.id)).definition.description).toBe(
			'Saved in a browser session',
		)
	})
})

test.describe('the slug lock outlives a delete', () => {
	// @e2e migration-mapping-packs::a-deleted-built-in-pack-imported-again-keeps-its-lock
	test('a deleted built-in pack imported again keeps its lock', async () => {
		const builtin = await packBySlug(BUILTIN_SLUG)
		expect(
			builtin,
			`the built-in pack ${BUILTIN_SLUG} is not installed`,
		).toBeDefined()
		const definition = builtin!.definition

		try {
			const deleted = await admin.delete(`${API}/${builtin!.id}`)
			expect(
				deleted.ok(),
				`the built-in pack could not be deleted: ${await deleted.text()}`,
			).toBe(true)
			expect(await packBySlug(BUILTIN_SLUG)).toBeUndefined()

			const imported = await admin.post(`${API}/import`, {
				multipart: { file: jsonFile(definition) },
			})
			expect(imported.status(), await imported.text()).toBe(201)
			const restored = (await imported.json()) as StoredPack
			expect(restored.packSlug).toBe(BUILTIN_SLUG)
			expect(
				restored.builtin,
				'a pack imported under a deleted built-in slug must be built-in',
			).toBe(true)

			await expectError(
				await admin.put(`${API}/${restored.id}`, {
					data: { definition: { ...definition, id: 'my-zgw' } },
				}),
				422,
				'builtin-slug-locked',
			)
			expect((await readPack(restored.id)).packSlug).toBe(BUILTIN_SLUG)
		} finally {
			// Put OpenRegister's own pack back whatever failed above.
			if ((await packBySlug(BUILTIN_SLUG)) === undefined) {
				const again = await admin.post(`${API}/import`, {
					multipart: { file: jsonFile(definition) },
				})
				expect(
					again.status(),
					`could not restore ${BUILTIN_SLUG}: ${await again.text()}`,
				).toBe(201)
			}
		}
	})
})
