import ExploreSchema from './ExploreSchema.vue'

// Running an exploration stores its analysis and seeds a configuration for
// every suggestion that has none yet; a failure becomes the modal's error.

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	schemaStore: { schemaItem: { id: 7 } },
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

/**
 * A bare component context: the options object's methods and state, without a mount.
 *
 * @return {object} The context
 */
function context() {
	const ctx = {
		t: (app, s) => s,
		explorationData: null,
		selectedPropertiesConfig: {},
		analysisStarted: false,
		loading: false,
		error: null,
	}
	for (const [name, method] of Object.entries(ExploreSchema.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * A fetch double answering with one response.
 *
 * @param {object} response The response.
 * @return {Function} The fetch double
 */
function answer(response) {
	return jest.fn(() => Promise.resolve(response))
}

describe('ExploreSchema.startAnalysis', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it('stores the analysis and seeds a configuration per new suggestion', async () => {
		const data = {
			suggestions: [
				{
					property_name: 'title',
					recommended_type: 'string',
					max_length: 9,
					min_length: 1,
				},
				{ property_name: 'kept', recommended_type: 'number' },
			],
		}
		global.fetch = answer({ ok: true, json: () => Promise.resolve(data) })
		const ctx = context()
		ctx.selectedPropertiesConfig.kept = { mine: true }

		await ctx.startAnalysis()

		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/schemas/7/explore',
			{ method: 'GET', headers: { 'Content-Type': 'application/json' } },
		)
		expect(ctx.explorationData).toBe(data)
		expect(ctx.selectedPropertiesConfig.kept).toEqual({ mine: true })
		expect(ctx.selectedPropertiesConfig.title).toMatchObject({
			type: 'string',
			title: 'title',
			maxLength: 9,
			minLength: 1,
			order: 0,
		})
		expect(ctx.error).toBeNull()
		expect(ctx.loading).toBe(false)
		expect(ctx.analysisStarted).toBe(false)
	})

	it('reports an HTTP failure', async () => {
		global.fetch = answer({ ok: false, status: 500 })
		const ctx = context()
		await ctx.startAnalysis()
		expect(ctx.error).toBe('HTTP error! status: 500')
		expect(ctx.explorationData).toBeNull()
		expect(ctx.loading).toBe(false)
	})

	it('reports an error the endpoint returns', async () => {
		global.fetch = answer({
			ok: true,
			json: () => Promise.resolve({ error: 'no objects' }),
		})
		const ctx = context()
		await ctx.startAnalysis()
		expect(ctx.error).toBe('no objects')
		expect(ctx.explorationData).toBeNull()
	})

	it('falls back to a generic message', async () => {
		global.fetch = jest.fn(() => Promise.reject(new Error('')))
		const ctx = context()
		await ctx.startAnalysis()
		expect(ctx.error).toBe('Failed to analyze schema properties')
	})
})

describe('ExploreSchema.togglePropertySelection', () => {
	it('selects a property with a configuration from its suggestion, and unselects it', () => {
		const ctx = context()
		ctx.selectedProperties = []
		ctx.explorationData = {
			suggestions: [{ property_name: 'age', recommended_type: 'integer' }],
		}

		ctx.togglePropertySelection('age')
		expect(ctx.selectedProperties).toEqual(['age'])
		expect(ctx.selectedPropertiesConfig.age).toMatchObject({
			selected: true,
			type: 'integer',
			order: 100,
		})

		ctx.togglePropertySelection('age')
		expect(ctx.selectedProperties).toEqual([])
		expect(ctx.selectedPropertiesConfig.age).toBeUndefined()
	})
})
