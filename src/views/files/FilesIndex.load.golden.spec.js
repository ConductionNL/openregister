import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import FilesIndex from './FilesIndex.vue'

/**
 * Golden record of the files list load: the request with its paging, sort
 * and filters, the state each answer leaves, and the error shown and logged.
 *
 * @spec exclude Golden record of the client-side files list load.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, t: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/FilesSidebar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/OffsetPager.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../services/menuActions.js', () => ({ refreshAction: () => ({}) }))

const LIST = {
	loading: false,
	limit: 25,
	offset: 50,
	sortField: 'name',
	sortOrder: 'asc',
	searchQuery: '',
	statusFilter: null,
	riskLevelFilter: null,
	filesList: ['old'],
	totalFiles: 9,
}

/**
 * Load the files into a bare page state carrying the page's methods.
 *
 * @param {object} state The page data
 * @param {Function} answer The request's answer
 * @return {Promise<object>} The data after loading, the request and what was shown or logged
 */
async function loadFiles(state, answer) {
	const page = { ...state }
	const methods = Object.keys(FilesIndex.methods)
	for (const name of methods) {
		page[name] = FilesIndex.methods[name].bind(page)
	}
	const during = []
	axios.get.mockImplementation((...args) => {
		during.push({ args, loading: page.loading })
		return answer()
	})
	const logged = jest.spyOn(console, 'error').mockImplementation(() => {})
	await page.loadFiles()
	for (const name of methods) {
		delete page[name]
	}
	const result = { page, during, shown: showError.mock.calls, logged: logged.mock.calls.map(([text]) => text) }
	logged.mockRestore()
	return result
}

describe('FilesIndex.loadFiles', () => {
	beforeEach(() => {
		axios.get.mockReset()
		showError.mockReset()
	})

	it.each([
		['without filters', {}],
		['with every filter', { searchQuery: 'report', statusFilter: 'done', riskLevelFilter: 'high' }],
	])('asks for the page %s', async (label, filters) => {
		const result = await loadFiles({ ...LIST, ...filters }, () => Promise.resolve({ data: { success: true, data: [{ id: 1 }], count: 80 } }))
		expect(result).toMatchSnapshot()
		expect(Object.keys(result.during[0].args[1].params)).toMatchSnapshot('parameter order')
	})

	it.each([
		['a success without a count', { success: true, data: [{ id: 1 }, { id: 2 }] }],
		['an unsuccessful answer', { success: false, data: [{ id: 1 }] }],
	])('handles %s', async (label, data) => {
		expect(await loadFiles(LIST, () => Promise.resolve({ data }))).toMatchSnapshot()
	})

	it('shows and logs a failure', async () => {
		expect(await loadFiles(LIST, () => Promise.reject(new Error('offline')))).toMatchSnapshot()
	})
})
