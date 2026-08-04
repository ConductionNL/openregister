/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * THE SHARES TAB — driven through the browser, group 10.1 / 10.2.
 *
 * `object-sharing.spec.ts` next to this file already drives the same capability
 * over HTTP, and the live-DB PHPUnit suite proves the four enforcement paths
 * agree. Neither of them can fail if the UI never renders: the component could
 * be missing from the bundle, the tab could be gated on a condition that is
 * never true, or the props could arrive as `undefined` and send every request to
 * `/api/objects/undefined/undefined/undefined/shares`. All three are green-but-
 * dead states that an API-level suite reports as success.
 *
 * So this one CLICKS. And because a UI test that asserts on its own UI can pass
 * while nothing was persisted, every consequence here is measured OUTSIDE the
 * browser — a separate API context, authenticated as the other user, asking the
 * question that actually matters: can they reach the object or not.
 *
 *   owner clicks "Private"        -> other user's GET must start failing
 *   owner adds a grant in the UI  -> other user's GET must start working
 *   owner clicks revoke in the UI -> other user's GET must fail again
 *
 * The before-state is asserted in every case, so "it was already invisible" can
 * never be mistaken for "the click worked".
 *
 * HERMETIC. It creates its own register, schema and one object per test. The two
 * accounts come from the workflow's `playwright-seed-command`, shared with the
 * sibling spec — see the note there on why they are fixed rather than per-run.
 */
import { test, expect, request as pwRequest, type APIRequestContext, type Page } from '@playwright/test'
import { resolveBaseUrl } from '../base-url'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

const OWNER = 'e2e-owner'
const OTHER = 'e2e-other'
const PASS = 'E2e-Share-Pass-123'

/** Seeded group whose only member is OTHER — see tests/e2e/ci/seed.sh. */
const GROUP = 'e2e-grantees'

/** Build an API context authenticated as one user. */
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

/**
 * Close any modal covering the page.
 *
 * On the FIRST app load of a fresh instance, nc-vue's support dialog
 * (`cn-support-dialog`) opens over everything, and its mask intercepts pointer
 * events — the tab click retried for 45s against "subtree intercepts pointer
 * events" while reporting the tab itself as visible, enabled and stable, which
 * reads like a broken selector and is not one. Only the first test in a run hit
 * it, so it also looks like flake.
 *
 * Escape rather than `dispatchEvent`: a real user meets this dialog too, so the
 * test should get past it the way a user does. Bypassing hit-testing would hide
 * a genuine overlay regression.
 *
 * Fails loudly if a mask survives, instead of proceeding into a click that
 * cannot land.
 */
async function dismissBlockingModals(page: Page): Promise<void> {
	const mask = page.locator('.modal-mask:visible')

	for (let attempt = 0; attempt < 3; attempt++) {
		if (await mask.count() === 0) {
			return
		}
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)
	}

	await expect(
		mask,
		'a modal is still covering the page after three Escapes — it will swallow every click',
	).toHaveCount(0)
}

/**
 * Open an object's detail page and select the Shares tab.
 *
 * `/objects/:register/:schema/:id` is the deep-link route declared in
 * `src/manifest.json`; clicking down the registers -> schemas -> objects chain
 * would test the navigation tree instead of the sharing surface.
 *
 * Returns the tab panel root, so a caller cannot accidentally assert against
 * another tab's markup. Inactive AppTab panels are not rendered at all (AppTab
 * returns nothing when it is not the active tab), so anything found under this
 * locator is genuinely on screen.
 */
async function openSharesTab(
	page: Page,
	register: string,
	schema: string,
	uuid: string,
) {
	await page.goto(
		`/index.php/apps/openregister/#/objects/${register}/${schema}/${uuid}`,
		{ waitUntil: 'domcontentloaded' },
	)

	const tab = page.getByRole('tab', { name: 'Shares' })
	await expect(
		tab,
		'no "Shares" tab on the object detail page — the tab is gated on relationContext, '
		+ 'so this also fails when register/schema/id did not reach the view',
	).toBeVisible({ timeout: 30_000 })

	/*
	 * WHO IS THIS PAGE? Asserted, not assumed.
	 *
	 * The config authenticates every request as admin and the describe block
	 * overrides that header. If the override ever stops taking effect, all three
	 * tests would keep passing while measuring an ADMINISTRATOR'S view — which
	 * bypasses the private scope, so the very thing under test would be gone and
	 * nothing would go red. That is the failure this line exists to make loud.
	 */
	const uid = await page.evaluate(() => (window as any).OC?.getCurrentUser?.()?.uid ?? null)
	expect(
		uid,
		'the browser is not authenticated as the owner — this would silently become an admin '
		+ 'test, and an admin bypasses the private scope',
	).toBe(OWNER)

	await dismissBlockingModals(page)
	await tab.click()

	const panel = page.locator('.cn-object-access-tab')
	await expect(
		panel,
		'the Shares tab is present but CnObjectAccessTab did not render — check that the '
		+ 'installed @conduction/nextcloud-vue actually exports it and that js/ was rebuilt',
	).toBeVisible({ timeout: 20_000 })

	return panel
}

