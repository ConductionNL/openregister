import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RECORDS AS A GALLERY — records-gallery-view 2.1 to 2.3.
 *
 * On a schema whose records carry an image, an administrator builds a view
 * through the save-view form: Gallery as the presentation, the title and the
 * cover picked in its field pickers. Saved, the records show as cards with the
 * image as their cover (2.1), a card opens its record (2.2), and the view was
 * built entirely in the editor (2.3).
 *
 * HERMETIC. It creates its own register, schema, records and view and removes them.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import * as zlib from 'zlib'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')
const API = '/index.php/apps/openregister/api'

/** A short unique suffix so a re-run against the same instance never collides. */
const RUN = Math.random().toString(36).slice(2, 10)
const VIEW = `e2e gallery ${RUN}`

test.use({ storageState: STORAGE_STATE, viewport: { width: 1600, height: 1000 } })
test.describe.configure({ mode: 'serial' })

/**
 * A small solid-colour PNG, as a data URI.
 *
 * @param rgb The colour, three bytes.
 */
function png(rgb: [number, number, number]): string {
	const width = 64
	const height = 48
	const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array(width).fill(rgb).flat())])
	const raw = Buffer.concat(Array(height).fill(row))
	const crcTable = Array.from({ length: 256 }, (_, n) => {
		let c = n
		for (let k = 0; k < 8; k++) {
			c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1)
		}
		return c >>> 0
	})
	const crc = (data: Buffer): number => {
		let c = 0xffffffff
		for (const byte of data) {
			c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8)
		}
		return (c ^ 0xffffffff) >>> 0
	}
	const chunk = (type: string, data: Buffer): Buffer => {
		const length = Buffer.alloc(4)
		length.writeUInt32BE(data.length)
		const body = Buffer.concat([Buffer.from(type), data])
		const sum = Buffer.alloc(4)
		sum.writeUInt32BE(crc(body))
		return Buffer.concat([length, body, sum])
	}
	const header = Buffer.alloc(13)
	header.writeUInt32BE(width, 0)
	header.writeUInt32BE(height, 4)
	header.set([8, 2, 0, 0, 0], 8)
	const file = Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
		chunk('IHDR', header),
		chunk('IDAT', zlib.deflateSync(raw)),
		chunk('IEND', Buffer.alloc(0)),
	])

	return `data:image/png;base64,${file.toString('base64')}`
}

