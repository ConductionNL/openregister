/**
 * The schema property modal's form logic: option lookups, the concept tree,
 * calculation trials, facet state in and out, code-list annotations, relation
 * pickers and the inversedBy options. Computed values and methods run against
 * the component's own initial state, with stub stores and HTTP.
 *
 * @spec exclude Client-side schema property form handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import EditSchemaProperty from './EditSchemaProperty.vue'

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

const { schemaStore, navigationStore } = jest.requireMock('../../store/store.js')
const { ensureRegisterAndSchemaLists } = jest.requireMock('../../composables/registerSchemaLists.js')
const { computed, methods } = EditSchemaProperty

/**
 * The modal's initial state with computed getters and bound methods.
 *
 * @param {object} state Values to set on top of the initial state
 * @return {object} The instance
 */
function form(state = {}) {
	const vm = EditSchemaProperty.data.call({})
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	schemaStore.schemaList = []
	schemaStore.schemaItem = null
	schemaStore.schemaPropertyKey = null
	global.fetch = jest.fn()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
	delete global.fetch
})

describe('EditSchemaProperty option lookups', () => {
	it('falls back to the first or a fixed option when the value is unknown', () => {
		const vm = form()
		expect(vm.codedStoreOption).toBe(vm.codedStoreOptions[0])
		expect(vm.semanticRoleOption).toBe(vm.semanticRoleOptions[0])
		expect(vm.facetTypeOption.value).toBe('date_histogram')
		expect(vm.facetIntervalOption.value).toBe('month')
		expect(vm.calculationTypeOption.value).toBe('string')

		vm.calculationType = 'nothing'
		vm.facetType = 'terms'
		vm.facetInterval = 'year'
		expect(vm.calculationTypeOption).toBeNull()
		expect(vm.facetTypeOption.value).toBe('terms')
		expect(vm.facetIntervalOption.value).toBe('year')
		expect(vm.calculationTypeOptions.map((o) => o.value)).toEqual(['string', 'integer', 'number', 'boolean', 'date'])
	})

	it('flattens the concept tree with depth and offerability', () => {
		const vm = form({
			conceptTree: [
				{ value: 'a', label: 'A', children: [{ value: 'a1', label: 'A1', offerable: false }] },
				{ value: 'b', label: 'B' },
			],
		})
		expect(vm.flatConceptTree).toEqual([
			{ value: 'a', label: 'A', offerable: true, depth: 0 },
			{ value: 'a1', label: 'A1', offerable: false, depth: 1 },
			{ value: 'b', label: 'B', offerable: true, depth: 0 },
		])
	})

	it('labels operators with category and description', () => {
		const vm = form({ operatorCatalogue: [{ op: 'sum', category: 'math', description: 'Adds' }] })
		expect(vm.operatorOptions).toEqual([{ value: 'sum', label: 'sum (math): Adds' }])
	})

	it('describes the object and file handling selects', () => {
		const vm = form()
		const handling = vm.objectConfiguration.handling
		expect(handling.options.map((o) => o.value)).toEqual(['nested-object', 'nested-schema', 'related-schema', 'uri'])
		expect(handling.reduce({ value: 'uri' })).toBe('uri')
		expect(handling.getOptionLabel({ value: 'v' })).toBe('v')
		expect(handling.getOptionLabel({})).toBe('')
		expect(vm.fileConfiguration.handling.options).toEqual(['ignore', 'transform'])
		expect(vm.mimeTypes).toContain('application/xml')
	})
})

