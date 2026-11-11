/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The register OpenAPI download and viewer helpers the register card and the
 * register sidebar share.
 *
 * @spec exclude Unit test of client-side OAS download plumbing.
 */
import axios from '@nextcloud/axios'
import {
	downloadRegisterOas,
	registerOasUrl,
	viewRegisterOas,
} from './registerOas.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))

/**
 * The text of a blob; jsdom's Blob has no text().
 *
 * @param {Blob} blob The blob
 * @return {Promise<string>} Its text
 */
function readBlob(blob) {
	return new Promise((resolve) => {
		const reader = new FileReader()
		reader.onload = () => resolve(reader.result)
		reader.readAsText(blob)
	})
}

const OAS_URL = `${window.location.origin}/index.php/apps/openregister/api/registers/7/oas`

describe('registerOas', () => {
	let clicked
	let revoked

	beforeEach(() => {
		jest.clearAllMocks()
		clicked = []
		revoked = []
		URL.createObjectURL = jest.fn(() => 'blob:oas')
		URL.revokeObjectURL = jest.fn((href) => revoked.push(href))
		jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
			function () {
				clicked.push({
					href: this.getAttribute('href'),
					download: this.download,
					attached: document.body.contains(this),
				})
			},
		)
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('builds the register OAS URL', () => {
		expect(registerOasUrl(7)).toBe(OAS_URL)
	})

	it('downloads the pretty-printed spec as <title>-api-specification.json', async () => {
		axios.get.mockResolvedValue({ data: { openapi: '3.0.0' } })

		await downloadRegisterOas({ id: 7, title: 'Petstore Register' })

		expect(axios.get).toHaveBeenCalledWith(OAS_URL)
		const blob = URL.createObjectURL.mock.calls[0][0]
		expect(blob.type).toBe('application/json')
		expect(await readBlob(blob)).toBe('{\n  "openapi": "3.0.0"\n}')
		expect(clicked).toEqual([
			{
				href: 'blob:oas',
				download: 'petstore register-api-specification.json',
				attached: true,
			},
		])
		expect(document.body.querySelector('a')).toBeNull()
		expect(revoked).toHaveLength(1)
	})

	it('rejects without downloading when the request fails', async () => {
		const failure = new Error('boom')
		axios.get.mockRejectedValue(failure)

		await expect(downloadRegisterOas({ id: 7, title: 'X' })).rejects.toBe(
			failure,
		)
		expect(clicked).toEqual([])
	})

	it('opens the spec in the ReDoc viewer in a new tab', () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)

		viewRegisterOas(7)

		expect(open).toHaveBeenCalledWith(
			`https://redocly.github.io/redoc/?url=${encodeURIComponent(OAS_URL)}`,
			'_blank',
		)
	})
})
