/**
 * AvgIndex — Cases surface unit tests.
 *
 * The repo's installed @vue/test-utils is the Vue 3 build while the app
 * runs on Vue 2.7, so (mirroring TranslationStatusChip.spec.js) we exercise
 * the component's options object — computed getters + methods — directly
 * against a synthetic `this` context rather than mounting.
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
// vue-material-design-icons ships ESM .vue files that this repo's jest
// transform chain does not compile (no existing spec imports icons). Stub
// each imported icon so the component options object can be required.
jest.mock(
	'vue-material-design-icons/AccountCheckOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/AccountSearch.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/AlertCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/AlertOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Archive.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ArrowLeft.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/BankOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/CheckCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/CheckboxMarkedCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ClipboardTextClockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ClockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/DatabaseSearchOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Download.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/EyeOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/FileDocumentEditOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/FileDocumentOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Magnify.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/MagnifyClose.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Marker.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/OpenInNew.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Pencil.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Plus.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Refresh.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ShieldAlertOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ShieldLockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)

const computedCall = (key, ctx) => AvgIndex.computed[key].call(ctx)
const methodCall = (key, ctx, ...args) => AvgIndex.methods[key].call(ctx, ...args)

const PACK = {
	escalationTiers: [
		{ tier: 'Reminder' },
		{ tier: 'Escalation' },
		{ tier: 'Breach' },
	],
	denialGrounds: [
		{
			key: 'third-party-rights',
			label: 'Third-party rights',
			citation: 'Art 15(4)',
		},
	],
}

describe('AvgIndex — Cases surface', () => {
	beforeEach(() => {
		Object.assign(mockStore, {
			getCases: [],
			getActiveCase: null,
			getActivePolicyPack: PACK,
			isLoading: false,
			getError: null,
			fetchCases: jest.fn().mockResolvedValue([]),
			fetchActivePolicyPack: jest.fn().mockResolvedValue(null),
			fetchCase: jest.fn().mockResolvedValue(null),
			transitionCase: jest.fn().mockResolvedValue(null),
			collectEvidence: jest.fn().mockResolvedValue({}),
			updateCase: jest.fn().mockResolvedValue(null),
			verifyIdentity: jest.fn().mockResolvedValue({ status: 'needs-more' }),
			escalateRegulator: jest.fn().mockResolvedValue({ status: 'refused' }),
		})
	})

	it('adds a Cases tab without dropping the existing tabs', () => {
		const ids = computedCall('tabs', {}).map((t) => t.id)
		expect(ids).toEqual([
			'activities',
			'accountability',
			'dsar',
			'cases',
			'compliance',
		])
	})

	it('filteredCases narrows by status, handler, and overdue', () => {
		const ctx = {
			cases: [
				{ uuid: 'a', status: 'received', handler: 'h1', isOverdue: false },
				{ uuid: 'b', status: 'verifying', handler: 'h2', isOverdue: true },
				{ uuid: 'c', status: 'verifying', handler: 'h1', isOverdue: true },
			],
			caseFilters: { status: 'verifying', handler: null, overdue: true },
		}
		const rows = computedCall('filteredCases', ctx)
		expect(rows.map((r) => r.uuid)).toEqual(['b', 'c'])

		ctx.caseFilters = { status: null, handler: 'h1', overdue: false }
		expect(computedCall('filteredCases', ctx).map((r) => r.uuid)).toEqual([
			'a',
			'c',
		])
	})

	it('availableTransitions offers only declared transitions from the current state', () => {
		const ctx = { selectedCase: { status: 'denial-drafted' } }
		const actions = computedCall('availableTransitions', ctx).map(
			(t) => t.action,
		)
		expect(actions).toContain('finaliseDenial')
		expect(actions).not.toContain('collectEvidence')
	})

	it('finalise gating reflects the presence of a regulatorReference', () => {
		expect(
			computedCall('hasRegulatorReference', {
				selectedCase: { regulatorReference: '' },
			}),
		).toBe(false)
		expect(
			computedCall('hasRegulatorReference', {
				selectedCase: { regulatorReference: 'AP-2026-1' },
			}),
		).toBe(true)
	})

	it('statusLabel / tierLabel / groundLabel resolve from the active pack', () => {
		const ctx = { activePolicyPack: PACK }
		expect(methodCall('tierLabel', ctx, 'breached')).toBe('Breach')
		expect(methodCall('groundLabel', ctx, 'third-party-rights')).toBe(
			'Third-party rights',
		)
		expect(methodCall('statusLabel', ctx, 'evidence-collection')).toBe(
			'Evidence collection',
		)
	})

	it('tierIcon conveys tier by icon (not colour alone)', () => {
		expect(methodCall('tierIcon', {}, 'breached')).toBe('AlertCircleOutline')
		expect(methodCall('tierIcon', {}, 'on-track')).toBe(
			'CheckboxMarkedCircleOutline',
		)
	})

	it('onTransition posts a plain transition and reloads the case', async () => {
		const ctx = {
			selectedCaseId: 'c1',
			reloadSelectedCase: jest.fn().mockResolvedValue(),
			openDenialDialog: jest.fn(),
			openRedactionDialog: jest.fn(),
		}
		await methodCall('onTransition', ctx, { action: 'assign' })
		expect(mockStore.transitionCase).toHaveBeenCalledWith('c1', 'assign')
		expect(ctx.reloadSelectedCase).toHaveBeenCalled()
	})

	it('onTransition routes draftDenial to its dialog instead of posting', async () => {
		const ctx = {
			selectedCaseId: 'c1',
			reloadSelectedCase: jest.fn(),
			openDenialDialog: jest.fn(),
			openRedactionDialog: jest.fn(),
		}
		await methodCall('onTransition', ctx, { action: 'draftDenial' })
		expect(ctx.openDenialDialog).toHaveBeenCalled()
		expect(mockStore.transitionCase).not.toHaveBeenCalled()
	})

	it('runVerifyIdentity renders the seam fail-closed result faithfully', async () => {
		const ctx = {
			selectedCaseId: 'c1',
			reloadSelectedCase: jest.fn().mockResolvedValue(),
			identityResult: null,
		}
		await methodCall('runVerifyIdentity', ctx)
		expect(ctx.identityResult).toEqual({ status: 'needs-more' })
	})

	it('seamNoteType flags a non-success seam result as a warning', () => {
		expect(methodCall('seamNoteType', {}, 'verified', 'verified')).toBe(
			'success',
		)
		expect(methodCall('seamNoteType', {}, 'needs-more', 'verified')).toBe(
			'warning',
		)
		expect(methodCall('seamNoteType', {}, 'refused', 'escalated')).toBe(
			'warning',
		)
	})
})
