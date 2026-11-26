import type { APIRequestContext, Locator, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A FLOW BUILT ON THE CANVAS RUNS ON A NEW RECORD — capability-rows-verified-b 1.1.
 *
 * @e2e flow-engine::a-flow-built-on-the-canvas-runs-on-a-new-record
 *
 * An administrator places an object trigger on `object.created` for one
 * register and schema, connects it to a send-email step and an End, saves and
 * publishes the flow, all on the flow page and without writing JSON. A record
 * of that schema is then created over the API, and the flow's run list must
 * show one run with that record as its subject.
 *
 * WHAT THIS PROVES THAT THE UNIT SUITES CANNOT. The trigger's config form, the
 * node catalogue, the save and publish round trip and the event dispatch are
 * each tested on their own; whether a flow drawn by hand reaches the engine in
 * a shape the object event actually matches is only visible here.
 *
 * WHAT IT DOES NOT PROVE: delivery of the mail. The run is the assertion; a
 * sandbox or CI instance without a mail server records the send step's outcome
 * either way, and the scenario asks for the run, not the mail.
 *
 * HERMETIC. It creates its own register, schema and flow and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)

test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 1000 } })
test.describe.configure({ mode: 'serial' })

/**
 * Click a Nextcloud themed control: dispatching the event avoids the overlay
 * that intercepts a pointer click on these (see flow-controls.spec.ts).
 *
 * @param locator The control.
 */
async function press(locator: Locator): Promise<void> {
	await expect(locator).toBeVisible()
	await expect(locator).toBeEnabled()
	await locator.dispatchEvent('click')
}

/**
 * Add one step from the picker, by its exact name, and return its node.
 *
 * @param page The page.
 * @param name The step's display name.
 */
async function addStep(page: Page, name: string): Promise<Locator> {
	await press(page.locator('[data-testid="flow-add-step"]'))
	const picker = page.locator('[data-testid="flow-step-picker"]')
	await expect(picker).toBeVisible()
	await press(
		picker
			.locator('[data-testid="flow-step-picker-item"]')
			.filter({ has: page.locator('.cn-step-picker__name', { hasText: new RegExp(`^${name}$`) }) })
			.first(),
	)
	await expect(picker).toBeHidden()
	// A new step lands beside the last one, often outside the canvas at the
	// editor's starting zoom; fit the flow so every node can be clicked.
	await page.getByRole('button', { name: 'Fit the whole flow in view' }).click()
	// Anchored: a loose `hasText: 'End'` also matches "Send an email".
	const node = page.locator('.cn-flow-node', { hasText: new RegExp(`^\\s*${name.slice(0, 18)}`) }).first()
	await expect(node, `the ${name} step did not reach the canvas`).toBeVisible()

	return node
}

/**
 * Open a node's config dialog through its own menu.
 *
 * @param page The page.
 * @param node The node.
 * @param title The dialog's title, the step's name.
 */
async function editNode(page: Page, node: Locator, title: string): Promise<Locator> {
	await node.click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	const dialog = page.getByRole('dialog', { name: title })
	await expect(dialog).toBeVisible()

	return dialog
}

/**
 * Pick one option of a select in a config dialog.
 *
 * @param page The page.
 * @param dialog The dialog.
 * @param label The select's label.
 * @param option The option's text.
 */
async function choose(page: Page, dialog: Locator, label: string, option: string): Promise<void> {
	const select = dialog.getByRole('combobox', { name: label })
	await select.click()
	await select.fill(option)
	// The filtered list highlights the match inside the option, which splits
	// its accessible name; the text match plus Enter is what a keyboard user does.
	await expect(page.locator('.vs__dropdown-option', { hasText: option }).first()).toBeVisible({ timeout: 15_000 })
	await select.press('Enter')
	await expect(dialog.locator('.vs__selected', { hasText: option }).first(), `${label} did not take ${option}`).toBeVisible()
}

