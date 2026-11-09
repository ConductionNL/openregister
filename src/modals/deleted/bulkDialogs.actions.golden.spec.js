/**
 * Golden record of the bulk purge and restore dialogs' selection handling
 * and of the restore action: the store calls, events, timers and state.
 *
 * @spec exclude Golden record of client-side bulk dialog actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import PurgeMultiple from './PurgeMultiple.vue'
import RestoreMultiple from './RestoreMultiple.vue'
import eventBus from '../../eventBus.js'
import { deletedStore, navigationStore } from '../../store/store.js'
import { objectTitle } from './bulkSelection.js'

jest.mock('../../store/store.js', () => ({ deletedStore: {}, navigationStore: {} }))
jest.mock('../../eventBus.js', () => ({ emit: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one, many, count, vars) =>
		`${count === 1 ? one : many} ${JSON.stringify(vars)}`,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Restore.vue', () => ({}))

const log = []

/**
 * A bare dialog context with its methods and computeds bound to it.
 *
 * @param {object} component The dialog
 * @param {Array|null} selected The selected objects
 * @return {object} The context
 */
function context(component, selected) {
	// A dialog's options may come from its mixins as well as its own.
	const options = [...(component.mixins || []), component]
	const data = options.find((option) => option.data).data
	const methods = Object.assign({}, ...options.map((option) => option.methods || {}))
	const ctx = {
		...data.call({}),
		selectedObjects: selected,
		error: 'old error',
	}
	for (const [name, method] of Object.entries(methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(component.computed)) {
		Object.defineProperty(ctx, name, {
			get: () => getter.call(ctx),
			enumerable: false,
		})
	}
	return ctx
}

/**
 * The state a dialog writes.
 *
 * @param {object} ctx The context
 * @return {object} The state
 */
function state(ctx) {
	return {
		selected: ctx.selectedObjects,
		success: ctx.success,
		successMessage: ctx.successMessage,
		loading: ctx.loading,
		error: ctx.error,
		timer: ctx.closeModalTimeout !== null,
	}
}

const DIALOGS = {
	purge: [PurgeMultiple, 'permanentlyDeleteMultiple'],
	restore: [RestoreMultiple, 'restoreMultiple'],
}

describe('bulk dialogs actions golden', () => {
	beforeEach(() => {
		log.length = 0
		jest.useFakeTimers()
		eventBus.emit.mockImplementation((...args) => log.push(['emit', ...args]))
		navigationStore.setDialog = (value) => log.push(['setDialog', value])
		deletedStore.clearSelectedForBulkAction = () =>
			log.push(['clearSelectedForBulkAction'])
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.clearAllMocks()
	})

	for (const [name, [component, dialog]] of Object.entries(DIALOGS)) {
		for (const [label, stored] of Object.entries({
			none: undefined,
			empty: [],
			two: [{ id: 'a' }, { id: 'b' }],
		})) {
			it(`${name}: takes the stored selection ${label}`, () => {
				deletedStore.selectedForBulkAction = stored
				const ctx = context(component, [{ id: 'old' }])
				ctx.initializeSelection()
				expect({ log, state: state(ctx) }).toMatchSnapshot()
			})
		}

		it(`${name}: removes objects and closes when none are left`, () => {
			const ctx = context(component, [{ id: 'a' }, { id: 'b' }])
			ctx.removeObject('a')
			const between = state(ctx)
			ctx.removeObject('missing')
			ctx.removeObject('b')
			expect({ log, between, state: state(ctx) }).toMatchSnapshot()
		})

		it(`${name}: closes and resets`, () => {
			const ctx = context(component, [{ id: 'a' }])
			Object.assign(ctx, {
				success: true,
				successMessage: 'm',
				loading: true,
				error: 'e',
			})
			ctx.closeModalTimeout = setTimeout(
				() => log.push(['stale timer fired']),
				10,
			)
			ctx.closeDialog()
			jest.advanceTimersByTime(20)
			expect({ log, state: state(ctx) }).toMatchSnapshot()
		})

		it(`${name}: reacts to the dialog opening`, () => {
			const ctx = context(component, [])
			let calls = 0
			ctx.initializeSelection = () => calls++
			const watcher = [...(component.mixins || []), component]
				.find((option) => option.watch).watch['navigationStore.dialog']
			watcher.call(ctx, dialog, 'other')
			watcher.call(ctx, dialog, dialog)
			watcher.call(ctx, 'other', dialog)
			expect(calls).toBe(1)
		})

		it(`${name}: titles objects`, () => {
			const objects = [
				null,
				{},
				{ title: 'T', name: 'N' },
				{ fileName: 'f' },
				{ name: 'N' },
				{ object: { title: 'OT' } },
				{ object: { name: 'ON' } },
				{ object: { title: 'OT', name: 'ON' } },
				{ title: '', fileName: 'f', object: { title: 'OT' } },
				{ id: 7 },
				{ id: 0 },
			]
			expect(objects.map((object) => objectTitle(object))).toMatchSnapshot()
		})
	}

	for (const [label, selected, answer] of [
		['missing', null, () => Promise.resolve()],
		['empty', [], () => Promise.resolve()],
		['one', [{ id: 'a' }], () => Promise.resolve()],
		['two', [{ id: 'a' }, { id: 7 }], () => Promise.resolve()],
		['throwsNamed', [{ id: 'a' }], () => Promise.reject(new Error('Denied'))],
		['throwsSilent', [{ id: 'a' }], () => Promise.reject(new Error(''))],
	]) {
		it(`restore: restores ${label}`, async () => {
			const ctx = context(RestoreMultiple, selected)
			deletedStore.restoreMultiple = (ids) => {
				log.push(['restoreMultiple', ids, ctx.loading])
				return answer()
			}
			await ctx.restoreMultiple()
			const after = state(ctx)
			jest.advanceTimersByTime(1999)
			log.push(['after 1999 ms'])
			jest.advanceTimersByTime(1)
			expect({ log, after }).toMatchSnapshot()
		})
	}
})