describe('EditSchemaProperty inversedBy options', () => {
	it('is disabled without a related schema', () => {
		expect(form().inversedByOptions).toEqual({ inputLabel: 'Select Property', multiple: false, options: [], disabled: true })
	})

	it('lists the properties of the schema the $ref points at', () => {
		schemaStore.schemaList = [{ id: 's-1', slug: 'person', properties: { name: { title: 'Name', description: 'Full name' }, age: {} } }]
		const vm = form()
		vm.properties.$ref = '#/components/schemas/person'
		const options = vm.inversedByOptions
		expect(options.options).toEqual([
			{ value: 'name', label: 'Name', title: 'Full name' },
			{ value: 'age', label: 'age', title: 'age' },
		])
		expect(options.disabled).toBe(false)
		expect(options.reduce({ value: 'name' })).toBe('name')
		expect(options.getOptionLabel({ label: 'L' })).toBe('L')
	})

	it('finds a schema by id, title or slug and nothing without a ref', () => {
		schemaStore.schemaList = [{ id: 'x', title: 'Thing', slug: 'thing' }]
		const vm = form()
		expect(vm.getSchemaFromRef('')).toBeNull()
		expect(vm.getSchemaFromRef('x')).toEqual(schemaStore.schemaList[0])
		expect(vm.getSchemaFromRef('a/b/Thing')).toEqual(schemaStore.schemaList[0])
		expect(vm.getSchemaFromRef('missing')).toBeUndefined()
	})
})

describe('EditSchemaProperty calculations', () => {
	it('loads the operator catalogue and empties it on failure', async () => {
		axios.get.mockResolvedValueOnce({ data: { operators: [{ op: 'sum' }] } })
		const vm = form()
		await vm.loadOperatorCatalogue()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/schemas/calculation-operators')
		expect(vm.operatorCatalogue).toEqual([{ op: 'sum' }])
		expect(vm.operatorsLoading).toBe(false)

		axios.get.mockRejectedValueOnce(new Error('x'))
		await vm.loadOperatorCatalogue()
		expect(vm.operatorCatalogue).toEqual([])
	})

	it('appends an operator skeleton to the expression', () => {
		const vm = form()
		vm.insertOperator(null)
		expect(vm.calculationExpression).toBe('')
		vm.insertOperator({ value: 'sum' })
		expect(vm.calculationExpression).toBe('{"sum":[]}')
		vm.insertOperator({ value: 'max' })
		expect(vm.calculationExpression).toBe('{"sum":[]}\n{"max":[]}')
	})

	it('evaluates a trial calculation against the sample', async () => {
		axios.post.mockResolvedValueOnce({ data: { value: 5, dependencies: ['a'] } })
		const vm = form({ calculationType: 'integer', calculationExpression: '{"+":[1,4]}', calculationSample: '{"a":1}' })
		await vm.tryCalculation()
		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/schemas/calculation-evaluate', {
			calculation: { type: 'integer', expression: { '+': [1, 4] } },
			object: { a: 1 },
		})
		expect(vm.calculationTrialValue).toBe('5')
		expect(vm.calculationDependencies).toEqual(['a'])
		expect(vm.calculationTrying).toBe(false)
	})

	it('reports invalid JSON without a request, and the server error otherwise', async () => {
		const vm = form({ calculationExpression: '{bad' })
		await vm.tryCalculation()
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.calculationTrialError).toBe('The expression or the sample is not valid JSON.')

		axios.post.mockRejectedValueOnce({ response: { data: { error: { message: 'Unknown operator' }, dependencies: ['b'] } } })
		vm.calculationExpression = '{"x":[]}'
		await vm.tryCalculation()
		expect(vm.calculationTrialError).toBe('Unknown operator')
		expect(vm.calculationDependencies).toEqual(['b'])

		axios.post.mockRejectedValueOnce(new Error('network'))
		await vm.tryCalculation()
		expect(vm.calculationTrialError).toBe('The expression could not be evaluated.')
	})

	it('builds the stored calculation only when enabled and valid', () => {
		expect(form({ calculationExpression: '1' }).buildCalculationValue()).toBeNull()
		expect(form({ calculationEnabled: true, calculationExpression: '' }).buildCalculationValue()).toBeNull()
		expect(form({ calculationEnabled: true, calculationExpression: '{x' }).buildCalculationValue()).toBeNull()
		expect(form({ calculationEnabled: true, calculationType: 'number', calculationExpression: '{"+":[1]}' }).buildCalculationValue())
			.toEqual({ type: 'number', expression: { '+': [1] } })
	})

	it('reads a stored calculation into the form', () => {
		const vm = form()
		vm.hydrateCalculationState(null)
		expect(vm.calculationEnabled).toBe(false)
		vm.hydrateCalculationState({ expression: { a: 1 } })
		expect(vm.calculationEnabled).toBe(true)
		expect(vm.calculationType).toBe('string')
		expect(vm.calculationExpression).toBe('{\n  "a": 1\n}')
	})
})

