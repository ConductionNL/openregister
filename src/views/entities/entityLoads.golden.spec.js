import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import EntitiesIndex from './EntitiesIndex.vue'
import EntityDetail from './EntityDetail.vue'

/**
 * Golden record of the entity pages' loads: the request and its filters, the
 * state each answer leaves, and the errors shown and logged.
 *
 * @spec exclude Golden record of the client-side entity list and detail loads.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, t: (app, text) => `t:${text}`, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path, vars) => `/gen${path}${vars ? JSON.stringify(vars) : ''}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ViewModeSwitch.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('./EntityStatsTable.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { on: jest.fn(), off: jest.fn() } }))
jest.mock('../../services/menuActions.js', () => ({ __esModule: true, refreshAction() {} }))
jest.mock('../../store/store.js', () => ({ __esModule: true, navigationStore: {} }))

let answer

/**
 * Call one method on a bare page state that carries the page's methods, and
 * return the state, the request, and what was shown or logged.
 *
 * @param {object} component The page component
 * @param {object} state The page data, plus `method` to call
 * @return {Promise<object>} What the call left
 */
async function run(component, state) {
	const { method, ...data } = state
	const page = { ...data }
	const methods = Object.keys(component.methods)
	for (const name of methods) {
		page[name] = component.methods[name].bind(page)
	}
	const during = []
	axios.get.mockImplementation((...args) => {
		during.push({ args, loading: page.loading, loadingRelations: page.loadingRelations, error: page.error })
		return answer()
	})
	const logged = jest.spyOn(console, 'error').mockImplementation(() => {})
	await page[method]()
	for (const name of methods) {
		delete page[name]
	}
	const result = { page, during, shown: showError.mock.calls, logged: logged.mock.calls.map(([text]) => text) }
	logged.mockRestore()
	return result
}

const LIST = { method: 'loadEntities', loading: false, limit: 20, currentPage: 3, searchQuery: '', typeFilter: null, categoryFilter: null, entitiesList: ['old'], totalEntities: 9 }

describe('EntitiesIndex.loadEntities', () => {
	beforeEach(() => {
		axios.get.mockReset()
		showError.mockReset()
	})

	it.each([
		['without filters', {}],
		['with every filter', { searchQuery: 'jan', typeFilter: 'person', categoryFilter: 'pii' }],
	])('asks for the page %s', async (label, filters) => {
		answer = () => Promise.resolve({ data: { success: true, data: [{ id: 1 }], count: 41 } })
		expect(await run(EntitiesIndex, { ...LIST, ...filters })).toMatchSnapshot()
	})

	it.each([
		['a success without a count', { success: true, data: [{ id: 1 }, { id: 2 }] }],
		['an unsuccessful answer', { success: false, data: [{ id: 1 }] }],
	])('handles %s', async (label, data) => {
		answer = () => Promise.resolve({ data })
		expect(await run(EntitiesIndex, LIST)).toMatchSnapshot()
	})

	it('shows and logs a failure', async () => {
		answer = () => Promise.reject(new Error('offline'))
		expect(await run(EntitiesIndex, LIST)).toMatchSnapshot()
	})
})

const DETAIL = { method: 'loadEntity', loading: false, loadingRelations: false, error: 'old', entity: null, relations: ['old'], $route: { params: { id: 'e-7' } } }

describe('EntityDetail.loadEntity', () => {
	beforeEach(() => {
		axios.get.mockReset()
		showError.mockReset()
	})

	it.each([
		['an entity with relations', { success: true, data: { id: 'e-7' }, relations: [{ id: 'r' }] }],
		['an entity without relations', { success: true, data: { id: 'e-7' } }],
		['a refusal with a message', { success: false, message: 'Not yours' }],
		['a refusal without a message', { success: false }],
	])('handles %s', async (label, data) => {
		answer = () => Promise.resolve({ data })
		expect(await run(EntityDetail, DETAIL)).toMatchSnapshot()
	})

	it.each([
		['the API message', { response: { data: { message: 'Gone' } } }],
		['the fallback', new Error('offline')],
	])('shows and logs a failure with %s', async (label, failure) => {
		answer = () => Promise.reject(failure)
		expect(await run(EntityDetail, DETAIL)).toMatchSnapshot()
	})
})
