/**
 * AvgIndex — the case detail: picking the selected case, filtering by overdue,
 * the transitions offered, running a transition, recording a regulator
 * reference and reloading the case.
 *
 * Like AvgIndex.spec.js this exercises the options object (computed getters,
 * watchers and methods) against a synthetic `this`, with the AVG store mocked.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

// Mock the store singleton the component imports so we can assert dispatches.
// The object is created inside the (hoisted) factory to avoid a TDZ, then
// referenced in the tests via the mocked module export.
import AvgIndex from './AvgIndex.vue'
import { avgStore as mockStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	avgStore: {},
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((p) => p),
}))
// Stub @nextcloud/vue wholesale — we exercise the options object, not the
// rendered DOM, so the heavy real component chain (auth/logger/storage) is
// unnecessary and otherwise breaks under jsdom.
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)
// Stub each imported icon so the rendered markup stays independent of the
// icon library.
jest.mock(
	'vue-material-design-icons/AccountCheckOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/AccountSearch.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/AlertCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/AlertOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Archive.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/ArrowLeft.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/BankOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/CheckCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/CheckboxMarkedCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/ClipboardTextClockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/ClockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/DatabaseSearchOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Download.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/EyeOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/FileDocumentEditOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/FileDocumentOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Magnify.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/MagnifyClose.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Marker.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/OpenInNew.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Pencil.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Plus.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Refresh.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/ShieldAlertOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/ShieldLockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)

const computedCall = (key, ctx) => AvgIndex.computed[key].call(ctx)
const methodCall = (key, ctx, ...args) => AvgIndex.methods[key].call(ctx, ...args)

/**
 * A case-detail context with the view's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function view(state = {}) {
	const ctx = {
		selectedCaseId: 'c-2',
		cases: [],
		caseFilters: { status: '', handler: '', overdue: false },
		regulatorReferenceDraft: '',
		denialDialogOpen: false,
		redactionDialogOpen: false,
		...state,
	}
	for (const name of ['caseId', 'reloadSelectedCase', 'openDenialDialog', 'openRedactionDialog', 'onTransition', 'recordRegulatorReference']) {
		ctx[name] = AvgIndex.methods[name].bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	for (const key of Object.keys(mockStore)) delete mockStore[key]
	Object.assign(mockStore, {
		getActiveCase: null,
		getDsarSummary: { total: 3 },
		fetchCase: jest.fn().mockResolvedValue(),
		transitionCase: jest.fn().mockResolvedValue(),
		updateCase: jest.fn().mockResolvedValue(),
	})
})

describe('AvgIndex — the selected case', () => {
	it('reads the DSAR summary from the store', () => {
		expect(computedCall('dsarSummary', {})).toEqual({ total: 3 })
	})

	it('prefers the store\'s active case, then the listed case with the selected id', () => {
		const listed = [{ uuid: 'c-1' }, { '@self': { id: 'c-2' }, status: 'received' }]

		expect(computedCall('selectedCase', view({ cases: listed }))).toBe(listed[1])

		mockStore.getActiveCase = { uuid: 'c-2', status: 'verifying' }
		expect(computedCall('selectedCase', view({ cases: listed }))).toBe(mockStore.getActiveCase)

		mockStore.getActiveCase = null
		expect(computedCall('selectedCase', view({ cases: [{ uuid: 'c-9' }] }))).toBeNull()
		expect(computedCall('selectedCase', view({ selectedCaseId: '', cases: listed }))).toBeNull()
	})

	it('keeps only overdue cases when that filter is on', () => {
		const cases = [{ uuid: 'a', isOverdue: true }, { uuid: 'b', isOverdue: false }, { uuid: 'c' }]

		expect(computedCall('filteredCases', view({ cases, caseFilters: { overdue: true } })).map((c) => c.uuid)).toEqual(['a'])
		expect(computedCall('filteredCases', view({ cases, caseFilters: { overdue: false } }))).toHaveLength(3)
	})

	it('offers no transitions without a status, and only those leaving the current status', () => {
		expect(computedCall('availableTransitions', { selectedCase: null })).toEqual([])
		expect(computedCall('availableTransitions', { selectedCase: {} })).toEqual([])

		const offered = computedCall('availableTransitions', { selectedCase: { status: 'received' } })
		expect(offered.length).toBeGreaterThan(0)
		expect(offered.every((tr) => tr.from.includes('received'))).toBe(true)
		expect(offered.map((tr) => tr.action)).toContain('startVerifying')
	})
})

describe('AvgIndex — acting on the case', () => {
	it('opens the denial and redaction dialogs instead of transitioning', async () => {
		const ctx = view()

		await ctx.onTransition({ action: 'draftDenial' })
		await ctx.onTransition({ action: 'redact' })

		expect(ctx.denialDialogOpen).toBe(true)
		expect(ctx.redactionDialogOpen).toBe(true)
		expect(mockStore.transitionCase).not.toHaveBeenCalled()
	})

	it('runs any other transition on the selected case and reloads it', async () => {
		await view().onTransition({ action: 'startProgress' })

		expect(mockStore.transitionCase).toHaveBeenCalledWith('c-2', 'startProgress')
		expect(mockStore.fetchCase).toHaveBeenCalledWith('c-2')
	})

	it('swallows a refused transition without reloading', async () => {
		mockStore.transitionCase.mockRejectedValue(new Error('guard'))

		await expect(view().onTransition({ action: 'close' })).resolves.toBeUndefined()
		expect(mockStore.fetchCase).not.toHaveBeenCalled()
	})

	it('records the regulator reference, clears the draft and reloads', async () => {
		const ctx = view({ regulatorReferenceDraft: 'AP-2026-1' })

		await ctx.recordRegulatorReference()

		expect(mockStore.updateCase).toHaveBeenCalledWith('c-2', { regulatorReference: 'AP-2026-1' })
		expect(ctx.regulatorReferenceDraft).toBe('')
		expect(mockStore.fetchCase).toHaveBeenCalledWith('c-2')
	})

	it('records nothing without a draft, and keeps the draft when the update fails', async () => {
		await view().recordRegulatorReference()
		expect(mockStore.updateCase).not.toHaveBeenCalled()

		mockStore.updateCase.mockRejectedValue(new Error('down'))
		const ctx = view({ regulatorReferenceDraft: 'AP-1' })
		await ctx.recordRegulatorReference()
		expect(ctx.regulatorReferenceDraft).toBe('AP-1')
	})

	it('reloads only with a selected case, and survives a failing reload', async () => {
		await view({ selectedCaseId: '' }).reloadSelectedCase()
		expect(mockStore.fetchCase).not.toHaveBeenCalled()

		mockStore.fetchCase.mockRejectedValue(new Error('down'))
		await expect(view().reloadSelectedCase()).resolves.toBeUndefined()
	})

	it('shows an unparseable timestamp as it came, and an empty one as nothing', () => {
		const spy = jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(() => { throw new RangeError('bad') })
		expect(methodCall('formatTime', {}, 'not-a-date')).toBe('not-a-date')
		spy.mockRestore()
		expect(methodCall('formatTime', {}, '')).toBe('')
	})
})