test.describe('a flow built on the canvas', () => {
	let admin: APIRequestContext
	let registerId = ''
	let schemaId = ''
	let flowUuid = ''

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e canvas register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		const sch = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e melding ${RUN}`,
				description: 'e2e',
				properties: { onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 } },
			},
		})
		expect(sch.ok(), `schema create failed: ${await sch.text()}`).toBeTruthy()
		schemaId = String((await sch.json()).id)

		const linked = await admin.put(`${API}/registers/${registerId}`, {
			data: { title: `e2e canvas register ${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(linked.ok(), `linking the schema failed: ${await linked.text()}`).toBeTruthy()
	})

	test.afterAll(async () => {
		// A flow whose run has not finished refuses deletion with 409 (there is
		// no run cancel route); where background jobs run, as in CI, the run
		// finishes and this goes. Elsewhere the flow is left, which is why the
		// test reaches the editor through the page's primary action and not
		// through any link named "New flow".
		if (flowUuid !== '') {
			await admin.delete(`${API}/flows/${flowUuid}`)
		}
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		await admin.dispose()
	})

	test('an object trigger connected to a send-email step runs on a new record', async ({ page }) => {
		await page.addInitScript(() => {
			const read = Storage.prototype.getItem
			Storage.prototype.getItem = function (key: string) {
				if (typeof key === 'string' && key.startsWith('cn-support-dialog-shown:')) {
					return '1'
				}
				return read.call(this, key)
			}
		})

		await page.goto('/index.php/apps/openregister/flows', { waitUntil: 'domcontentloaded' })
		// The page's primary action, not any link named "New flow": every
		// unnamed flow in the list carries that name as well.
		const newFlow = page.getByTestId('cn-cta-primary').filter({ hasText: 'New flow' })
		await expect(newFlow).toBeVisible({ timeout: 30_000 })
		await newFlow.click()

		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		const save = toolbar.getByRole('button', { name: 'Save', exact: true })
		await expect(save, 'the editor never initialised').toBeEnabled({ timeout: 30_000 })

		// The trigger: object.created on this register and schema.
		const trigger = await addStep(page, 'When an object changes')
		const triggerForm = await editNode(page, trigger, 'When an object changes')
		await choose(page, triggerForm, 'Event', 'An object is created')
		await choose(page, triggerForm, 'Register', `e2e canvas register ${RUN}`)
		await choose(page, triggerForm, 'Schema', `e2e melding ${RUN}`)
		await press(triggerForm.getByRole('button', { name: 'Done' }))
		await expect(triggerForm).toBeHidden()

		// The step: mail the administrator.
		const mail = await addStep(page, 'Send an email')
		const mailForm = await editNode(page, mail, 'Send an email')
		await mailForm.getByRole('textbox', { name: 'Who to mail' }).fill(ADMIN)
		await mailForm.getByRole('textbox', { name: 'Subject' }).fill(`New melding ${RUN}`)
		await press(mailForm.getByRole('button', { name: 'Done' }))
		await expect(mailForm).toBeHidden()

		const end = await addStep(page, 'End')

		// A new flow opens with a manual trigger; this flow starts on the
		// object event instead, so the manual one goes.
		const manual = page.locator('.cn-flow-node', { hasText: 'When someone ru' })
		await page.getByRole('button', { name: 'Fit the whole flow in view' }).click()
		await manual.click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()
		await expect(manual).toHaveCount(0)

		// Connect by keyboard: `c` on the source, `c` on the target.
		await trigger.press('c')
		await mail.press('c')
		await mail.press('c')
		await end.press('c')
		await expect(page.locator('.vue-flow__edge'), 'the two connections did not reach the canvas').toHaveCount(2)

		const created = page.waitForResponse(
			(response) => response.request().method() === 'POST'
				&& new URL(response.url()).pathname.endsWith('/apps/openregister/api/flows'),
			{ timeout: 15_000 },
		)
		await press(save)
		const createResponse = await created
		expect(createResponse.status(), `the flow was not created: ${(await createResponse.text()).slice(0, 300)}`).toBe(201)
		flowUuid = String((await createResponse.json())?.id ?? '')
		expect(flowUuid).toMatch(/^[0-9a-f-]{8,}$/)

		// Saved as drawn: the trigger names the event, register and schema.
		const stored = await (await admin.get(`${API}/flows/${flowUuid}`)).json()
		const storedTrigger = (stored.nodes ?? []).find((node: Record<string, unknown>) => node.type === 'openregister.trigger-object')
		expect(storedTrigger, 'the saved flow has no object trigger').toBeTruthy()
		expect(storedTrigger.config.event).toBe('object.created')
		expect(String(storedTrigger.config.register)).toBe(registerId)
		expect(String(storedTrigger.config.schema)).toBe(schemaId)

		// Switch it on while it is still a draft: a published version is
		// read-only, and a flow that is switched off runs on nothing.
		const menu = page.locator('.app-sidebar-header__menu button').first()
		await press(menu)
		await press(page.getByRole('menuitem', { name: 'Enable', exact: true }).first())
		const updated = page.waitForResponse(
			(response) => ['PUT', 'PATCH'].includes(response.request().method())
				&& new URL(response.url()).pathname.includes(`/apps/openregister/api/flows/${flowUuid}`),
			{ timeout: 15_000 },
		)
		await press(save)
		expect((await updated).status(), 'switching the flow on was not saved').toBeLessThan(300)

		// Publish, which is what makes the version live.
		const publish = page.locator('[data-testid="flow-publish"]')
		if (await publish.isVisible().catch(() => false)) {
			await press(publish.locator('button').first())
		} else {
			await press(menu)
			await press(page.getByRole('menuitem', { name: 'Publish', exact: true }).first())
		}
		const publishDialog = page.locator('[data-testid="flow-publish-dialog"]')
		await expect(publishDialog).toBeVisible({ timeout: 10_000 })
		await press(publishDialog.locator('[data-testid="flow-publish-confirm"]'))
		await expect(page.locator('[data-testid="flow-lifecycle"]')).toHaveText('Published', { timeout: 15_000 })
		const live = await (await admin.get(`${API}/flows/${flowUuid}`)).json()
		expect(live.enabled, 'the published flow is not switched on').toBe(true)

		// A user creates a record of that schema.
		const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data: { onderwerp: `Melding ${RUN}` },
		})
		expect(record.ok(), `record create failed: ${await record.text()}`).toBeTruthy()
		const body = await record.json()
		const recordUuid = String(body['@self']?.id ?? body.id ?? body.uuid)

		// THEN the flow's run list shows one run with that record as its subject.
		await expect
			.poll(
				async () => {
					const runs = await admin.get(`${API}/flow-runs?flowId=${flowUuid}&limit=25`)
					if (runs.ok() === false) {
						return `HTTP ${runs.status()}`
					}
					const rows = ((await runs.json()).results ?? []) as Array<Record<string, unknown>>
					return rows.map((row) => JSON.stringify(row).includes(recordUuid)).join(',')
				},
				{ message: 'no run of the flow names the new record', timeout: 60_000 },
			)
			.toBe('true')
	})
})
