/**
 * The property editor's two index switches: a new property asks for neither
 * index, and the text-search switch is only offered where the instance can
 * build that index.
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-the-property-editor-offers-both-index-kinds
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditSchemaProperty from './EditSchemaProperty.vue'
import { fetchSchemaIndexes } from '../../services/schemaIndexes.js'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn() },
	registerStore: {},
	schemaStore: { schemaList: [], setSchemaPropertyKey: jest.fn(), schemaItem: null, schemaPropertyKey: null },
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (p) => p }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('../../composables/registerSchemaLists.js', () => ({ ensureRegisterAndSchemaLists: jest.fn() }))
jest.mock('../../services/schemaIndexes.js', () => ({ __esModule: true, fetchSchemaIndexes: jest.fn() }))

const { schemaStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = EditSchemaProperty

/**
 * The modal's initial state with computed getters and bound methods.
 *
 * @return {object} The instance
 */
function form() {
	const vm = EditSchemaProperty.data.call({})
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
	schemaStore.schemaItem = null
})

describe('EditSchemaProperty index switches', () => {
	it('starts a new property with both index switches off', () => {
		const vm = form()

		expect(vm.properties.indexed).toBe(false)
		expect(vm.properties.searchable).toBe(false)
		expect(vm.textSearchIndexAvailable).toBeNull()
	})

	it('offers the text-search index when the instance can build one', async () => {
		schemaStore.schemaItem = { id: 12 }
		fetchSchemaIndexes.mockResolvedValue({ results: [], textSearchIndex: true })
		const vm = form()

		await vm.loadTextSearchIndexAvailability()

		expect(fetchSchemaIndexes).toHaveBeenCalledWith(12)
		expect(vm.textSearchIndexAvailable).toBe(true)
	})

	it('keeps the text-search switch off on an instance without pg_trgm', async () => {
		schemaStore.schemaItem = { id: 12 }
		fetchSchemaIndexes.mockResolvedValue({ results: [], textSearchIndex: false })
		const vm = form()

		await vm.loadTextSearchIndexAvailability()

		expect(vm.textSearchIndexAvailable).toBe(false)
	})

	it('keeps the switch off when the list cannot be read', async () => {
		schemaStore.schemaItem = { id: 12 }
		fetchSchemaIndexes.mockRejectedValue(new Error('500'))
		const vm = form()

		await vm.loadTextSearchIndexAvailability()

		expect(vm.textSearchIndexAvailable).toBe(false)
	})

	it('asks nothing for a schema that is not saved yet', async () => {
		schemaStore.schemaItem = { title: 'Nieuw' }
		const vm = form()

		await vm.loadTextSearchIndexAvailability()

		expect(fetchSchemaIndexes).not.toHaveBeenCalled()
		expect(vm.textSearchIndexAvailable).toBeNull()
	})
})
