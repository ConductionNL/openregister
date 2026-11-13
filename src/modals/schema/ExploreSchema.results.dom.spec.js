/**
 * The explore schema modal's analysis results as rendered: the issues and
 * recommendations of an existing property, its sample values, the filters,
 * the pagination and the selection footer.
 *
 * @spec exclude Client-side dialog markup and its event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ExploreSchema from './ExploreSchema.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		`t:${text.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match))}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () =>
	require('../../views/__mocks__/goldenDom.js').library(),
)
jest.mock('../../components/SchemaStatsBlock.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('SchemaStatsBlock'),
)
jest.mock('../../components/PaginationComponent.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('PaginationComponent'),
)
jest.mock('vue-material-design-icons/Check.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Check'),
)
jest.mock('vue-material-design-icons/DatabaseSearch.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('DatabaseSearch'),
)
jest.mock('vue-material-design-icons/Refresh.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Refresh'),
)
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	schemaStore: {
		schemaItem: { id: 7 },
		getSchemaStats: jest.fn(() => Promise.resolve({ objects: { total: 3 } })),
		setSchemaItem: jest.fn(),
		refreshSchemaList: jest.fn(),
	},
}))

const EXISTING = {
	property_name: 'name',
	recommended_type: 'string',
	type: 'string',
	confidence: 'medium',
	improvement_status: 'existing',
	issues: ['type_mismatch', 'missing_max_length', 'missing_enum', 'unheard_of'],
	suggestions: [
		{ field: 'maxLength', current: 'none', recommended: '255', description: 'Cap the length' },
	],
	examples: [null, { a: 1 }, 'abcdefghijklmnopqrstuvwxyz', 42],
	type_variations: ['string', 'integer'],
}

const NEW = {
	property_name: 'title',
	recommended_type: 'string',
	description: 'A title',
	confidence: 'high',
	improvement_status: 'new',
}

/**
 * An analysis holding the given suggestions.
 *
 * @param {Array<object>} suggestions The suggestions
 * @return {object} The analysis
 */
function analysisOf(suggestions) {
	return {
		total_objects: 12,
		analysis_date: '2026-01-02T03:04:05Z',
		analysis_summary: { new_properties_count: 1, existing_properties_improvements: 1 },
		suggestions,
	}
}

/**
 * Mounts the dialog and runs its analysis against a stubbed endpoint.
 *
 * @param {object} analysis The analysis the endpoint answers
 * @return {Promise<object>} The wrapper
 */
async function dialog(analysis = analysisOf([NEW, EXISTING])) {
	global.fetch = jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(analysis) }))
	const { translate: t, translatePlural: n } = jest.requireMock('@nextcloud/l10n')
	const stores = jest.requireMock('../../store/store.js')
	const SchemaStatsBlock = jest.requireMock('../../components/SchemaStatsBlock.vue').default
	const bindings = { ...ExploreSchema.components, ...stores, SchemaStatsBlock, t, n }
	const wrapper = mount({ ...ExploreSchema, setup: () => bindings }, {
		global: { config: { warnHandler: () => {} } },
	})
	await wrapper.vm.startAnalysis()
	await flushPromises()
	return wrapper
}

/**
 * The trimmed texts of every element a selector matches.
 *
 * @param {object} wrapper The mounted dialog
 * @param {string} selector The elements
 * @return {Array<string>} The texts
 */
function texts(wrapper, selector) {
	return wrapper.findAll(selector).map((el) => el.text().replace(/\s+/g, ' ').trim())
}

/**
 * The stubbed button whose text contains a label.
 *
 * @param {object} wrapper The mounted dialog
 * @param {string} label The label
 * @return {object} The button component
 */
function button(wrapper, label) {
	const found = wrapper
		.findAllComponents({ name: 'NcButton' })
		.find((item) => item.text().includes(label))
	expect(found).toBeDefined()
	return found
}

/**
 * The property names of the rendered cards.
 *
 * @param {object} wrapper The mounted dialog
 * @return {Array<string>} The names
 */