describe('EditSchemaProperty facets', () => {
	it('reads true, an object and false into the facet state', () => {
		const vm = form()
		vm.hydrateFacetState(true)
		expect(vm.facetableEnabled).toBe(true)

		vm.hydrateFacetState({
			aggregated: false,
			title: 'T',
			order: 2,
			type: 'date_range',
			options: { useDefaultRanges: false, ranges: [{ label: 'Q1', from: '01' }] },
		})
		expect(vm.facetConfig).toEqual({ aggregated: false, title: 'T', description: '', order: '2' })
		expect(vm.facetType).toBe('date_range')
		expect(vm.facetUseDefaultRanges).toBe(false)
		expect(vm.facetCustomRanges).toEqual([{ label: 'Q1', from: '01', to: '' }])
		expect(vm.facetInterval).toBe('month')

		vm.hydrateFacetState(false)
		expect(vm.facetableEnabled).toBe(false)
	})

	it('writes false, true or a configuration object', () => {
		expect(form({ facetableEnabled: false }).facetableValue()).toBe(false)
		expect(form().facetableValue()).toBe(true)

		const vm = form()
		vm.facetConfig = { aggregated: true, title: ' Title ', description: '', order: '3' }
		expect(vm.facetableValue()).toEqual({ aggregated: true, title: 'Title', description: null, order: 3 })
	})

	it('adds the date facet type and options for a date property', () => {
		const vm = form({ facetType: 'date_histogram', facetFormat: 'Y-m' })
		vm.properties.type = 'string'
		vm.properties.format = 'date'
		expect(vm.facetableValue()).toEqual({
			aggregated: true,
			title: null,
			description: null,
			order: null,
			type: 'date_histogram',
			options: { interval: 'month', format: 'Y-m' },
		})

		vm.facetType = 'date_range'
		vm.facetUseDefaultRanges = false
		vm.facetCustomRanges = [{ label: 'A', from: '1', to: '' }, { label: '', from: '2', to: '3' }]
		expect(vm.facetDateOptions()).toEqual({ useDefaultRanges: false, ranges: [{ label: 'A', from: '1' }] })
		vm.facetType = 'terms'
		expect(vm.facetDateOptions()).toEqual({})
	})

	it('edits the custom ranges, the facet type and the oneOf entries', () => {
		const vm = form()
		vm.addCustomRange()
		vm.addCustomRange()
		vm.removeCustomRange(0)
		expect(vm.facetCustomRanges).toEqual([{ label: '', from: '', to: '' }])
		vm.updateFacetType({ value: 'terms' })
		expect(vm.facetType).toBe('terms')
		vm.addOneOfEntry()
		expect(vm.properties.oneOf).toEqual([{ type: '', format: '' }])
		vm.removeOneOfEntry(0)
		expect(vm.properties.oneOf).toEqual([])
	})
})

