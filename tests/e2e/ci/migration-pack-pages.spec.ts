import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE MIGRATION PACK PAGES, driven through the browser.
 *
 * Two manifest pages: `migration-packs` (MigrationPacksIndex, the list) and
 * `migration-pack-detail` (MigrationPackDetail, the editor). The Jest specs in
 * src/views/migrationPack/ drive their options objects against a synthetic
 * `this`, so none of them can fail when a page never renders: a route missing
 * from the manifest, a component missing from the registry, or a pack that
 * never reaches the mapping table all leave every unit test green.
 *
 * @e2e openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#scenario-a-non-admin-who-opens-the-editor-by-url-can-only-read
 *
 * WHAT THIS FILE PROVES.
 *
 *  - an administrator sees the list, with the pack this file created in it;
 *  - clicking that pack opens the editor on it;
 *  - the editor's field-mapping table shows the pack's rows, in their order;
 *  - an ordinary user who opens the editor by URL reads the same pack, with a
 *    note that only administrators change packs and no Save action.
 *
 * The browser identity is asserted on every page, not assumed: the config
 * authenticates page loads as admin, and an override that stopped working
 * would turn the non-admin case into an admin case that still passes.
 *
 * HERMETIC. It creates its own pack through the admin API and deletes it in
 * `afterAll`. The ordinary user is `e2e-other`, provisioned by the workflow's
 * `playwright-seed-command` (tests/e2e/ci/seed.sh), as in the sharing specs.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** Seeded non-admin account, see tests/e2e/ci/seed.sh. */
const OTHER = 'e2e-other'
const OTHER_PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const PACK_NAME = `e2e migration pack ${RUN}`

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

const PACK_DEFINITION = {
	id: `e2e-migration-pack-${RUN}`,
	name: PACK_NAME,
	description: 'Created by tests/e2e/ci/migration-pack-pages.spec.ts.',
	sourceFormat: 'excel',
	version: '1.0.0',
	fieldMappings: MAPPINGS,
	defaults: { type: 'Application' },
	idStrategy: { type: 'generate' },
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

/** The rows of the editor's field-mapping table. */
function mappingRows(page: Page) {
	return page.locator(
		'section[aria-labelledby="migration-pack-mappings"] tbody tr',
	)
}

let admin: APIRequestContext
let packId: string

test.beforeAll(async () => {
	admin = await contextFor(ADMIN, ADMIN_PASS)

	const other = await contextFor(OTHER, OTHER_PASS)
	const probe = await other.get('/index.php/apps/openregister/api/migration-packs')
	expect(
		probe.status(),
		`seeded account '${OTHER}' cannot read the packs (${probe.status()}), did playwright-seed-command run?`,
	).toBeLessThan(400)
	await other.dispose()

	const created = await admin.post(
		'/index.php/apps/openregister/api/migration-packs',
		{ data: { definition: PACK_DEFINITION } },
	)
	expect(created.status(), `pack create failed: ${await created.text()}`).toBe(201)
	packId = String((await created.json()).id)
	expect(packId, 'no id came back from the pack create').toMatch(/^\d+$/)
})

test.afterAll(async () => {
	if (packId) {
		await admin
			.delete(`/index.php/apps/openregister/api/migration-packs/${packId}`)
			.catch(() => undefined)
	}
	await admin?.dispose()
})

test.describe('migration packs as an administrator', () => {
	test('the list renders with the pack in it, and the pack opens in the editor', async ({
		page,
	}) => {
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
		await expectSignedInAs(page, ADMIN)
		await dismissBlockingModals(page)

		const table = page.locator('table.migration-packs__table')
		await expect(
			table.getByRole('columnheader', { name: 'Slug', exact: true }),
		).toBeVisible()
		const link = table.getByRole('link', { name: PACK_NAME, exact: true })
		await expect(link, 'the created pack is not in the list').toBeVisible()
		await expect(table).toContainText(PACK_DEFINITION.id)
		await expect(
			page.getByRole('button', { name: 'Import JSON', exact: true }),
		).toBeVisible()

		await link.click()

		await expect(page).toHaveURL(
			new RegExp(`/apps/openregister/migration-packs/${packId}$`),
		)
		await expect(
			page.getByRole('heading', { level: 2, name: PACK_NAME, exact: true }),
			'MigrationPackDetail did not open the clicked pack',
		).toBeVisible({ timeout: 30_000 })
	})

	test('the field-mapping table shows every row of the pack, in order', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/openregister/migration-packs/${packId}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.getByRole('heading', {
				level: 3,
				name: 'Field mappings',
				exact: true,
			}),
		).toBeVisible({ timeout: 30_000 })
		await expectSignedInAs(page, ADMIN)
		await dismissBlockingModals(page)

		await expect(mappingRows(page)).toHaveCount(MAPPINGS.length)
		for (const [index, mapping] of MAPPINGS.entries()) {
			const number = index + 1
			await expect(
				page.getByRole('textbox', {
					name: `Source column of row ${number}`,
				}),
			).toHaveValue(mapping.source)
			await expect(
				page.getByRole('textbox', { name: `Target field of row ${number}` }),
			).toHaveValue(mapping.target)
		}
		await expect(
			page.getByRole('checkbox', { name: 'Row 1 is required' }),
		).toBeChecked()
		await expect(
			page.getByRole('textbox', { name: 'Source column of row 1' }),
		).toBeEnabled()
		await expect(
			page.getByRole('button', { name: 'Save', exact: true }),
		).toBeVisible()
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

	test('the editor opened by URL reads the pack and offers no Save', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/openregister/migration-packs/${packId}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(
			page.getByRole('heading', { level: 2, name: PACK_NAME, exact: true }),
			'a non-admin should still read the pack',
		).toBeVisible({ timeout: 30_000 })
		await expectSignedInAs(page, OTHER)
		await dismissBlockingModals(page)

		await expect(
			page.getByText(
				'Only administrators can change migration packs. You can read this one.',
			),
		).toBeVisible()
		await expect(mappingRows(page)).toHaveCount(MAPPINGS.length)
		await expect(
			page.getByRole('textbox', { name: 'Source column of row 1' }),
		).toBeDisabled()
		await expect(
			page.getByRole('button', { name: 'Save', exact: true }),
			'a non-admin must not be offered Save',
		).toHaveCount(0)
	})
})