function cardNames(wrapper) {
	return texts(wrapper, '.property-card .property-info h4')
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('ExploreSchema existing property details', () => {
	it('lists one labelled, described issue per detected issue', async () => {
		const wrapper = await dialog()
		const items = wrapper.findAll('.improvement-details .issue-item')
		expect(items.map((item) => [
			item.find('.issue-badge').classes(),
			item.find('.issue-badge').text(),
			item.find('.issue-description').text(),
		])).toEqual([
			[['issue-badge', 'issue-type'], 't:Type Issue', 't:Data type does not match observed values'],
			[['issue-badge', 'issue-constraint'], 't:Constraint Issue', 't:Maximum length constraint is missing'],
			[['issue-badge', 'issue-enum'], 't:Enum Issue', 't:Enum constraint is missing'],
			[['issue-badge', 'issue-general'], 't:General Issue', 't:Property can be improved'],
		])
	})

	it('shows each recommendation as field, current and recommended value', async () => {
		const wrapper = await dialog()
		const items = wrapper.findAll('.suggestions-list .suggestion-item')
		expect(items.map((item) => [
			item.find('.suggestion-field').text(),
			item.find('.current').text(),
			item.find('.recommended').text(),
			item.find('.suggestion-desc').text(),
		])).toEqual([['maxLength:', 'none', '255', 'Cap the length']])
	})

	it('shows no issue section for a new property', async () => {
		const wrapper = await dialog(analysisOf([NEW]))
		expect(wrapper.find('.improvement-details').exists()).toBe(false)
		expect(wrapper.find('.property-examples').exists()).toBe(false)
	})

	it('formats the sample values', async () => {
		const wrapper = await dialog()
		expect(texts(wrapper, '.example-tag')).toEqual([
			'null',
			'{object}',
			'abcdefghijklmnopqrst...',
			'42',
		])
	})

	it('warns about inconsistent types once the property is selected', async () => {
		const wrapper = await dialog()
		expect(wrapper.find('.config-warning').exists()).toBe(false)
		wrapper.vm.togglePropertySelection('name')
		await flushPromises()
		expect(wrapper.find('.config-warning').text()).toBe(
			't:Warning: This property has inconsistent types: string, integer. Consider if all objects should have the same type.',
		)
	})

	it('selects and unselects a property through its card switch', async () => {
		const wrapper = await dialog()
		const toggle = wrapper
			.findAll('.property-card')[1]
			.find('.property-actions')
			.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.vm.$attrs.modelValue).toBe(false)
		toggle.vm.$attrs['onUpdate:modelValue'](true)
		await flushPromises()
		expect(wrapper.vm.selectedProperties).toEqual(['name'])
		expect(wrapper.vm.selectedPropertiesConfig.name).toMatchObject({ type: 'string', title: 'name' })
		toggle.vm.$attrs['onUpdate:modelValue'](false)
		await flushPromises()
		expect(wrapper.vm.selectedProperties).toEqual([])
		expect(wrapper.vm.selectedPropertiesConfig.name).toBeUndefined()
	})
})

describe('ExploreSchema result filters', () => {
	/**
	 * The filter control at a position in the filter bar.
	 *
	 * @param {object} wrapper The mounted dialog
	 * @param {number} index The position
	 * @return {object} The control component
	 */
	function filter(wrapper, index) {
		return wrapper
			.findAll('.property-filters .filter-section')[index]
			.findAllComponents({ name: index === 0 ? 'NcTextField' : 'NcSelect' })[0]
	}

	it('narrows the cards by a case-insensitive name search', async () => {
		const wrapper = await dialog()
		expect(cardNames(wrapper)).toEqual(['title', 'name'])
		filter(wrapper, 0).vm.$attrs['onUpdate:modelValue']('TIT')
		await flushPromises()
		expect(wrapper.vm.propertyFilter).toBe('TIT')
		expect(cardNames(wrapper)).toEqual(['title'])
	})

	it('narrows the cards by confidence', async () => {
		const wrapper = await dialog()
		filter(wrapper, 1).vm.$attrs['onUpdate:modelValue']('medium')
		await flushPromises()
		expect(cardNames(wrapper)).toEqual(['name'])
	})

	it('narrows the cards to new properties or to existing improvements', async () => {
		const wrapper = await dialog()
		filter(wrapper, 2).vm.$attrs['onUpdate:modelValue']('new')
		await flushPromises()
		expect(cardNames(wrapper)).toEqual(['title'])
		filter(wrapper, 2).vm.$attrs['onUpdate:modelValue']('existing')
		await flushPromises()
		expect(cardNames(wrapper)).toEqual(['name'])
	})

	it('shows the no-results message when nothing matches', async () => {
		const wrapper = await dialog()
		expect(wrapper.find('.no-results').exists()).toBe(false)
		filter(wrapper, 0).vm.$attrs['onUpdate:modelValue']('nothing-like-this')
		await flushPromises()
		expect(wrapper.findAll('.property-card')).toHaveLength(0)
		expect(wrapper.find('.no-results').exists()).toBe(true)
	})
})