describe('EditSchemaProperty code lists and help text', () => {
	it('reads the concept, role and help annotations into the form', () => {
		const vm = form()
		vm.hydrateCodeListState({
			'x-openregister-concepts': { scheme: 'https://s', store: 'notation', maxDepth: 2, leafOnly: true, score: { property: 'p' } },
			'x-openregister-role': 'title',
			'x-openregister-help': { nl: 'Hulp', en: 'Help' },
		})
		expect(vm.codedConfig).toMatchObject({ scheme: 'https://s', store: 'notation', maxDepth: '2', leafOnly: true, allowDeprecated: false, scoreProperty: 'p' })
		expect(vm.semanticRole).toBe('title')
		expect(vm.helpTextNl).toBe('Hulp')
		expect(vm.helpTextEn).toBe('Help')

		vm.hydrateCodeListState({ 'x-openregister-help': 'Alleen NL' })
		expect(vm.codedConfig.store).toBe('uri')
		expect(vm.helpTextNl).toBe('Alleen NL')
		expect(vm.helpTextEn).toBe('')
	})

	it('writes the annotations back, leaving out what is empty', () => {
		const vm = form()
		vm.codedConfig = { scheme: ' https://s ', store: 'uri', branch: ' b ', maxDepth: '3', leafOnly: true, allowDeprecated: true, contextProperty: ' c ', scoreProperty: ' p ' }
		vm.semanticRole = 'title'
		vm.helpTextNl = ' Hulp '
		expect(vm.codeListAnnotations()).toEqual({
			'x-openregister-concepts': { scheme: 'https://s', store: 'uri', branch: 'b', maxDepth: 3, leafOnly: true, allowDeprecated: true, contextProperty: 'c', score: { property: 'p' } },
			'x-openregister-role': 'title',
			'x-openregister-help': { nl: 'Hulp' },
		})

		expect(form().codeListAnnotations()).toEqual({
			'x-openregister-concepts': undefined,
			'x-openregister-role': undefined,
			'x-openregister-help': undefined,
		})
	})

	it('loads the concept tree with the scheme settings', async () => {
		global.fetch.mockResolvedValue({ ok: true, json: async () => ({ tree: [{ value: 'a' }] }) })
		const vm = form()
		await vm.loadConceptTree()
		expect(global.fetch).not.toHaveBeenCalled()

		vm.codedConfig = { ...vm.codedConfig, scheme: 'https://s', branch: 'b', maxDepth: '2', leafOnly: true, allowDeprecated: true }
		await vm.loadConceptTree()
		const url = global.fetch.mock.calls[0][0]
		expect(url).toContain('/index.php/apps/openregister/api/vocabulary/options?')
		const params = new URLSearchParams(url.split('?')[1])
		expect(Object.fromEntries(params)).toEqual({ scheme: 'https://s', tree: '1', branch: 'b', store: 'uri', maxDepth: '2', leafOnly: 'true', allowDeprecated: 'true' })
		expect(vm.conceptTree).toEqual([{ value: 'a' }])
		expect(vm.treeLoaded).toBe(true)
		expect(vm.treeLoading).toBe(false)
	})

	it('reports an unreadable scheme and a failed request', async () => {
		const vm = form()
		vm.codedConfig.scheme = 'https://s'
		global.fetch.mockResolvedValueOnce({ ok: false })
		await vm.loadConceptTree()
		expect(vm.treeError).toBe('That concept scheme could not be read. Check the URI.')
		global.fetch.mockRejectedValueOnce(new Error('offline'))
		await vm.loadConceptTree()
		expect(vm.treeError).toBe('Error: offline')
		expect(vm.conceptTree).toEqual([])
	})
})

