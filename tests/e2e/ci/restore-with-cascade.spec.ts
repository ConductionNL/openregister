import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A RESTORE BRINGS BACK WHAT THE DELETE TOOK, AND NOTHING SOMEONE CHANGED SINCE.
 *
 * Change `records-restore-with-cascade`, task 3.2:
 *
 * @e2e deletion::restore-with-related-records
 * @e2e deletion::a-restore-never-overwrites-a-field-changed-since
 *
 * A lead with two activities that cascade on its delete and two contacts whose
 * `lead` is set to null on it. The lead is deleted: the activities go with it
 * and both contacts lose their reference. Someone then points the second
 * contact at another lead. On the Deleted page the steward chooses "Restore
 * with related records": the preview lists the two activities to restore, the
 * first contact's reference to put back and the second contact as changed
 * since the delete. After the restore the activities and the lead read back,
 * the first contact names the lead again, and the second contact still names
 * the lead it was pointed at, untouched.
 *
 * Every outcome is read from the object API, not from the restore's own
 * answer.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, three schemas and
 * six objects, and removes all of them. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseUrl } from '../base-url.ts'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

test.use(fs.existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {})

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** A short unique suffix so repeated runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'
const APP = '/index.php/apps/openregister'
const LEAD_NAME = `Lead to restore ${RUN}`

/** The uuid of an object as the object API answers it. */
function uuidOf(body: Record<string, any>): string {
	return String(body['@self']?.id ?? body.id ?? body.uuid)
}

