import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A REGISTER'S MODEL AS A DIAGRAM — modelling-schema-diagram 2.5, the Verify
 * lines of 2.1 to 2.4 on a running instance.
 *
 * A register with two schemas, one of which links to the other, is opened on
 * its detail page and switched to the Diagram view:
 *
 *   2.1  the link is drawn and listed, and following a schema opens it
 *   2.2  a box moved by hand is where it was left after a reload
 *   2.3  the table of the same links passes an axe check
 *   2.4  the download is an SVG document
 *
 * HERMETIC. It creates its own register and schemas and removes them; the
 * per-user layout it writes is keyed by that register and goes with it.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)
const PERSON = `e2e person ${RUN}`
const CASE = `e2e case ${RUN}`

test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 1000 } })
test.describe.configure({ mode: 'serial' })

/**
 * Open the register and switch it to the Diagram view.
 *
 * @param page The page.
 * @param registerId The register.
 */
async function openDiagram(page: Page, registerId: string): Promise<void> {
	await page.goto(`/index.php/apps/openregister/registers/${registerId}`, { waitUntil: 'domcontentloaded' })
	const diagramButton = page.getByRole('button', { name: 'Diagram', exact: true })
	await expect(diagramButton).toBeVisible({ timeout: 30_000 })
	await diagramButton.dispatchEvent('click')
	await expect(page.locator('[data-testid="register-model-table"]')).toBeVisible({ timeout: 30_000 })
}

test.describe('a register model as a diagram', () => {
	let admin: APIRequestContext
	let registerId = ''
	let personId = ''
	let caseId = ''

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const person = await admin.post(`${API}/schemas`, {
			data: { title: PERSON, description: 'e2e', properties: { naam: { type: 'string', title: 'Naam' } } },
		})
		expect(person.ok(), `schema create failed: ${await person.text()}`).toBeTruthy()
		personId = String((await person.json()).id)

		const zaak = await admin.post(`${API}/schemas`, {
			data: {
				title: CASE,
				description: 'e2e',
				properties: {
					onderwerp: { type: 'string', title: 'Onderwerp' },
					aanvrager: { type: 'object', title: 'Aanvrager', $ref: personId },
				},
			},
		})
		expect(zaak.ok(), `schema create failed: ${await zaak.text()}`).toBeTruthy()
		caseId = String((await zaak.json()).id)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e model ${RUN}`, description: 'e2e', schemas: [Number(personId), Number(caseId)] },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)
	})

	test.afterAll(async () => {
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		for (const id of [caseId, personId]) {
			if (id !== '') {
				await admin.delete(`${API}/schemas/${id}`)
			}
		}
		await admin.dispose()
	})

	test('the link is drawn and listed, and the table passes axe', async ({ page }) => {
		await openDiagram(page, registerId)

		// 2.1: both schemas are boxes and the link is a line.
		const canvas = page.locator('[data-testid="register-model-diagram"]')
		await expect(canvas.locator('.vue-flow__node', { hasText: PERSON })).toBeVisible()
		await expect(canvas.locator('.vue-flow__node', { hasText: CASE })).toBeVisible()
		await expect(canvas.locator('.vue-flow__edge')).toHaveCount(1)

		// The table lists the same link: case.aanvrager -> person.
		const table = page.locator('[data-testid="register-model-table"]')
		const row = table.locator('tbody tr', { hasText: 'aanvrager' })
		await expect(row).toContainText(CASE)
		await expect(row).toContainText(PERSON)

		// 2.3: the keyboard-reachable rendering passes an automated accessibility check.
		await page.addScriptTag({ path: require.resolve('axe-core') })
		const violations = await page.evaluate(async () => {
			const result = await (window as unknown as { axe: { run: (context: string) => Promise<{ violations: Array<{ id: string, nodes: unknown[] }> }> } })
				.axe.run('[data-testid="register-model-table"]')
			return result.violations.map((violation) => `${violation.id} (${violation.nodes.length})`)
		})
		expect(violations, 'the model table has accessibility violations').toEqual([])

		// 2.1: following the linked schema opens it.
		await row.getByRole('link', { name: PERSON }).click()
		await expect(page).toHaveURL(new RegExp(`/schemas/${personId}$`))
	})

	test('a moved box stays where it was left after a reload', async ({ page }) => {
		await openDiagram(page, registerId)

		const box = page.locator('[data-testid="register-model-diagram"] .vue-flow__node', { hasText: PERSON })
		await expect(box).toBeVisible()
		// The diagram sits below the register's charts; a drag needs it on screen.
		await page.locator('[data-testid="register-model-diagram"] .vue-flow').scrollIntoViewIfNeeded()
		const before = await box.boundingBox()
		expect(before).not.toBeNull()

		const saved = page.waitForResponse(
			(response) => ['PUT', 'POST'].includes(response.request().method())
				&& response.url().includes(`/api/preferences/register-model-layout-${registerId}`),
			{ timeout: 15_000 },
		)
		const startX = (before?.x ?? 0) + 20
		const startY = (before?.y ?? 0) + 15
		await page.mouse.move(startX, startY)
		await page.mouse.down()
		await page.mouse.move(startX + 60, startY + 90, { steps: 12 })
		await page.mouse.move(startX + 120, startY + 180, { steps: 12 })
		await page.mouse.up()
		expect((await saved).status(), 'the moved position was not saved').toBeLessThan(300)

		const moved = await box.boundingBox()
		expect(Math.abs((moved?.y ?? 0) - (before?.y ?? 0)), 'the box did not move').toBeGreaterThan(50)

		// Measured relative to the other box, so a different fit after the
		// reload does not read as a lost position.
		const other = page.locator('[data-testid="register-model-diagram"] .vue-flow__node', { hasText: CASE })
		const gapBefore = (moved?.y ?? 0) - ((await other.boundingBox())?.y ?? 0)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await openDiagram(page, registerId)
		await expect(box).toBeVisible()
		await page.locator('[data-testid="register-model-diagram"] .vue-flow').scrollIntoViewIfNeeded()
		const after = await box.boundingBox()
		const gapAfter = (after?.y ?? 0) - ((await other.boundingBox())?.y ?? 0)
		expect(Math.sign(gapAfter), 'the moved box is not on the side it was left').toBe(Math.sign(gapBefore))
		expect(Math.abs(gapAfter - gapBefore), 'the moved box is not where it was left').toBeLessThan(Math.abs(gapBefore) * 0.25 + 10)
	})

	test('the download is an SVG of the model', async ({ page }) => {
		await openDiagram(page, registerId)

		const download = page.waitForEvent('download')
		await page.locator('[data-testid="register-model-download"]').dispatchEvent('click')
		const file = await download
		expect(file.suggestedFilename()).toBe(`register-${registerId}-model.svg`)

		const content = fs.readFileSync(String(await file.path()), 'utf8')
		expect(content.trimStart().startsWith('<svg'), 'the download does not start with <svg').toBe(true)
		expect(content, 'the SVG draws both schemas').toContain(PERSON)
		expect(content).toContain(CASE)
	})
})
