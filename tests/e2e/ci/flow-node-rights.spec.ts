import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A POWERFUL FLOW STEP NEEDS A RIGHT — flow-powerful-steps-need-a-right 4.1.
 *
 * A non-administrator who may create flows may not put a step that sends
 * e-mail into one until an administrator grants `flow.node.send-email` to a
 * group they are in, on the "Action rights" section of the admin settings.
 *
 *   the node catalogue the editor reads  -> the e-mail step is `locked` for them
 *   saving a flow with an e-mail step    -> 403, naming the step type and the right
 *   an administrator grants the right    -> on the settings screen, to their group
 *   the same save                        -> 201, and the step is no longer locked
 *   the grant is taken back afterwards   -> the matrix is left as it was found
 *
 * The step picker in @conduction/nextcloud-vue does not draw a lock yet; the
 * catalogue's `locked` is what it will read, so that is what is asserted.
 *
 * Accounts: `e2e-other` from the workflow's seed command, put in a group this
 * spec creates. The administrator's browser session comes from global-setup.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const AUTHOR = 'e2e-other'
const AUTHOR_PASS = 'E2e-Share-Pass-123'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'
const RIGHT = 'flow.node.send-email'
const STEP = 'openregister.send-email'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)
const GROUP = `e2e-flow-mailers-${RUN}`

test.describe.configure({ mode: 'serial' })

/**
 * An API context for one account.
 *
 * @param user The user id.
 * @param password The password.
 */
async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** The smallest runnable flow with an e-mail step: manual start, e-mail, end. */
function mailFlow(): Record<string, unknown> {
	return {
		name: `e2e mail flow ${RUN}`,
		nodes: [
			{ id: 'start', type: 'openregister.trigger-manual', config: {} },
			{ id: 'mail', type: STEP, config: { recipients: ADMIN, subject: `Hello ${RUN}` } },
			{ id: 'end', type: 'openregister.end', config: {} },
		],
		edges: [
			{ source: 'start', target: 'mail' },
			{ source: 'mail', target: 'end' },
		],
	}
}

test.describe('a powerful flow step needs a right', () => {
	let admin: APIRequestContext
	let author: APIRequestContext
	let groupsBefore: string[] = []
	let ready = false
	const created: string[] = []

	/** Whether the e-mail step is locked for the author in the editor's catalogue. */
	async function mailLockedForAuthor(): Promise<boolean | undefined> {
		const catalog = await (await author.get(`${API}/flow/node-catalog`)).json()
		const entries = (Array.isArray(catalog) ? catalog : (catalog.results ?? [])) as Array<Record<string, unknown>>
		const mail = entries.find((entry) => entry.id === STEP)
		expect(mail, 'the catalogue offers no e-mail step at all').toBeTruthy()

		return mail?.locked as boolean | undefined
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		author = await contextFor(AUTHOR, AUTHOR_PASS)

		const rights = await (await admin.get(`${API}/settings/action-rights`)).json()
		const entry = (rights.actions as Array<Record<string, unknown>>).find((row) => row.action === RIGHT)
		groupsBefore = (entry?.groups as string[] | undefined) ?? []

		const made = await admin.post('/ocs/v2.php/cloud/groups', { form: { groupid: GROUP } })
		const joined = await admin.post(`/ocs/v2.php/cloud/users/${AUTHOR}/groups`, { form: { groupid: GROUP } })
		ready = made.status() !== 404 && joined.status() !== 404
	})

	test.afterAll(async () => {
		// Leave the matrix as it was found, whatever happened above.
		const restored = await admin.put(`${API}/settings/action-rights`, {
			data: { groups: { [RIGHT]: groupsBefore } },
		})
		expect(restored.ok(), `the action rights could not be restored: ${await restored.text()}`).toBeTruthy()
		for (const uuid of created) {
			await author.delete(`${API}/flows/${uuid}`)
		}
		await admin.delete(`/ocs/v2.php/cloud/groups/${GROUP}`)
		await admin.dispose()
		await author.dispose()
	})

	test('without the right, the e-mail step is locked and a flow with it is refused', async () => {
		test.skip(ready === false, 'the provisioning API is absent, so the author group could not be made')

		expect(await mailLockedForAuthor(), 'the e-mail step must be locked for an author without the right').toBe(true)

		const refused = await author.post(`${API}/flows`, { data: mailFlow() })
		expect(refused.status(), `a flow with an e-mail step was not refused: ${await refused.text()}`).toBe(403)
		const message = JSON.stringify(await refused.json())
		expect(message, 'the refusal names the step type').toContain(STEP)
		expect(message, 'the refusal names the right').toContain(RIGHT)
	})

	test.describe('the administrator grants it on the settings screen', () => {
		test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 1000 } })

		test('and then the same flow saves', async ({ page }) => {
			test.skip(ready === false, 'the provisioning API is absent, so the author group could not be made')

			await page.goto('/index.php/settings/admin/openregister', { waitUntil: 'domcontentloaded' })
			const row = page.locator('.action-rights__right').filter({ hasText: RIGHT })
			await expect(row, 'the Action rights section does not list the e-mail step right').toBeVisible({ timeout: 30_000 })

			const groups = row.getByRole('combobox')
			await groups.click()
			await groups.fill(GROUP)
			await expect(page.locator('.vs__dropdown-option', { hasText: GROUP }).first()).toBeVisible({ timeout: 15_000 })
			await groups.press('Enter')
			await expect(row.locator('.vs__selected', { hasText: GROUP })).toBeVisible()

			const saved = page.waitForResponse(
				(response) => response.request().method() === 'PUT'
					&& new URL(response.url()).pathname.endsWith('/apps/openregister/api/settings/action-rights'),
				{ timeout: 15_000 },
			)
			await page.locator('.action-rights').getByRole('button', { name: 'Save', exact: true }).dispatchEvent('click')
			expect((await saved).status(), 'the grant was not saved').toBeLessThan(300)
			await expect(page.locator('.action-rights')).toContainText('The action rights are saved.')

			// Stored as chosen, and the administrators the right already had are kept.
			const rights = await (await admin.get(`${API}/settings/action-rights`)).json()
			const entry = (rights.actions as Array<Record<string, unknown>>).find((r) => r.action === RIGHT)
			expect(entry?.groups).toEqual(expect.arrayContaining([...groupsBefore, GROUP]))

			// An open entry carries no `locked` at all, rather than `false`.
			expect(await mailLockedForAuthor(), 'the e-mail step is still locked after the grant').not.toBe(true)

			const accepted = await author.post(`${API}/flows`, { data: mailFlow() })
			expect(accepted.status(), `the flow was still refused: ${await accepted.text()}`).toBe(201)
			created.push(String((await accepted.json()).id))
		})
	})
})