/** Fetch the object as a given user and return the status code. */
async function statusFor(
	ctx: APIRequestContext,
	register: string,
	schema: string,
	uuid: string,
): Promise<number> {
	const res = await ctx.get(
		`/index.php/apps/openregister/api/objects/${register}/${schema}/${uuid}`,
	)
	return res.status()
}

test.describe('the Shares tab, driven through the browser', () => {
	/*
	 * DRIVE THE BROWSER AS THE OWNER, NOT AS ADMIN.
	 *
	 * Admin is the one account whose view proves nothing here: an administrator
	 * bypasses the private scope by design, so a tab driven as admin would show
	 * access nobody had to grant.
	 *
	 * This overrides the config's `use.extraHTTPHeaders`, which authenticates
	 * every request — page navigations included — as admin. That header is also
	 * why a form login does NOT work from a test context: `/index.php/login`
	 * arrives already authenticated and redirects, so `input[name="user"]` never
	 * renders and the fill times out. (globalSetup CAN form-log-in because it
	 * builds its own context with no such header.) Swapping the credentials is
	 * both simpler and how the sibling specs authenticate page loads.
	 */
	test.use({
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${OWNER}:${PASS}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
		},
	})

	let admin: APIRequestContext
	let owner: APIRequestContext
	let other: APIRequestContext
	let registerId: string
	let schemaId: string

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		owner = await contextFor(OWNER, PASS)
		other = await contextFor(OTHER, PASS)

		const reg = await admin.post('/index.php/apps/openregister/api/registers', {
			data: { title: `e2e shares tab register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// All four actions are listed deliberately: a non-empty authorization
		// block fails CLOSED for anything it omits, so a read-only block would
		// stop the owner from creating the fixture at all.
		const sch = await admin.post('/index.php/apps/openregister/api/schemas', {
			data: {
				title: `e2e shares tab schema ${RUN}`,
				description: 'e2e',
				properties: { key: { type: 'string', title: 'Key', maxLength: 255 } },
				authorization: {
					read: ['authenticated'],
					create: ['authenticated'],
					update: ['authenticated'],
					delete: ['authenticated'],
				},
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)
	})

	/**
	 * One fresh object per test, so no test depends on what an earlier one left
	 * behind. The scope tests mutate the object they are given, and a shared
	 * fixture would make the third test's result depend on the second's.
	 */
	async function newObject(): Promise<string> {
		const res = await owner.post(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`,
			{ data: { key: `tab-${Math.random().toString(36).slice(2, 8)}` } },
		)
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		return uuid
	}

	test('the tab renders the live access surface, not an empty panel', async ({ page }) => {
		const uuid = await newObject()
		const panel = await openSharesTab(page, registerId, schemaId, uuid)

		// The three sections the component owns. Asserting on rendered content
		// rather than on the presence of the root element: a component that
		// mounted and then failed its first request still renders the root.
		//
		// By ROLE, not by text. `getByText('Shared with')` matches substrings
		// case-insensitively, so it also resolved the empty-state paragraph
		// "Not shared with anyone yet" and failed on strict mode with two
		// elements — an assertion that broke on the very content it was meant to
		// coexist with. The role also pins these as headings rather than as any
		// element that happens to contain the words.
		await expect(panel.getByRole('heading', { name: 'Visibility' })).toBeVisible()
		await expect(panel.getByRole('heading', { name: 'Shared with' })).toBeVisible()
		await expect(panel.getByRole('heading', { name: 'Add access' })).toBeVisible()

		// A brand-new object has no grants, and the empty state is the proof
		// that the grants request SUCCEEDED and came back empty — the error
		// branch renders a different element in its place.
		await expect(panel.getByText('Not shared with anyone yet')).toBeVisible()
		await expect(
			panel.locator('.cn-object-access-tab__error'),
			'the tab rendered its error branch — the shares request failed',
		).toHaveCount(0)
		await expect(
			panel.locator('.cn-object-access-tab__banner'),
			'the tab rendered its degraded banner',
		).toHaveCount(0)
	})

	test('clicking Private in the UI hides the object from another user', async ({ page }) => {
		const uuid = await newObject()

		// CONTROL. Without this, "the other user cannot see it" is unfalsifiable
		// — an object they never could see would produce the same verdict.
		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'the object must start out readable by the other user, or the toggle proves nothing',
		).toBeLessThan(300)

		const panel = await openSharesTab(page, registerId, schemaId, uuid)

		// Click the switch's label: NcCheckboxRadioSwitch renders a visually
		// hidden input, and clicking the input itself is not an actionable
		// target.
		await panel.getByText('Private', { exact: true }).click()

		// The component only emits/persists on a successful PUT, and it reverts
		// the switch when the write is refused, so the hint flipping to the
		// private wording means the server accepted it.
		await expect(
			panel.getByText('Only you, administrators, and the people below can reach this.'),
			'the scope switch did not stick — the PUT was refused and the component reverted',
		).toBeVisible({ timeout: 20_000 })

		// The consequence, measured outside the browser.
		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'the object is still readable by the other user after being made private in the UI',
		).toBeGreaterThanOrEqual(400)

		// And the owner has not locked themselves out.
		expect(
			await statusFor(owner, registerId, schemaId, uuid),
			'the owner can no longer reach their own private object',
		).toBeLessThan(300)
	})

	test('granting in the UI restores access, and revoking in the UI removes it', async ({ page }) => {
		const uuid = await newObject()

		// Set the scope over the API: this test is about the grant controls, and
		// driving the toggle again here would make it depend on the previous
		// test's subject.
		const put = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${uuid}/scope`,
			{ data: { scope: 'private' } },
		)
		expect(put.ok(), `could not set the scope: ${await put.text()}`).toBeTruthy()

		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'a private object must be invisible before the grant, or the grant proves nothing',
		).toBeGreaterThanOrEqual(400)

		const panel = await openSharesTab(page, registerId, schemaId, uuid)

		// The type select defaults to "user", so the username field is the only
		// input this needs.
		await panel.getByRole('textbox', { name: 'Username' }).fill(OTHER)
		await panel.getByRole('button', { name: 'Share', exact: true }).click()

		// The grant row appearing means the POST came back and the list was
		// refetched — the component clears the form only on success.
		await expect(
			panel.locator('.cn-object-access-tab__row'),
			'no grant row appeared after clicking Share',
		).toHaveCount(1, { timeout: 20_000 })

		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'the granted user still cannot reach the object after a grant made in the UI',
		).toBeLessThan(300)

		// Revoke, through the UI.
		await panel.getByRole('button', { name: 'Revoke access' }).click()
		await expect(
			panel.locator('.cn-object-access-tab__row'),
			'the grant row is still there after clicking revoke',
		).toHaveCount(0, { timeout: 20_000 })

		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'a revoked user can still reach the object — revocation takes effect on the NEXT '
			+ 'request, so this is not a propagation delay',
		).toBeGreaterThanOrEqual(400)
	})

	/*
	 * THE GROUP PATH, which is the one that was silently broken.
	 *
	 * The tab posted `shareType: 0 | 1` — a key
	 * ObjectSharingController::createShare() does not read — so it fell through to
	 * the controller's 'user' default and picking "Group" created a USER grant to
	 * a uid spelled like the group name. A user grant worked by coincidence, so
	 * every UI test that used one stayed green (nextcloud-vue#591).
	 *
	 * Access is checked as OTHER, who is in the group and is named nowhere in the
	 * grant. That is the whole discriminator: under the old behaviour the share
	 * went to a nonexistent user called "e2e-grantees" and OTHER got nothing.
	 */
	test('granting to a GROUP in the UI reaches a member of that group', async ({ page }) => {
		const uuid = await newObject()

		const put = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${uuid}/scope`,
			{ data: { scope: 'private' } },
		)
		expect(put.ok(), `could not set the scope: ${await put.text()}`).toBeTruthy()

		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'the group member must be locked out before the grant, or the grant proves nothing',
		).toBeGreaterThanOrEqual(400)

		const panel = await openSharesTab(page, registerId, schemaId, uuid)

		await panel.getByRole('combobox').click()
		await page.getByRole('option', { name: 'Group' }).click()

		// The label changing to "Group name" is how we know the select changed the
		// component's state and not merely its own display.
		await panel.getByRole('textbox', { name: 'Group name' }).fill(GROUP)
		await panel.getByRole('button', { name: 'Share', exact: true }).click()

		await expect(
			panel.locator('.cn-object-access-tab__row'),
			'no grant row appeared after granting to a group',
		).toHaveCount(1, { timeout: 20_000 })

		// A sanity check only, NOT the discriminator: the row renders
		// `sharedWith`, which was the group's name under the broken behaviour
		// too — the share went to a nonexistent USER called "e2e-grantees" and
		// still displayed "e2e-grantees". This line would have passed either way.
		await expect(panel.locator('.cn-object-access-tab__row')).toContainText(GROUP)

		// THIS is the discriminator. OTHER is named nowhere in the grant and can
		// only have gained access through group membership, so a user share
		// spelled like the group leaves them locked out.
		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'a member of the granted group cannot reach the object — the grant was probably '
			+ 'created as a USER share named after the group',
		).toBeLessThan(300)

		await panel.getByRole('button', { name: 'Revoke access' }).click()
		await expect(panel.locator('.cn-object-access-tab__row')).toHaveCount(0, { timeout: 20_000 })

		expect(
			await statusFor(other, registerId, schemaId, uuid),
			'the group member still has access after the group grant was revoked',
		).toBeGreaterThanOrEqual(400)
	})

	/*
	 * Task 10.3. The sibling HTTP spec already proves a token resolves
	 * anonymously and stops when revoked; what it cannot show is that the LINK
	 * CONTROL in the tab produces such a token — the "Public link" option could
	 * post to the wrong endpoint, or render a token it never received, and the
	 * HTTP spec would stay green because it mints its own link.
	 *
	 * The anonymous half genuinely has to be a separate browser context: this
	 * describe block puts the owner's Authorization header on every request the
	 * test context makes, so reusing `page` would "prove" that the owner can read
	 * their own object.
	 */
	test('a link created in the UI resolves with no credentials, and dies when revoked', async ({ page, browser }) => {
		const uuid = await newObject()

		const put = await owner.put(
			`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${uuid}/scope`,
			{ data: { scope: 'private' } },
		)
		expect(put.ok(), `could not set the scope: ${await put.text()}`).toBeTruthy()

		const panel = await openSharesTab(page, registerId, schemaId, uuid)

		// Pick "Public link" in the type select. The dropdown is vue-select
		// underneath, and its listbox is not necessarily inside the panel, so the
		// option is looked up on the page while the combobox is not.
		await panel.getByRole('combobox').click()
		await page.getByRole('option', { name: 'Public link' }).click()

		// A link needs no principal, so the username field must be gone — this
		// also confirms the select actually changed the component's state rather
		// than just its own display.
		await expect(
			panel.getByRole('textbox', { name: 'Username' }),
			'the type select did not take effect — the principal field is still there',
		).toHaveCount(0)

		await panel.getByRole('button', { name: 'Share', exact: true }).click()

		const tokenNode = panel.locator('.cn-object-access-tab__link code')
		await expect(
			tokenNode,
			'no token appeared after creating a link — the component only renders one it received',
		).toBeVisible({ timeout: 20_000 })

		const token = (await tokenNode.innerText()).trim()
		expect(token, 'the rendered token is empty').not.toBe('')

		// ANONYMOUS: a context with no credentials at all, and no shared state
		// with the authenticated one.
		const anon = await browser.newContext({ baseURL: BASE, storageState: undefined })
		try {
			const live = await anon.request.get(`/index.php/apps/openregister/api/shared/${token}`)
			expect(
				live.status(),
				`a link minted through the UI must resolve anonymously: ${await live.text()}`,
			).toBeLessThan(300)

			// Revoke it in the UI. The link is the only grant on this object, so
			// there is exactly one revoke button to press.
			await panel.getByRole('button', { name: 'Revoke access' }).click()
			await expect(
				panel.locator('.cn-object-access-tab__row'),
				'the link row is still listed after clicking revoke',
			).toHaveCount(0, { timeout: 20_000 })

			const dead = await anon.request.get(`/index.php/apps/openregister/api/shared/${token}`)
			expect(
				dead.status(),
				'a link revoked in the UI still resolves — the token check happens per request, so '
				+ 'this is not a cache',
			).toBe(404)
		} finally {
			await anon.close()
		}
	})
})