describe('ExploreSchema pagination', () => {
	const many = Array.from({ length: 12 }, (_, index) => ({
		...NEW,
		property_name: `prop${String(index).padStart(2, '0')}`,
	}))

	it('pages the cards, ten at a time', async () => {
		const wrapper = await dialog(analysisOf(many))
		const pagination = wrapper.findComponent({ name: 'PaginationComponent' })
		expect(pagination.vm.$attrs).toMatchObject({ currentPage: 1, totalPages: 2, totalItems: 12, currentPageSize: 10 })
		expect(wrapper.findAll('.property-card')).toHaveLength(10)
		pagination.vm.$attrs.onPageChanged(2)
		await flushPromises()
		expect(wrapper.vm.currentPage).toBe(2)
		expect(cardNames(wrapper)).toEqual(['prop10', 'prop11'])
	})

	it('returns to the first page when the page size changes', async () => {
		const wrapper = await dialog(analysisOf(many))
		const pagination = wrapper.findComponent({ name: 'PaginationComponent' })
		pagination.vm.$attrs.onPageChanged(2)
		await flushPromises()
		pagination.vm.$attrs.onPageSizeChanged(5)
		await flushPromises()
		expect(wrapper.vm.currentPage).toBe(1)
		expect(wrapper.vm.itemsPerPage).toBe(5)
		expect(wrapper.findAll('.property-card')).toHaveLength(5)
	})

	it('shows no pagination when one page holds every card', async () => {
		const wrapper = await dialog()
		expect(wrapper.find('.pagination-controls').exists()).toBe(false)
	})
})

describe('ExploreSchema selection footer', () => {
	it('appears with the selection count once a property is selected', async () => {
		const wrapper = await dialog()
		expect(wrapper.find('.selection-summary').exists()).toBe(false)
		wrapper.vm.togglePropertySelection('title')
		await flushPromises()
		expect(wrapper.find('.selection-summary').text()).toContain(
			't:You have selected 1 properties to add to the schema.',
		)
	})

	it('selects every suggestion, seeding a configuration for the unconfigured ones', async () => {
		const wrapper = await dialog()
		wrapper.vm.togglePropertySelection('title')
		await flushPromises()
		const titleConfig = wrapper.vm.selectedPropertiesConfig.title
		delete wrapper.vm.selectedPropertiesConfig.name
		button(wrapper, 'Select All').vm.$attrs.onClick()
		await flushPromises()
		expect(wrapper.vm.selectedProperties).toEqual(['title', 'name'])
		expect(wrapper.vm.selectedPropertiesConfig.title).toBe(titleConfig)
		expect(wrapper.vm.selectedPropertiesConfig.name).toEqual({
			type: 'string',
			title: 'name',
			description: '',
			required: false,
			facetable: false,
		})
	})

	it('clears the selection and its configuration', async () => {
		const wrapper = await dialog()
		wrapper.vm.togglePropertySelection('title')
		await flushPromises()
		button(wrapper, 'Clear Selection').vm.$attrs.onClick()
		await flushPromises()
		expect(wrapper.vm.selectedProperties).toEqual([])
		expect(wrapper.vm.selectedPropertiesConfig).toEqual({})
		expect(wrapper.find('.selection-summary').exists()).toBe(false)
	})

	it('applies the selection through the update endpoint', async () => {
		jest.useFakeTimers()
		try {
			const wrapper = await dialog()
			wrapper.vm.togglePropertySelection('title')
			await flushPromises()
			global.fetch = jest.fn(() => Promise.resolve({
				ok: true,
				json: () => Promise.resolve({ schema: { id: 7, title: 'Updated' } }),
			}))
			const apply = button(wrapper, 'Apply Changes')
			expect(apply.vm.$attrs.disabled).toBe(false)
			await apply.vm.$attrs.onClick()
			await flushPromises()
			expect(global.fetch).toHaveBeenCalledTimes(1)
			expect(global.fetch.mock.calls[0][0]).toBe(
				'/index.php/apps/openregister/api/schemas/7/update-from-exploration',
			)
			expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({
				properties: {
					title: {
						type: 'string',
						title: 'title',
						description: 'A title',
						required: false,
						facetable: false,
					},
				},
			})
			const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')
			expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 7, title: 'Updated' })
			expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
			expect(wrapper.text()).toContain('Schema successfully updated with 1 properties')
			expect(navigationStore.setDialog).not.toHaveBeenCalled()
			jest.advanceTimersByTime(2000)
			expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		} finally {
			jest.useRealTimers()
		}
	})
})

describe('ExploreSchema closing', () => {
	it('closes and resets from the Close button of the results', async () => {
		const wrapper = await dialog()
		wrapper.vm.togglePropertySelection('title')
		await flushPromises()
		button(wrapper, 'Close').vm.$attrs.onClick()
		await flushPromises()
		const { navigationStore } = jest.requireMock('../../store/store.js')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.vm.explorationData).toBeNull()
		expect(wrapper.vm.selectedProperties).toEqual([])
		expect(wrapper.find('.discovered-properties').exists()).toBe(false)
	})

	it('closes and resets when the dialog reports it closed', async () => {
		const wrapper = await dialog()
		wrapper.findComponent({ name: 'NcDialog' }).vm.$attrs['onUpdate:open'](false)
		await flushPromises()
		const { navigationStore } = jest.requireMock('../../store/store.js')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.vm.explorationData).toBeNull()
		expect(wrapper.vm.objectCount).toBe(0)
	})
})