test.describe('records as a gallery', () => {
	let admin: APIRequestContext
	let registerId = ''
	let schemaId = ''
	const recordIds: string[] = []
	let viewUuid = ''

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e kleur ${RUN}`,
				description: 'e2e',
				properties: {
					titel: { type: 'string', title: 'Titel' },
					foto: { type: 'file', title: 'Foto', allowedTypes: ['image/png'] },
				},
			},
		})
		expect(schema.ok(), `schema create failed: ${await schema.text()}`).toBeTruthy()
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: { title: `e2e gallery register ${RUN}`, description: 'e2e', schemas: [Number(schemaId)] },
		})
		expect(register.ok(), `register create failed: ${await register.text()}`).toBeTruthy()
		registerId = String((await register.json()).id)

		for (const [titel, rgb] of [['Rood', [204, 51, 51]], ['Blauw', [51, 51, 204]]] as Array<[string, [number, number, number]]>) {
			const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
				data: { titel: `${titel} ${RUN}`, foto: png(rgb) },
			})
			expect(record.ok(), `record create failed: ${await record.text()}`).toBeTruthy()
			recordIds.push(String((await record.json()).id))
		}
	})

	test.afterAll(async () => {
		if (viewUuid !== '') {
			await admin.delete(`${API}/views/${viewUuid}`)
		}
		for (const id of recordIds) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${id}`)
		}
		if (registerId !== '') {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== '') {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await admin.dispose()
	})

	test('a gallery view built in the editor shows its records as cards with covers', async ({ page }) => {
		// Scope the search the way a person does, in the sidebar's pickers.
		await page.goto('/index.php/apps/openregister/tables', { waitUntil: 'domcontentloaded' })
		for (const [id, title] of [['registerSelect', `e2e gallery register ${RUN}`], ['schemaSelect', `e2e kleur ${RUN}`]]) {
			const select = page.locator(`#${id}`).locator('xpath=ancestor-or-self::*[contains(@class,"v-select")][1]').getByRole('combobox').first()
			await expect(select).toBeEnabled({ timeout: 30_000 })
			await select.click()
			await select.fill(title)
			await expect(page.locator('.vs__dropdown-option', { hasText: title }).first()).toBeVisible({ timeout: 15_000 })
			await select.press('Enter')
			await select.press('Escape')
		}
		await expect(page.getByText(`Rood ${RUN}`).first(), 'the records did not list').toBeVisible({ timeout: 30_000 })

		// 2.3: build the view in the save-view form.
		await page.getByRole('button', { name: 'Save current search as view' }).dispatchEvent('click')
		await page.getByRole('textbox', { name: 'View Name' }).fill(VIEW)

		const picker = page.locator('.cn-view-presentation__types')
		await picker.getByText('Gallery', { exact: true }).click()
		const gallery = page.locator('[data-testid="cn-view-presentation-gallery"]')
		await expect(gallery, 'choosing Gallery offers no gallery fields').toBeVisible()

		for (const [testid, field] of [['cn-view-presentation-gallery-title', 'Titel'], ['cn-view-presentation-gallery-cover', 'Foto']]) {
			const select = gallery.locator(`[data-testid="${testid}"]`).getByRole('combobox')
			await select.click()
			await select.fill(field)
			await expect(page.locator('.vs__dropdown-option', { hasText: field }).first()).toBeVisible({ timeout: 10_000 })
			await select.press('Enter')
			await expect(gallery.locator(`[data-testid="${testid}"] .vs__selected`, { hasText: field })).toBeVisible()
		}

		const saved = page.waitForResponse(
			(response) => response.request().method() === 'POST'
				&& new URL(response.url()).pathname.endsWith('/apps/openregister/api/views'),
			{ timeout: 15_000 },
		)
		await page.locator('.saveViewForm').getByRole('button', { name: 'Save', exact: true }).dispatchEvent('click')
		const response = await saved
		expect(response.status(), `the view was not saved: ${(await response.text()).slice(0, 300)}`).toBeLessThan(300)
		const body = await response.json()
		const view = body.view ?? body
		viewUuid = String(view.uuid ?? view.id)
		expect(view.presentation?.viewType).toBe('gallery')
		expect(view.presentation?.gallery?.titleField).toBe('titel')
		expect(view.presentation?.gallery?.coverField).toBe('foto')

		// 2.1: the records are cards, each with its image as the cover.
		const cards = page.locator('.cn-object-card')
		await expect(cards, 'the gallery does not show the two records as cards').toHaveCount(2, { timeout: 30_000 })
		const rood = cards.filter({ hasText: `Rood ${RUN}` })
		const cover = rood.locator('img').first()
		await expect(cover, 'the card has no cover image').toBeVisible()
		// The record's own preview route: a file attached to a record lives in
		// OpenRegister's storage, where Nextcloud's preview service answers 404.
		const coverSrc = String(await cover.getAttribute('src'))
		expect(coverSrc).toContain(`/api/objects/${registerId}/${schemaId}/`)
		const coverResponse = await page.request.get(coverSrc)
		expect(coverResponse.status(), 'the cover thumbnail is not served').toBe(200)
		expect(coverResponse.headers()['content-type']).toContain('image/')
		await expect
			.poll(async () => await cover.evaluate((img) => (img as HTMLImageElement).naturalWidth), {
				message: 'the cover image did not load',
				timeout: 15_000,
			})
			.toBeGreaterThan(0)

		// 2.2: a card opens its record, in the same dialog a table row opens,
		// and the record is the record: not the card with its display cover.
		await rood.locator('.cn-object-card__title, h3, h4').first().click()
		const dialog = page.getByRole('dialog').filter({ hasText: `Rood ${RUN}` })
		await expect(dialog, 'the card did not open its record').toBeVisible({ timeout: 15_000 })
		await expect(dialog.getByText('Titel', { exact: true })).toBeVisible()
		await expect(dialog, 'the display cover leaked into the record').not.toContainText('_galleryCover')
	})
})
