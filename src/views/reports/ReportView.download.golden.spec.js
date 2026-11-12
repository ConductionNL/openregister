import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import ReportView from './ReportView.vue'

/**
 * Golden record of the report download: the render request, the file name
 * it hands the browser, and the message it shows for each failure body.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnChartWidget: {}, CnDataTable: {} }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn(), get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/AccountGroupOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ChartBoxOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ChevronLeft.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FileDelimitedOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FilePdfBox.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/MicrosoftExcel.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Printer.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../store/store.js', () => ({ __esModule: true, reportsStore: {} }))

let events

/**
 * Download in a format from a bare page carrying the page's methods.
 *
 * @param {object} state The page data
 * @return {Promise<void>}
 */
async function downloadAs(state) {
	const page = { $route: { params: { id: 'r 1' } }, dashboard: { titel: 'Sales' }, ...state }
	for (const [name, method] of Object.entries(ReportView.methods)) {
		page[name] = method.bind(page)
	}
	await page.downloadAs('pdf')
}

/**
 * A failed blob request whose body is the given text.
 *
 * @param {string} text The body
 * @return {object} The failure
 */
function failureWithBody(text) {
	return { response: { data: { text: () => Promise.resolve(text) } } }
}

beforeEach(() => {
	jest.clearAllMocks()
	events = []
	URL.createObjectURL = jest.fn((blob) => {
		events.push(['createObjectURL', blob])
		return 'blob:report'
	})
	URL.revokeObjectURL = jest.fn((url) => events.push(['revokeObjectURL', url]))
	jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
		events.push(['click', this.href, this.download])
	})
	showError.mockImplementation((text) => events.push(['showError', text]))
})

afterEach(() => jest.restoreAllMocks())

describe('ReportView.downloadAs', () => {
	it.each([
		['the server file name', { 'content-disposition': 'attachment; filename="sales-2026.pdf"' }, {}],
		['an unquoted server file name', { 'content-disposition': 'attachment; filename=sales.pdf' }, {}],
		['the dashboard title', {}, {}],
		['the default name', undefined, { dashboard: null }],
	])('saves the render under %s', async (label, headers, state) => {
		axios.post.mockResolvedValue({ data: 'BLOB', headers })
		await downloadAs(state)
		expect({ request: axios.post.mock.calls, events }).toMatchSnapshot()
	})

	it('does nothing without a report id', async () => {
		await downloadAs({ $route: { params: {} } })
		expect([axios.post.mock.calls, events]).toEqual([[], []])
	})

	it.each([
		['a missing office server', () => failureWithBody(JSON.stringify({ error: 'office_server_required', message: 'm' }))],
		['a message', () => failureWithBody(JSON.stringify({ message: 'Too large', error: 'e' }))],
		['an error code', () => failureWithBody(JSON.stringify({ error: 'render_failed' }))],
		['an empty JSON body', () => failureWithBody('{}')],
		['a body that is not JSON', () => failureWithBody('<html>')],
		['no response', () => new Error('offline')],
	])('shows the failure for %s', async (label, failure) => {
		axios.post.mockRejectedValue(failure())
		await downloadAs({})
		expect(events).toMatchSnapshot()
	})
})
