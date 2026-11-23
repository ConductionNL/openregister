import axios from '@nextcloud/axios'
import ExportSection from './ExportSection.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'

/**
 * The personal data export button: the request it makes, the file it hands
 * the browser, and the status line for a download, a rate limit and any
 * other failure.
 *
 * @spec exclude UI plumbing — client-side export download of the account page.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true, NcButton: {} }))
jest.mock('../../../components/shared/SectionStatus.vue', () => ({ __esModule: true, default: {} }))

let answer
let events

/**
 * Run the export on a bare section state carrying the section's methods.
 *
 * @return {Promise<object>} The section state and what happened during the request
 */
async function exportData() {
	const section = { loading: false, message: 'old', isError: false }
	const methods = { ...sectionStatus.methods, ...ExportSection.methods }
	for (const [name, method] of Object.entries(methods)) {
		section[name] = method.bind(section)
	}
	axios.get.mockImplementation((...args) => {
		section.duringRequest = { loading: section.loading, message: section.message, args }
		return answer()
	})
	await section.exportData()
	for (const name of Object.keys(methods)) {
		delete section[name]
	}
	return section
}


describe('ExportSection.exportData', () => {
	beforeEach(() => {
		jest.useFakeTimers({ now: new Date('2026-03-04T23:30:00Z') })
		axios.get.mockReset()
		events = []
		window.URL.createObjectURL = jest.fn((blob) => {
			events.push(['createObjectURL', blob instanceof Blob, blob.size])
			return 'blob:export'
		})
		window.URL.revokeObjectURL = jest.fn((url) => events.push(['revokeObjectURL', url]))
		jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
			events.push(['click', this.href, this.getAttribute('download'), document.body.contains(this)])
		})
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	it('hands the browser the export as a dated JSON file', async () => {
		answer = () => Promise.resolve({ data: '{"a":1}' })
		const section = await exportData()

		expect(section).toEqual({
			loading: false,
			message: 't:Export downloaded successfully',
			isError: false,
			duringRequest: {
				loading: true,
				message: '',
				args: ['/gen/apps/openregister/api/user/me/export', { responseType: 'blob' }],
			},
		})
		expect(events).toEqual([
			['createObjectURL', true, 7],
			['click', 'blob:export', 'openregister-export-2026-03-04.json', true],
			['revokeObjectURL', 'blob:export'],
		])
		expect(document.body.querySelector('a')).toBeNull()
	})

	it.each([
		['a rate limit', { response: { status: 429 } }, 't:Export is rate limited. Please try again later.'],
		['a server error', { response: { status: 500 } }, 't:Failed to export data'],
		['a network failure', new Error('offline'), 't:Failed to export data'],
	])('reports %s', async (label, failure, message) => {
		answer = () => Promise.reject(failure)
		const section = await exportData()

		expect(section).toMatchObject({ loading: false, message, isError: true })
		expect(events).toEqual([])
	})
})