/**
 * Dismiss the first-run setup wizard if it is open: its modal wrapper takes the
 * pointer events of everything under it.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}

	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('restore with related records', () => {
	test.describe.configure({ mode: 'serial' })

	let admin: APIRequestContext
	let registerId = ''
	const schemas = { lead: '', activity: '', contact: '' }
	let lead = ''
	let otherLead = ''
	const activities: string[] = []
	let untouched = ''
	let edited = ''

	/** Create one object and answer its uuid. */
	async function create(schemaId: string, data: Record<string, unknown>): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, { data })
		expect(res.status(), await res.text()).toBeLessThan(300)
		return uuidOf(await res.json())
	}

	/** One object as the object API answers it now: its status and body. */
	async function read(schemaId: string, uuid: string): Promise<{ status: number, body: Record<string, any> }> {
		const res = await admin.get(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
		const status = res.status()
		return { status, body: status === 200 ? await res.json() : {} }
	}

	/** Create a schema and answer its id. */
	async function schema(data: Record<string, unknown>): Promise<string> {
		const res = await admin.post(`${API}/schemas`, { data })
		expect(res.status(), await res.text()).toBeLessThan(300)
		return String((await res.json()).id)
	}

	test.beforeAll(async () => {
		admin = await pwRequest.newContext({
			baseURL: BASE,
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${ADMIN}:${ADMIN_PASS}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		})

		schemas.lead = await schema({
			title: `Lead ${RUN}`,
			slug: `restore-lead-${RUN}`,
			properties: { name: { type: 'string' } },
		})
		schemas.activity = await schema({
			title: `Activity ${RUN}`,
			slug: `restore-activity-${RUN}`,
			properties: {
				subject: { type: 'string' },
				lead: { type: 'string', $ref: schemas.lead, onDelete: 'CASCADE' },
			},
		})
		schemas.contact = await schema({
			title: `Contact ${RUN}`,
			slug: `restore-contact-${RUN}`,
			properties: {
				name: { type: 'string' },
				lead: { type: 'string', $ref: schemas.lead, onDelete: 'SET_NULL' },
			},
		})

		const register = await admin.post(`${API}/registers`, {
			data: {
				title: `Restore with cascade ${RUN}`,
				slug: `restore-cascade-${RUN}`,
				schemas: [schemas.lead, schemas.activity, schemas.contact].map(Number),
			},
		})
		expect(register.status(), await register.text()).toBeLessThan(300)
		registerId = String((await register.json()).id)

		lead = await create(schemas.lead, { name: LEAD_NAME })
		otherLead = await create(schemas.lead, { name: `Other lead ${RUN}` })
		activities.push(await create(schemas.activity, { subject: `Call ${RUN}`, lead }))
		activities.push(await create(schemas.activity, { subject: `Visit ${RUN}`, lead }))
		untouched = await create(schemas.contact, { name: `Anna ${RUN}`, lead })
		edited = await create(schemas.contact, { name: `Bram ${RUN}`, lead })
	})

	test.afterAll(async () => {
		if (!admin) {
			return
		}

		const objects: Array<[string, string]> = [
			...activities.map((uuid): [string, string] => [schemas.activity, uuid]),
			[schemas.contact, untouched],
			[schemas.contact, edited],
			[schemas.lead, lead],
			[schemas.lead, otherLead],
		]
		for (const [schemaId, uuid] of objects) {
			if (uuid) {
				await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			}
		}
		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
		for (const schemaId of [schemas.contact, schemas.activity, schemas.lead]) {
			if (schemaId) {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}
		await admin.dispose()
	})

	test('deleting the lead takes its activities and unlinks its contacts', async () => {
		const res = await admin.delete(`${API}/objects/${registerId}/${schemas.lead}/${lead}`)
		expect(res.status(), await res.text()).toBeLessThan(300)

		for (const uuid of activities) {
			expect((await read(schemas.activity, uuid)).status).toBe(404)
		}
		for (const uuid of [untouched, edited]) {
			const contact = await read(schemas.contact, uuid)
			expect(contact.status).toBe(200)
			expect(contact.body.lead ?? null).toBeNull()
		}
	})

	test('someone points the second contact at another lead after the delete', async () => {
		const res = await admin.put(`${API}/objects/${registerId}/${schemas.contact}/${edited}`, {
			data: { name: `Bram ${RUN}`, lead: otherLead },
		})
		expect(res.status(), await res.text()).toBe(200)
		expect((await read(schemas.contact, edited)).body.lead).toBe(otherLead)
	})

	test('the preview lists what comes back, and the restore leaves the changed contact alone', async ({ page }) => {
		await page.goto(`${APP}/deleted`, { waitUntil: 'domcontentloaded' })
		await dismissSetupWizard(page)

		const row = page.locator('tr').filter({ hasText: LEAD_NAME })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		const dialog = page.getByRole('dialog', { name: 'Restore with related records' })
		// The list loads its registers and schemas after the rows and redraws, which closes an open row menu; open
		// it again until the choice lands.
		await expect(async () => {
			await row.getByRole('button', { name: 'Actions' }).click()
			await page.getByRole('menuitem', { name: 'Restore with related records' }).click({ timeout: 3_000 })
			await expect(dialog).toBeVisible({ timeout: 3_000 })
		}).toPass({ timeout: 30_000 })
		await expect(dialog.getByRole('heading', { name: 'Will be restored (2)' })).toBeVisible({ timeout: 30_000 })
		await expect(dialog.getByRole('heading', { name: 'References put back (1)' })).toBeVisible()
		await expect(dialog.getByRole('heading', { name: 'Changed since the delete (1)' })).toBeVisible()

		await dialog.getByRole('button', { name: 'Restore', exact: true }).click()
		await expect(dialog).toBeHidden({ timeout: 30_000 })

		// Read back: the lead and both activities are there again.
		expect((await read(schemas.lead, lead)).status).toBe(200)
		for (const uuid of activities) {
			const activity = await read(schemas.activity, uuid)
			expect(activity.status).toBe(200)
			expect(activity.body.lead).toBe(lead)
		}
		// The untouched contact names the lead again; the edited one keeps what it was given.
		expect((await read(schemas.contact, untouched)).body.lead).toBe(lead)
		expect((await read(schemas.contact, edited)).body.lead).toBe(otherLead)
	})
})
