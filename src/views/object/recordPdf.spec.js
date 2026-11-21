/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */

import { downloadRecordPdf, recordPdfUrl } from './recordPdf.js'

const ADDRESS = { register: 'zaken', schema: 'zaak', id: 'uuid/42' }

describe('record PDF export', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok' }
	})

	it('addresses the record PDF endpoint, escaping each part', () => {
		expect(recordPdfUrl(ADDRESS)).toBe('/index.php/apps/openregister/api/objects/zaken/zaak/uuid%2F42/pdf')
	})

	it('saves the PDF under the name the server gave', async () => {
		const blob = { size: 3 }
		const fetchFn = jest.fn(async () => ({
			ok: true,
			blob: async () => blob,
			headers: { get: (name) => (name === 'Content-Disposition' ? 'attachment; filename="Z-2026-0042.pdf"' : null) },
		}))
		const saveFn = jest.fn()

		await expect(downloadRecordPdf(ADDRESS, fetchFn, saveFn)).resolves.toEqual({ ok: true, message: null })

		expect(fetchFn).toHaveBeenCalledWith(recordPdfUrl(ADDRESS), { headers: { requesttoken: 'tok' } })
		expect(saveFn).toHaveBeenCalledWith(blob, 'Z-2026-0042.pdf')
	})

	it('falls back to the record id for the file name', async () => {
		const saveFn = jest.fn()
		await downloadRecordPdf(ADDRESS, async () => ({ ok: true, blob: async () => ({}), headers: { get: () => null } }), saveFn)
		expect(saveFn).toHaveBeenCalledWith({}, 'uuid/42.pdf')
	})

	it('answers a refusal with the server message and saves nothing', async () => {
		const saveFn = jest.fn()
		const refused = async () => ({ ok: false, status: 403, json: async () => ({ message: 'You may not export this schema.' }) })
		const bare = async () => ({ ok: false, status: 501, json: async () => { throw new Error('not json') } })

		await expect(downloadRecordPdf(ADDRESS, refused, saveFn)).resolves.toEqual({ ok: false, message: 'You may not export this schema.' })
		await expect(downloadRecordPdf(ADDRESS, bare, saveFn)).resolves.toEqual({ ok: false, message: 'HTTP 501' })
		await expect(downloadRecordPdf(ADDRESS, async () => { throw new Error('offline') }, saveFn)).resolves.toEqual({ ok: false, message: 'offline' })
		expect(saveFn).not.toHaveBeenCalled()
	})
})