describe('EditSchemaProperty editing an existing property', () => {
	it('fills the form from the stored property', () => {
		schemaStore.schemaPropertyKey = 'title'
		schemaStore.schemaItem = {
			properties: {
				title: { type: 'string', maxLength: 10, calculation: { type: 'string', expression: 'x' }, facetable: true, objectConfiguration: { queryParams: 'q=1' } },
			},
		}
		const vm = form()
		vm.initializeSchemaItem()
		expect(vm.propertyTitle).toBe('title')
		expect(vm.properties.type).toBe('string')
		expect(vm.properties.maxLength).toBe(10)
		expect(vm.properties.minLength).toBe(0)
		expect(vm.properties.objectConfiguration.queryParams).toBe('q=1')
		expect(vm.properties.items.objectConfiguration.queryParams).toBe('')
		expect(vm.calculationEnabled).toBe(true)
	})

	it('does nothing without a property key', () => {
		const vm = form()
		const before = vm.properties
		vm.initializeSchemaItem()
		expect(vm.properties).toBe(before)
	})

	it('tells a new title clashes with another property', () => {
		schemaStore.schemaPropertyKey = 'title'
		schemaStore.schemaItem = { properties: { title: {}, name: {} } }
		expect(form({ propertyTitle: 'title' }).keyExists()).toBe(false)
		expect(form({ propertyTitle: 'name' }).keyExists()).toBe(true)
		expect(form({ propertyTitle: 'other' }).keyExists()).toBe(false)
	})

	it('drops the old and new name from the required list', () => {
		schemaStore.schemaPropertyKey = 'old'
		const item = { required: ['old', 'new', 'keep'] }
		form({ propertyTitle: 'new' }).dropFromRequired(item)
		expect(item.required).toEqual(['keep'])
		const none = {}
		form({ propertyTitle: 'new' }).dropFromRequired(none)
		expect(none.required).toBeUndefined()
	})

	it('closes the modal and clears the property key', () => {
		form().closeModal()
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(schemaStore.setSchemaPropertyKey).toHaveBeenCalledWith(null)
	})

	it('validates JSON input, accepting the empty string', () => {
		const vm = form()
		expect(vm.verifyJsonValidity('')).toBe(true)
		expect(vm.verifyJsonValidity('{"a":1}')).toBe(true)
		expect(vm.verifyJsonValidity('{a')).toBe(false)
	})
})

describe('EditSchemaProperty relation pickers', () => {
	it('loads the register and schema lists and clears the loading flags, also on failure', async () => {
		const vm = form()
		await vm.loadRegistersAndSchemas()
		expect(ensureRegisterAndSchemaLists).toHaveBeenCalled()
		ensureRegisterAndSchemaLists.mockRejectedValueOnce(new Error('x'))
		await vm.loadRegistersAndSchemas()
		expect(vm.registerLoading).toBe(false)
		expect(vm.schemaLoading).toBe(false)
	})

	it('resets the references when the register changes', () => {
		const vm = form()
		vm.properties.$ref = 'old'
		vm.properties.objectConfiguration.queryParams = 'q'
		vm.handleRegisterChange({ id: 4 })
		expect(vm.properties.register).toBe(4)
		expect(vm.properties.items.register).toBe(4)
		expect(vm.properties.$ref).toBe('')
		expect(vm.properties.objectConfiguration.queryParams).toBe('')
		vm.handleRegisterChange(5)
		expect(vm.selectedRegister).toBe(5)
	})

	it('points both references at the chosen schema', () => {
		const vm = form()
		vm.properties.inversedBy = 'x'
		vm.handleSchemaChange({ id: 's-2' })
		expect(vm.properties.$ref).toBe('s-2')
		expect(vm.properties.items.$ref).toBe('s-2')
		expect(vm.properties.inversedBy).toBe('')
		vm.handleSchemaChange('s-3')
		expect(vm.properties.$ref).toBe('s-3')
	})

	it('sets inversedBy from an option, an object id or a name', () => {
		const vm = form()
		vm.handleInversedByChange({ value: 'parent' })
		expect(vm.properties.inversedBy).toBe('parent')
		vm.handleInversedByChange({ id: 'p-id' })
		expect(vm.properties.items.inversedBy).toBe('p-id')
		vm.handleInversedByChange('plain')
		expect(vm.properties.inversedBy).toBe('plain')
	})

	it('sets a field on a target', () => {
		const target = {}
		form().setField(target, 'a', 1)
		expect(target).toEqual({ a: 1 })
	})
})
