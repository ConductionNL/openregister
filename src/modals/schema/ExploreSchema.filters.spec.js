/**
 * The schema explorer's suggestion filters (search, confidence, new versus
 * existing, selected only) and counting a schema's objects. Runs on the
 * options object against a synthetic `this`, with the schema store mocked.
 *
 * @spec exclude Client-side schema explorer filters.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ExploreSchema from './ExploreSchema.vue'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: {},
	schemaStore: { schemaItem: null, getSchemaStats: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseSearch.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('../../components/SchemaStatsBlock.vue', () => ({}))

const { schemaStore } = jest.requireMock('../../store/store.js')

const SUGGESTIONS = [
	{ property_name: 'Email', confidence: 'high', improvement_status: 'new' },
	{ property_name: 'emailVerified', confidence: 'low', improvement_status: 'existing' },
	{ property_name: 'phone', confidence: 'high' },
]

/**
 * The filtered suggestions for a filter state.
 *
 * @param {object} filters The filter state to apply
 * @return {Array<string>} The names of the suggestions that remain
 */
function filtered(filters = {}) {
	const ctx = {
		explorationData: { suggestions: SUGGESTIONS },
		propertyFilter: '',
		confidenceFilter: 'all',
		typeFilter: 'all',
		showOnlySelected: false,
		selectedProperties: [],
		...filters,
	}
	return ExploreSchema.computed.filteredSuggestions.call(ctx).map((s) => s.property_name)
}

describe('ExploreSchema — suggestion filters', () => {
	it('shows nothing before an exploration ran, and everything without a filter', () => {
		expect(ExploreSchema.computed.filteredSuggestions.call({ explorationData: null })).toEqual([])
		expect(filtered()).toEqual(['Email', 'emailVerified', 'phone'])
	})

	it('matches the search term case-insensitively on the property name', () => {
		expect(filtered({ propertyFilter: 'EMAIL' })).toEqual(['Email', 'emailVerified'])
	})

	it('keeps only the chosen confidence', () => {
		expect(filtered({ confidenceFilter: 'high' })).toEqual(['Email', 'phone'])
	})

	it('splits new properties from improvements to existing ones', () => {
		expect(filtered({ typeFilter: 'new' })).toEqual(['Email', 'phone'])
		expect(filtered({ typeFilter: 'existing' })).toEqual(['emailVerified'])
		expect(filtered({ typeFilter: 'other' })).toEqual(['Email', 'emailVerified', 'phone'])
	})

	it('shows only the selected properties when asked, combined with the other filters', () => {
		expect(filtered({ showOnlySelected: true, selectedProperties: ['phone', 'Email'] })).toEqual(['Email', 'phone'])
		expect(filtered({ showOnlySelected: true, selectedProperties: ['phone', 'Email'], confidenceFilter: 'low' })).toEqual([])
	})
})

describe('ExploreSchema — counting objects', () => {
	beforeEach(() => {
		schemaStore.schemaItem = null
		schemaStore.getSchemaStats.mockReset()
	})

	it('reads the object total and breakdown of the selected schema', async () => {
		schemaStore.schemaItem = { id: 7 }
		schemaStore.getSchemaStats.mockResolvedValue({ objects: { total: 12, invalid: 1 } })
		const ctx = { objectCount: 0, objectStats: null }

		await ExploreSchema.methods.countObjects.call(ctx)

		expect(schemaStore.getSchemaStats).toHaveBeenCalledWith(7)
		expect(ctx).toEqual({ objectCount: 12, objectStats: { total: 12, invalid: 1 } })
	})

	it('resets the count when the stats cannot be read', async () => {
		schemaStore.schemaItem = { id: 7 }
		schemaStore.getSchemaStats.mockRejectedValue(new Error('down'))
		const ctx = { objectCount: 4, objectStats: { total: 4 } }

		await ExploreSchema.methods.countObjects.call(ctx)

		expect(ctx).toEqual({ objectCount: 0, objectStats: null })
	})

	it('asks nothing without a selected schema', async () => {
		const ctx = { objectCount: 0, objectStats: null }

		await ExploreSchema.methods.countObjects.call(ctx)

		expect(schemaStore.getSchemaStats).not.toHaveBeenCalled()
	})
})
