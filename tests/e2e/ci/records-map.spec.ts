import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AN AREA DRAWN ON THE RECORDS MAP NARROWS THE LIST TO THE RECORDS INSIDE IT.
 *
 * geometry-on-a-map task 2.5 (REQ-GEOMAP-002): records with a point in two
 * neighbourhoods, Jordaan in Amsterdam and Wittevrouwen in Utrecht. On
 * `/tables` the list offers "Show on map"; the map lists all four locations.
 * A polygon drawn around the Amsterdam markers leaves only the two Jordaan
 * records in the list beside the map, and clearing the area brings all four
 * back. The polygon is drawn with the map's own draw control, around the
 * markers where Leaflet placed them, so the test does not depend on the
 * map's zoom.
 *
 * HERMETIC: it creates its register, schema and records and removes them.
 *
 * @e2e geo-metadata-kaart::a-user-finds-the-records-inside-an-area
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'
const STORAGE_STATE = path.resolve(__dirname, '..', '.auth', 'admin.json')

const API = '/index.php/apps/openregister/api'
const RUN = Math.random().toString(36).slice(2, 10)

/** Two records in each neighbourhood, as [name, longitude, latitude]. */
const RECORDS: Array<[string, number, number]> = [
	['Jordaan Bloemgracht', 4.8800, 52.3750],
	['Jordaan Westerstraat', 4.8840, 52.3770],
	['Wittevrouwen Biltstraat', 5.1300, 52.0950],
	['Wittevrouwen Nachtegaalstraat', 5.1340, 52.0970],
]

/**
 * An API context signed in as the administrator, with no session cookie.
 */
async function adminContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/**
 * The coordinates in the locations list beside the map, as "lat, lon" with six decimals.
 */
async function listedCoordinates(page: Page): Promise<string[]> {
	return (await page.getByTestId('map-location').locator('.or-map-view__coords').allTextContents()).map((text) => text.trim()).sort()
}

/**
 * How a record's point reads in that list.
 */
function coordinatesOf(lon: number, lat: number): string {
	return `${lat.toFixed(6)}, ${lon.toFixed(6)}`
}

test.describe('the records map narrows the list to a drawn area', () => {
	test.use({ storageState: STORAGE_STATE, viewport: { width: 1440, height: 1100 } })

	let admin: APIRequestContext
	let registerId: string
	let schemaId: string
	const uuids: string[] = []

	test.beforeAll(async () => {
		admin = await adminContext()

		const schema = await admin.post(`${API}/schemas`, {
			data: {
				title: `Records map ${RUN}`,
				slug: `recordsmap${RUN}`,
				properties: {
					naam: { type: 'string', title: 'Naam' },
					locatie: { type: 'object', format: 'geo:point', title: 'Locatie' },
				},
				configuration: { objectNameField: 'naam' },
			},
		})
		expect(schema.ok(), await schema.text()).toBe(true)
		schemaId = String((await schema.json()).id)

		const register = await admin.post(`${API}/registers`, {
			data: { title: `Records map ${RUN}`, slug: `recordsmapregister${RUN}`, schemas: [Number(schemaId)] },
		})
		expect(register.ok(), await register.text()).toBe(true)
		registerId = String((await register.json()).id)

		for (const [naam, lon, lat] of RECORDS) {
			const record = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
				data: { naam, locatie: { type: 'Point', coordinates: [lon, lat] } },
			})
			expect(record.status(), await record.text()).toBe(201)
			uuids.push((await record.json())['@self'].id)
		}
	})

	test.afterAll(async () => {
		for (const uuid of uuids) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}
		if (registerId !== undefined) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		if (schemaId !== undefined) {
			await admin.delete(`${API}/schemas/${schemaId}`)
		}
		await admin.dispose()
	})

	test('a polygon drawn around one neighbourhood leaves only its records', async ({ page }) => {
		await page.goto(`/index.php/apps/openregister/tables?register=${registerId}&schema=${schemaId}`)

		await page.getByTestId('search-open-map').click({ timeout: 30_000 })
		await expect(page.getByTestId('search-map')).toBeVisible()
		await expect(page.getByTestId('map-location')).toHaveCount(4, { timeout: 30_000 })

		// Pick the polygon tool first, then measure: the map must not move between
		// measuring the markers and pressing on the screen.
		const map = page.locator('.or-map-view__map')
		await map.scrollIntoViewIfNeeded()
		await page.locator('.leaflet-draw-draw-polygon').click()
		const frame = (await map.boundingBox())!

		// The markers Leaflet drew, as screen boxes. The map fits all four, so
		// Amsterdam's pair sits above Utrecht's: Amsterdam is mainly north of it.
		const markers = page.locator('.or-map-view__map path.leaflet-interactive')
		await expect(markers).toHaveCount(4)
		const boxes = []
		for (let index = 0; index < 4; index++) {
			const box = await markers.nth(index).boundingBox()
			expect(box).not.toBeNull()
			boxes.push(box!)
		}
		boxes.sort((a, b) => a.y - b.y)
		const north = boxes.slice(0, 2)
		const margin = 18
		const inside = (value: number, low: number, high: number) => Math.min(Math.max(value, low + 4), high - 4)
		const left = inside(Math.min(...north.map((box) => box.x)) - margin, frame.x, frame.x + frame.width)
		const right = inside(Math.max(...north.map((box) => box.x + box.width)) + margin, frame.x, frame.x + frame.width)
		const top = inside(Math.min(...north.map((box) => box.y)) - margin, frame.y, frame.y + frame.height)
		const bottom = inside(Math.max(...north.map((box) => box.y + box.height)) + margin, frame.y, frame.y + frame.height)
		expect(bottom, 'the two neighbourhoods are drawn apart').toBeLessThan(boxes[2].y)

		// Leaflet.draw places a vertex on a press and release after a move, and
		// the toolbar's Finish closes the shape.
		// The first move into the map lets the tool track the pointer; a press
		// before it is not a vertex.
		await page.mouse.move(frame.x + frame.width / 2, frame.y + frame.height / 2, { steps: 4 })
		const corners: Array<[number, number]> = [[left, top], [right, top], [right, bottom], [left, bottom]]
		for (const [x, y] of corners) {
			await page.mouse.move(x, y, { steps: 6 })
			await page.mouse.down()
			await page.mouse.up()
			await page.waitForTimeout(150)
		}
		await page.locator('.leaflet-draw-actions a', { hasText: 'Finish' }).click()

		await expect(page.getByTestId('map-clear-area')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.or-map-view__count')).toContainText('2', { timeout: 30_000 })
		await expect(page.getByTestId('map-location')).toHaveCount(2, { timeout: 30_000 })
		expect(await listedCoordinates(page)).toEqual(
			RECORDS.filter(([naam]) => naam.startsWith('Jordaan')).map(([, lon, lat]) => coordinatesOf(lon, lat)).sort(),
		)

		// Clearing the area shows every record again.
		await page.getByTestId('map-clear-area').click()
		await expect(page.getByTestId('map-location')).toHaveCount(4, { timeout: 30_000 })
	})
})
