/**
 * The merge dialog's own steps and helpers: starting, searching candidates,
 * moving between steps, the per-property choice it seeds and records, the
 * request it sends, the merged object it opens, and its display helpers.
 * Methods and computeds run against a plain `this` and stub stores.
 *
 * @spec exclude Client-side merge dialog state handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MergeObject from './MergeObject.vue'
import { navigationStore, objectStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: { objectItem: null, setObjectItem: jest.fn() },
	registerStore: { registerItem: null },
	schemaStore: { schemaItem: null },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s, vars = {}) => s.replace(/{(\w+)}/g, (match, key) => (key in vars ? String(vars[key]) : match)),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/Merge.vue', () => ({}))

/**
 * A bare merge context with every method and computed bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state = {}) {
	const ctx = { ...MergeObject.data.call({}), ...state }
	for (const [name, method] of Object.entries(MergeObject.methods)) {
		if (!(name in state)) ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(MergeObject.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx), enumerable: false })
	}
	return ctx
}

const SOURCE = { '@self': { id: 'src' }, id: 'src', name: 'Ada', city: 'Delft', note: '', _hidden: 1 }
const TARGET = { '@self': { id: 'tgt' }, id: 'tgt', name: 'Ada L.', city: null, age: 36 }

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.objectItem = SOURCE
	registerStore.registerItem = { id: 2 }
	schemaStore.schemaItem = { id: 5 }
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
	delete global.fetch
})

describe('MergeObject start and search', () => {
	it('closes at once without a source object', () => {
		objectStore.objectItem = null
		const ctx = context({ loadSourceData: jest.fn(), searchObjects: jest.fn() })

		ctx.initializeMerge()

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx.loadSourceData).not.toHaveBeenCalled()
		expect(ctx.searchObjects).not.toHaveBeenCalled()
	})

	it('loads the source and searches candidates with a source object', () => {
		const ctx = context({ loadSourceData: jest.fn(), searchObjects: jest.fn() })

		ctx.initializeMerge()

		expect(ctx.loadSourceData).toHaveBeenCalled()
		expect(ctx.searchObjects).toHaveBeenCalled()
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('lists every candidate but the source itself, searching by the term', async () => {
		global.fetch = jest.fn().mockResolvedValue({
			json: async () => ({ results: [{ '@self': { id: 'src' } }, { '@self': { id: 'a' } }, { '@self': { id: 'b' } }] }),
		})
		const ctx = context({ searchTerm: 'ad' })

		await ctx.searchObjects()

		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/2/5?_search=ad')
		expect(ctx.availableObjects.map((obj) => obj['@self'].id)).toEqual(['a', 'b'])
		expect(ctx.loading).toBe(false)
	})

	it('lists nothing when the search fails', async () => {
		global.fetch = jest.fn().mockRejectedValue(new Error('down'))
		const ctx = context({ availableObjects: ['old'] })

		await ctx.searchObjects()

		expect(ctx.availableObjects).toEqual([])
		expect(ctx.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Error searching objects:', expect.any(Error))
	})

	it('does not search without a register or schema', async () => {
		global.fetch = jest.fn()
		schemaStore.schemaItem = null

		await context().searchObjects()

		expect(global.fetch).not.toHaveBeenCalled()
	})
})

describe('MergeObject steps', () => {
	it('moves on only once a target is chosen, and back from step two', () => {
		const ctx = context()

		ctx.nextStep()
		expect(ctx.step).toBe(1)

		ctx.selectTargetObject(TARGET)
		ctx.nextStep()
		expect(ctx.step).toBe(2)
		expect(Object.keys(ctx.mergedData).length).toBeGreaterThan(0)

		ctx.previousStep()
		expect(ctx.step).toBe(1)
		ctx.previousStep()
		expect(ctx.step).toBe(1)
	})

	it('offers the union of both objects own properties, without @ or _ keys', () => {
		const ctx = context()
		expect(ctx.mergeableProperties).toEqual([])

		ctx.selectedTargetObject = TARGET
		expect(ctx.mergeableProperties).toEqual(['id', 'name', 'city', 'note', 'age'])
	})

	it('seeds each property: the id from the target, else target, else source, else custom', () => {
		const ctx = context({ selectedTargetObject: TARGET })

		ctx.initializeMergeData()

		expect(ctx.mergedData).toEqual({ id: 'tgt', name: 'Ada L.', city: 'Delft', note: 'custom', age: 36 })
		expect(ctx.customValues).toEqual({ note: '' })
		expect(ctx.propertySelections.name).toEqual({ label: 'From target: Ada L.', value: 'Ada L.' })
		expect(ctx.propertySelections.city).toEqual({ label: 'From source: Delft', value: 'Delft' })
		expect(ctx.propertySelections.note).toEqual({ label: 'Other value', value: 'custom' })
		expect(ctx.canMerge).toBe(true)
	})

	it('records a chosen option and clears the custom text when leaving custom', () => {
		const ctx = context({ customValues: { note: 'typed' } })

		ctx.onPropertySelectionChange('note', { label: 'x', value: 'custom' })
		expect(ctx.mergedData.note).toBe('custom')
		expect(ctx.customValues.note).toBe('typed')

		ctx.onPropertySelectionChange('note', { label: 'y', value: 'kept' })
		expect(ctx.mergedData.note).toBe('kept')
		expect(ctx.propertySelections.note).toEqual({ label: 'y', value: 'kept' })
		expect(ctx.customValues.note).toBe('')

		ctx.onPropertySelectionChange('note', null)
		ctx.onPropertySelectionChange('note', { label: 'z' })
		expect(ctx.mergedData.note).toBe('kept')
	})

	it('sends the target, the merged values and the three actions, without ids', () => {
		const ctx = context({
			selectedTargetObject: TARGET,
			mergedData: { id: 'tgt', '@self': {}, name: 'Ada', note: 'custom', city: { value: 'Leiden' }, empty: 'custom' },
			customValues: { note: 'merged by hand' },
			fileAction: 'delete',
		})

		expect(ctx.mergeRequest()).toEqual({
			target: 'tgt',
			object: { name: 'Ada', note: 'merged by hand', city: 'Leiden', empty: '' },
			fileAction: 'delete',
			relationAction: 'transfer',
			referenceAction: 'transfer',
		})
	})

	it('opens the merged target, and does nothing without one', () => {
		context().viewMergedObject()
		expect(objectStore.setObjectItem).not.toHaveBeenCalled()

		context({ selectedTargetObject: TARGET }).viewMergedObject()
		expect(objectStore.setObjectItem).toHaveBeenCalledWith(TARGET)
		expect(navigationStore.setModal).toHaveBeenCalledWith('viewObject')
	})
})

describe('MergeObject display helpers', () => {
	it('shows a value as text, JSON for objects, truncated past the limit', () => {
		const ctx = context()

		expect(ctx.displayValue(null)).toBe('N/A')
		expect(ctx.displayValue(undefined)).toBe('N/A')
		expect(ctx.displayValue(0)).toBe('0')
		expect(ctx.displayValue({ a: 1 })).toBe('{\n  "a": 1\n}')
		expect(ctx.displayValue('abcdef', 3)).toBe('abc...')
		expect(ctx.displayValue('x'.repeat(100))).toBe('x'.repeat(100))
	})

	it('labels an object by its name, title or id', () => {
		const ctx = context()

		expect(ctx.objectLabel({ '@self': { name: 'Self name' }, name: 'Own' })).toBe('Self name')
		expect(ctx.objectLabel({ name: 'Own', '@self': { title: 'T' } })).toBe('Own')
		expect(ctx.objectLabel({ title: 'Title' })).toBe('Title')
		expect(ctx.objectLabel({ '@self': { title: 'Self title' } })).toBe('Self title')
		expect(ctx.objectLabel({ '@self': { id: 'id-1' } })).toBe('id-1')
		expect(ctx.objectLabel(null)).toBeUndefined()
	})

	it('truncates text only past the limit', () => {
		const ctx = context()

		expect(ctx.truncateText('', 3)).toBe('')
		expect(ctx.truncateText(null, 3)).toBe('')
		expect(ctx.truncateText('abc', 3)).toBe('abc')
		expect(ctx.truncateText('abcd', 3)).toBe('abc...')
	})

	it('formats file sizes in binary units', () => {
		const ctx = context()

		expect(ctx.formatFileSize(0)).toBe('N/A')
		expect(ctx.formatFileSize(512)).toBe('512 Bytes')
		expect(ctx.formatFileSize(1536)).toBe('1.5 KB')
		expect(ctx.formatFileSize(5 * 1024 * 1024)).toBe('5 MB')
		expect(ctx.formatFileSize(3 * 1024 ** 3)).toBe('3 GB')
	})

	it('folds and unfolds each list', () => {
		const ctx = context()

		ctx.toggleFileList()
		ctx.toggleRelationList()
		ctx.toggleReferenceList()
		expect([ctx.showFileList, ctx.showRelationList, ctx.showReferenceList]).toEqual([false, false, false])

		ctx.toggleFileList()
		expect(ctx.showFileList).toBe(true)
	})
})
