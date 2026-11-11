/**
 * The explore schema modal's lookups and its schema update: the issue
 * categories and descriptions, the format options, and the request the
 * update sends.
 *
 * @spec exclude Client-side display helpers and modal plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ExploreSchema from './ExploreSchema.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	schemaStore: {
		schemaItem: { id: 7 },
		setSchemaItem: jest.fn(),
		refreshSchemaList: jest.fn(),
	},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => `[${s}]`,
	translatePlural: (app, s) => s,
}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseSearch.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('../../components/SchemaStatsBlock.vue', () => ({}))

const ISSUES = [
	'type_mismatch',
	'missing_max_length',
	'max_length_too_small',
	'missing_format',
	'missing_pattern',
	'missing_minimum',
	'minimum_too_high',
	'missing_maximum',
	'maximum_too_low',
	'inconsistent_required',
	'missing_enum',
	'something_else',
	'toString',
]

/**
 * A bare component context with the options object's methods.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { t: (app, s) => `[${s}]` }
	for (const [name, method] of Object.entries(ExploreSchema.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe('ExploreSchema issue lookups', () => {
	it('maps every issue type to a category and a description', () => {
		const ctx = context()
		expect(
			ISSUES.map((issue) => [
				issue,
				ctx.getIssueType(issue),
				ctx.getIssueDescription(issue),
			]),
		).toMatchSnapshot()
	})

	it('details a list of issues', () => {
		expect(
			context().getIssueDetails(['missing_enum', 'unknown']),
		).toMatchSnapshot()
	})
})

describe('ExploreSchema.formatOptions', () => {
	const formatOptions = ExploreSchema.computed.formatOptions.call({})

	it('lists the common formats when none was detected', () => {
		expect(formatOptions({})).toMatchSnapshot()
	})

	it('adds a detected format the common list lacks', () => {
		expect(formatOptions({ detected_format: 'ipv4' }).slice(-2)).toEqual([
			{ label: 'Color (Hex)', value: 'color', key: 'color' },
			{ label: 'Ipv4', value: 'ipv4', key: 'ipv4' },
		])
	})

	it('does not repeat a detected format already listed', () => {
		expect(formatOptions({ detected_format: 'email' })).toEqual(
			formatOptions({}),
		)
	})

	it('gives every call its own options', () => {
		const first = formatOptions({})
		const second = formatOptions({})
		expect(first).not.toBe(second)
		expect(first[0]).not.toBe(second[0])
	})
})

describe('ExploreSchema.updateSchema request', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	/**
	 * A context with two selected properties ready to save.
	 *
	 * @return {object} The context
	 */
	function saving() {
		const ctx = context()
		ctx.loading = false
		ctx.error = 'old'
		ctx.success = false
		ctx.handleDialogClose = jest.fn()
		ctx.selectedProperties = ['name', 'age']
		ctx.selectedPropertiesConfig = {
			name: {
				type: 'string',
				title: 'Name',
				description: 'The name',
				required: true,
				facetable: false,
				format: 'email',
			},
			age: { type: 'integer', title: 'Age', extra: 1 },
			ignored: { type: 'boolean' },
		}
		return ctx
	}

	it('posts only the selected properties, then refreshes and closes', async () => {
		const { schemaStore } = jest.requireMock('../../store/store.js')
		schemaStore.setSchemaItem.mockClear()
		schemaStore.refreshSchemaList.mockClear()
		global.fetch = jest.fn(() =>
			Promise.resolve({ ok: true, json: () => Promise.resolve({}) }),
		)
		const ctx = saving()
		await ctx.updateSchema()
		expect(global.fetch.mock.calls).toMatchSnapshot()
		expect(ctx.error).toBeNull()
		expect(ctx.success).toBe(true)
		expect(ctx.loading).toBe(false)
		expect(schemaStore.setSchemaItem).not.toHaveBeenCalled()
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		jest.advanceTimersByTime(1999)
		expect(ctx.handleDialogClose).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(ctx.handleDialogClose).toHaveBeenCalledTimes(1)
	})

	it('falls back to its own message when the error has none', async () => {
		global.fetch = jest.fn(() => Promise.reject(new Error('')))
		const ctx = saving()
		await ctx.updateSchema()
		expect(ctx.error).toBe('[Failed to update schema properties]')
		expect(ctx.success).toBe(false)
		expect(ctx.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith(
			'Schema update failed:',
			new Error(''),
		)
	})
})
