/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A DUTCH LICENCE PLATE IS FOUND, A CASE NUMBER IS NOT TAKEN FOR ONE, AND THE COPY SAYS KENTEKEN.
 *
 * Change `detection-dutch-licence-plates`, task 4.1. With entity recognition on,
 * the regex method and the `nl` pattern set enabled, a text file holding a
 * plate (sidecode 7), a BSN that passes the elfproef, a nine-digit number that
 * does not, and a case number shaped like a plate is extracted. Then:
 *   - `GET /api/entities` lists the plate as `LICENSE_PLATE` and the BSN as `SSN`;
 *   - the case number is no `LICENSE_PLATE`, and the number that fails the
 *     elfproef is no `SSN`;
 *   - for a Dutch reader the anonymised copy holds `[KENTEKEN: n]` and `[BSN: n]`,
 *     and neither the plate nor the BSN appears in it.
 *
 * The placeholder shape is `[<LABEL>: <n>]`, the one every anonymisation spec
 * pins (`openspec/specs/pdf-anonymisation`, `entity-relation-grondslagen`).
 *
 * HERMETIC BY CONSTRUCTION. It sets the three file settings and the
 * administrator's language, uploads its own file, and puts every setting back
 * and deletes both files in afterAll. The plate's digits are drawn per run, so
 * an entity from an earlier run cannot satisfy the assertions.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

const RUN = Math.random().toString(36).slice(2, 10)
const API = '/index.php/apps/openregister/api'
const digit = (): number => Math.floor(Math.random() * 10)
// Sidecode 7: two digits, three consonants, one digit.
const PLATE = `${digit()}${digit()}-GBK-${digit()}`
const BSN = '111222333'
const NOT_A_BSN = '123456789'
const CASE_NUMBER = 'ZK-12-AB-34'
const FILE = `e2e-plate-${RUN}.txt`
const CONTENT = `Het voertuig met kenteken ${PLATE} stond geparkeerd. BSN ${BSN}, nummer ${NOT_A_BSN}, zaak ${CASE_NUMBER}.\n`
const SETTINGS = { entityRecognitionEnabled: true, entityRecognitionMethod: 'regex', entityPatternSets: ['nl'] }

test.describe.configure({ mode: 'serial' })

test.describe('detection-dutch-licence-plates: a plate in a document', () => {
	let admin: APIRequestContext
	let settingsBefore: Record<string, unknown> | null = null
	let languageBefore: string | null = null
	let fileId = 0
	let anonymisedPath = ''

	/** The administrator's account settings over OCS. */
	async function setLanguage(language: string): Promise<void> {
		const res = await admin.put(`/ocs/v1.php/cloud/users/${ADMIN}?format=json`, { data: { key: 'language', value: language } })
		expect(res.status(), await res.text()).toBe(200)
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

		const settings = await admin.get(`${API}/settings/files`)
		expect(settings.status(), await settings.text()).toBe(200)
		const current = await settings.json()
		settingsBefore = Object.fromEntries(Object.keys(SETTINGS).map((key) => [key, current[key]]))
		const changed = await admin.patch(`${API}/settings/files`, { data: SETTINGS })
		expect(changed.status(), await changed.text()).toBe(200)

		const user = await admin.get(`/ocs/v1.php/cloud/users/${ADMIN}?format=json`)
		languageBefore = String((await user.json()).ocs.data.language ?? '')
		await setLanguage('nl')

		const put = await admin.fetch(`/remote.php/dav/files/${ADMIN}/${FILE}`, { method: 'PUT', data: CONTENT, headers: { 'Content-Type': 'text/plain' } })
		expect(put.status(), 'upload the file').toBe(201)
		const props = await admin.fetch(`/remote.php/dav/files/${ADMIN}/${FILE}`, {
			method: 'PROPFIND',
			headers: { Depth: '0', 'Content-Type': 'application/xml' },
			data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
		})
		fileId = Number(/<oc:fileid>(\d+)<\/oc:fileid>/.exec(await props.text())?.[1] ?? 0)
		expect(fileId, 'the file has a file id').toBeGreaterThan(0)
	})

	test.afterAll(async () => {
		for (const path of [`/remote.php/dav/files/${ADMIN}/${FILE}`, anonymisedPath && `/remote.php/dav${anonymisedPath.replace(`/${ADMIN}/files/`, `/files/${ADMIN}/`)}`]) {
			if (path) {
				await admin.fetch(path, { method: 'DELETE' })
			}
		}
		if (settingsBefore !== null) {
			await admin.patch(`${API}/settings/files`, { data: settingsBefore })
		}
		if (languageBefore !== null) {
			await setLanguage(languageBefore === '' ? 'en' : languageBefore)
		}
		await admin.dispose()
	})

	// @e2e pii-entity-detection::a-plate-in-a-document-becomes-a-licence-plate-entity
	// @e2e pii-entity-detection::a-bsn-is-found-only-when-it-passes-the-elfproef
	test('extraction finds the plate and the BSN, and takes neither the case number nor a failing number for one', async () => {
		const extracted = await admin.post(`${API}/files/${fileId}/extract`)
		expect(extracted.status(), await extracted.text()).toBe(200)

		const res = await admin.get(`${API}/entities?_limit=1000`)
		expect(res.status(), await res.text()).toBe(200)
		const entities = (await res.json()).data as Array<{ type: string, value: string }>
		const typed = (type: string): string[] => entities.filter((entity) => entity.type === type).map((entity) => entity.value)

		expect(typed('LICENSE_PLATE')).toContain(PLATE)
		expect(typed('SSN')).toContain(BSN)
		expect(typed('LICENSE_PLATE').filter((value) => CASE_NUMBER.includes(value)), 'the case number is no plate').toEqual([])
		expect(typed('SSN'), 'a number that fails the elfproef is no BSN').not.toContain(NOT_A_BSN)
	})

	// @e2e pii-entity-detection::the-anonymised-copy-shows-a-dutch-placeholder
	test('the anonymised copy shows Dutch placeholders and neither the plate nor the BSN', async () => {
		const res = await admin.post(`${API}/files/${fileId}/anonymize`, { data: {} })
		expect(res.status(), await res.text()).toBe(200)
		anonymisedPath = String((await res.json()).anonymized_path ?? '')
		expect(anonymisedPath, 'the answer names the copy').not.toBe('')

		const copy = await admin.fetch(`/remote.php/dav${anonymisedPath.replace(`/${ADMIN}/files/`, `/files/${ADMIN}/`)}`)
		expect(copy.status(), 'read the anonymised copy').toBe(200)
		const text = await copy.text()

		expect(text).toMatch(/kenteken \[KENTEKEN: \d+\] stond/)
		expect(text).toMatch(/BSN \[BSN: \d+\]/)
		expect(text).not.toContain(PLATE)
		expect(text).not.toContain(BSN)
	})
})
