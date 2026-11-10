/**
 * Golden record of the two transfer actions: MergeObject::performMerge and
 * MigrationObject::performMigration. Each is driven on a bare context through
 * success, a refused answer and a thrown failure, and the request it sends,
 * the state it leaves and what it logs are recorded.
 *
 * @spec exclude Golden record of client-side modal orchestration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MergeObject from './MergeObject.vue'
import MigrationObject from './MigrationObject.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'
import { refreshObjectLists } from './selectedObjectsDialog.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('./selectedObjectsDialog.js', () => ({
	__esModule: true,
	default: {},
	refreshObjectLists: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, t: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/Merge.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseExport.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => ({}))
jest.mock('../../components/shared/SelectedObjectsList.vue', () => ({}))

/**
 * A bare context of a component: its data, its methods bound, and the given
 * overrides.
 *
 * @param {object} component The component options
 * @param {object} overrides State to set on top of the data
 * @return {object} The context
 */
function context(component, overrides) {
	const ctx = { ...component.data.call({}), ...overrides }
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

let logged
beforeEach(() => {
	logged = []
	jest.spyOn(console, 'error').mockImplementation((...args) =>
		logged.push(
			args.map((arg) =>
				arg instanceof Error ? `Error(${arg.message})` : arg,
			),
		),
	)
	refreshObjectLists.mockClear()
})
afterEach(() => {
	jest.restoreAllMocks()
})

describe('MergeObject::performMerge (golden)', () => {
	const MERGED = {
		id: 'drop',
		'@self': 'drop',
		title: 'custom',
		labelled: { value: 'inner', label: 'Inner' },
		valueUndefined: { label: 'only label' },
		plain: 'kept',
		empty: null,
		emptyCustom: 'custom',
	}
	const ANSWERS = {
		merged: () => Promise.resolve({ success: true, statistics: {} }),
		refused: () => Promise.resolve(null),
		refusedNoError: () => Promise.resolve(false),
		thrown: () => Promise.reject(new Error('boom')),
		thrownNoMessage: () => Promise.reject(new Error('')),
	}

	it.each(Object.keys(ANSWERS))('records the %s merge', async (answer) => {
		const calls = []
		objectStore.currentType = 'object'
		objectStore.lifecycleError =
			answer === 'refused' ? { message: 'locked' } : null
		objectStore.mergeObjects = (...args) => {
			calls.push(args)
			return ANSWERS[answer]()
		}
		const ctx = context(MergeObject, {
			mergedData: { ...MERGED },
			customValues: { title: 'Custom title' },
			sourceObject: { '@self': { id: 'src' } },
			selectedTargetObject: { '@self': { id: 'tgt' } },
			fileAction: 'transfer',
			relationAction: 'transfer',
			referenceAction: 'update',
			step: 2,
		})
		Object.defineProperty(ctx, 'canMerge', { get: () => true })
		await ctx.performMerge()
		expect({
			calls,
			mergeResult: ctx.mergeResult,
			step: ctx.step,
			loading: ctx.loading,
			refreshed: refreshObjectLists.mock.calls.length,
			logged,
		}).toMatchSnapshot()
	})

	it('does nothing when the merge is not ready', async () => {
		objectStore.mergeObjects = jest.fn()
		const ctx = context(MergeObject, { step: 2 })
		Object.defineProperty(ctx, 'canMerge', { get: () => false })
		await ctx.performMerge()
		expect(objectStore.mergeObjects).not.toHaveBeenCalled()
		expect(ctx.loading).toBe(false)
		expect(ctx.step).toBe(2)
	})
})

describe('MigrationObject::performMigration (golden)', () => {
	const ANSWERS = {
		migrated: () => ({
			ok: true,
			json: async () => ({
				success: true,
				statistics: { objectsMigrated: 2 },
			}),
		}),
		refused: () => ({ ok: false, json: async () => ({ error: 'not allowed' }) }),
		refusedNoBody: () => ({
			ok: false,
			json: async () => {
				throw new Error('no json')
			},
		}),
		okNoBody: () => ({
			ok: true,
			json: async () => {
				throw new Error('no json')
			},
		}),
		thrown: () => {
			throw new Error('offline')
		},
	}

	it.each(Object.keys(ANSWERS))('records the %s migration', async (answer) => {
		const calls = []
		global.fetch = jest.fn(async (...args) => {
			calls.push(args)
			return ANSWERS[answer]()
		})
		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		const ctx = context(MigrationObject, {
			targetRegister: { id: 3 },
			targetSchema: { id: 4 },
			selectedObjects: [{ id: 'a' }, { id: 'b' }],
			uiMappings: {
				name: { value: 'title' },
				skipped: null,
				empty: { value: '' },
			},
			mapping: { stale: 'x' },
			step: 3,
		})
		Object.defineProperty(ctx, 'canMigrate', { get: () => true })
		await ctx.performMigration()
		expect({
			calls,
			mapping: ctx.mapping,
			migrationResult: ctx.migrationResult,
			step: ctx.step,
			loading: ctx.loading,
			refreshed: refreshObjectLists.mock.calls.length,
			logged,
		}).toMatchSnapshot()
	})

	it('does nothing when the migration is not ready', async () => {
		global.fetch = jest.fn()
		const ctx = context(MigrationObject, { step: 3, mapping: { kept: 'yes' } })
		Object.defineProperty(ctx, 'canMigrate', { get: () => false })
		await ctx.performMigration()
		expect(global.fetch).not.toHaveBeenCalled()
		expect(ctx.mapping).toEqual({ kept: 'yes' })
		expect(ctx.step).toBe(3)
	})
})
