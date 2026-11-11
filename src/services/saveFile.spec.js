/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saving content as a file through a temporary download link.
 *
 * @spec exclude Unit test of a browser download helper.
 */
import { saveBlob, saveJson } from './saveFile.js'

describe('saveFile', () => {
	let events

	beforeEach(() => {
		events = []
		URL.createObjectURL = jest.fn((blob) => {
			events.push(['url', blob.type, blob.size])
			return 'blob:saved'
		})
		URL.revokeObjectURL = jest.fn((url) => events.push(['revoke', url]))
		jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
			events.push(['click', this.href, this.download, this.isConnected])
		})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('clicks an attached link to the blob, then removes it and frees the url', () => {
		saveBlob(new Blob(['abc'], { type: 'text/csv' }), 'export.csv')

		expect(events).toEqual([
			['url', 'text/csv', 3],
			['click', 'blob:saved', 'export.csv', true],
			['revoke', 'blob:saved'],
		])
		expect(document.querySelector('a')).toBeNull()
	})

	it('saves data as pretty-printed JSON', () => {
		saveJson({ a: 1 }, 'data.json')

		expect(events[0]).toEqual(['url', 'application/json', JSON.stringify({ a: 1 }, null, 2).length])
		expect(events[1]).toEqual(['click', 'blob:saved', 'data.json', true])
	})
})
