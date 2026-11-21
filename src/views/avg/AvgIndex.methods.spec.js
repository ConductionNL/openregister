/**
 * AvgIndex — activities, DSAR, compliance and case-detail methods.
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

describe('AvgIndex — store-backed computed values', () => {
	beforeEach(() => {
		Object.assign(mockStore, {
			getDsarResults: [{ uuid: 'o1' }],
			getComplianceReport: { totals: { unannotatedSchemasWithPii: 2 } },
			isLoading: true,
			getError: 'Boom',
		})
	})

	it('reads DSAR results, the compliance report, loading and error from the store', () => {
		expect(computedCall('dsarResults', {})).toEqual([{ uuid: 'o1' }])
		expect(computedCall('complianceReport', {})).toEqual({ totals: { unannotatedSchemasWithPii: 2 } })
		expect(computedCall('loading', {})).toBe(true)
		expect(computedCall('error', {})).toBe('Boom')
	})

	it('offers every status after an "All statuses" option, labelled through statusLabel', () => {
		const ctx = { statusLabel: (s) => `label:${s}` }
		const options = computedCall('statusFilterOptions', ctx)

		expect(options[0]).toEqual({ value: null, label: 'All statuses' })
		expect(options.length).toBeGreaterThan(1)
		expect(options.slice(1).every((o) => o.label === `label:${o.value}`)).toBe(true)
	})

	it('offers each distinct handler once after an "All handlers" option', () => {
		const ctx = { cases: [{ handler: 'ann' }, { handler: 'bob' }, { handler: 'ann' }, { handler: '' }, {}] }

		expect(computedCall('handlerFilterOptions', ctx)).toEqual([
			{ value: null, label: 'All handlers' },
			{ value: 'ann', label: 'ann' },
			{ value: 'bob', label: 'bob' },
		])
	})

	it('shows finalise only when a finaliseDenial transition is available', () => {
		expect(computedCall('finaliseVisible', { availableTransitions: [{ action: 'assign' }, { action: 'finaliseDenial' }] })).toBe(true)
		expect(computedCall('finaliseVisible', { availableTransitions: [{ action: 'assign' }] })).toBe(false)
	})

	it('gives the case evidence and redactions only when they are lists', () => {
		const selectedCase = { evidence: [{ type: 'log' }], redactions: [{ field: 'name' }] }
		expect(computedCall('caseEvidence', { selectedCase })).toEqual([{ type: 'log' }])
		expect(computedCall('caseRedactions', { selectedCase })).toEqual([{ field: 'name' }])
		expect(computedCall('caseEvidence', { selectedCase: { evidence: 'x' } })).toEqual([])
		expect(computedCall('caseRedactions', { selectedCase: null })).toEqual([])
	})
})

describe('AvgIndex — activities and DSAR actions', () => {
	beforeEach(() => {
		Object.assign(mockStore, {
			fetchActivities: jest.fn().mockResolvedValue([]),
			fetchAccountability: jest.fn().mockResolvedValue(null),
			fetchCompliance: jest.fn().mockResolvedValue(null),
			runAccess: jest.fn().mockResolvedValue(null),
			runErasure: jest.fn().mockResolvedValue(null),
		})
	})

	it('formats a time, and leaves an empty one empty', () => {
		const value = '2026-11-14T10:00:00Z'
		expect(methodCall('formatTime', {}, value)).toBe(new Date(value).toLocaleString())
		expect(methodCall('formatTime', {}, '')).toBe('')
		expect(methodCall('formatTime', {}, null)).toBe('')
	})

	it('opens the dialog empty for a new activity and with a copy for an edit', () => {
		const ctx = { dialogOpen: false, dialogActivity: { uuid: 'old' } }
		methodCall('openCreateDialog', ctx)
		expect(ctx).toEqual({ dialogOpen: true, dialogActivity: null })

		const activity = { uuid: 'a1', name: 'Payroll' }
		methodCall('openEditDialog', ctx, activity)
		expect(ctx.dialogOpen).toBe(true)
		expect(ctx.dialogActivity).toEqual(activity)
		expect(ctx.dialogActivity).not.toBe(activity)
	})

	it('closes the dialog, and after a save also refreshes the activities', () => {
		const ctx = { dialogOpen: true, dialogActivity: { uuid: 'a1' }, refreshActivities: jest.fn() }
		ctx.closeDialog = () => methodCall('closeDialog', ctx)

		methodCall('onActivitySaved', ctx)

		expect(ctx.dialogOpen).toBe(false)
		expect(ctx.dialogActivity).toBeNull()
		expect(ctx.refreshActivities).toHaveBeenCalledTimes(1)
	})

	it('loads the accountability report and the compliance report from the store', async () => {
		await methodCall('loadAccountability', {})
		await methodCall('loadCompliance', {})

		expect(mockStore.fetchAccountability).toHaveBeenCalledTimes(1)
		expect(mockStore.fetchCompliance).toHaveBeenCalledTimes(1)
	})

	it('swallows a failed report load, which the store surfaces', async () => {
		mockStore.fetchAccountability.mockRejectedValue(new Error('down'))
		mockStore.fetchCompliance.mockRejectedValue(new Error('down'))

		await expect(methodCall('loadAccountability', {})).resolves.toBeUndefined()
		await expect(methodCall('loadCompliance', {})).resolves.toBeUndefined()
	})

	it('runs an access request for the subject, leaving out an empty type', async () => {
		await methodCall('runAccess', { dsar: { subject: 'jan@example.org', type: '' } })
		await methodCall('runAccess', { dsar: { subject: 'jan@example.org', type: 'email' } })

		expect(mockStore.runAccess.mock.calls).toEqual([
			[{ subject: 'jan@example.org', type: undefined }],
			[{ subject: 'jan@example.org', type: 'email' }],
		])
	})

	it('runs an erasure as a dry run only', async () => {
		await methodCall('runErasureDryRun', { dsar: { subject: 'jan', type: 'bsn' } })

		expect(mockStore.runErasure).toHaveBeenCalledWith({ subject: 'jan', type: 'bsn', dryRun: true })
	})

	it('swallows a failed access request or dry run', async () => {
		mockStore.runAccess.mockRejectedValue(new Error('refused'))
		mockStore.runErasure.mockRejectedValue(new Error('refused'))
		const ctx = { dsar: { subject: 'jan', type: '' } }

		await expect(methodCall('runAccess', ctx)).resolves.toBeUndefined()
		await expect(methodCall('runErasureDryRun', ctx)).resolves.toBeUndefined()
	})

	it('builds the subject query without an empty type', () => {
		expect(methodCall('subjectQuery', { dsar: { subject: 'jan', type: '' } })).toEqual({ subject: 'jan', type: undefined })
		expect(methodCall('subjectQuery', { dsar: { subject: 'jan', type: 'bsn' } })).toEqual({ subject: 'jan', type: 'bsn' })
	})
})

describe('AvgIndex — case list and case detail', () => {
	beforeEach(() => {
		Object.assign(mockStore, {
			getActiveCase: null,
			fetchCases: jest.fn().mockResolvedValue([]),
			fetchActivePolicyPack: jest.fn().mockResolvedValue(null),
			fetchCase: jest.fn().mockResolvedValue(null),
			updateCase: jest.fn().mockResolvedValue(null),
			collectEvidence: jest.fn().mockResolvedValue({}),
			escalateRegulator: jest.fn().mockResolvedValue({ status: 'sent', reference: 'AP-1' }),
		})
	})

	it('loads the cases on the first visit to the cases tab only', () => {
		const empty = { cases: [], refreshCases: jest.fn() }
		AvgIndex.watch.activeTab.call(empty, 'cases')
		AvgIndex.watch.activeTab.call(empty, 'dsar')
		expect(empty.refreshCases).toHaveBeenCalledTimes(1)

		const loaded = { cases: [{ uuid: 'c1' }], refreshCases: jest.fn() }
		AvgIndex.watch.activeTab.call(loaded, 'cases')
		expect(loaded.refreshCases).not.toHaveBeenCalled()
	})

	it('refreshes the cases with the filters set, and fetches the policy pack once', async () => {
		await methodCall('refreshCases', { caseFilters: { status: 'received', handler: 'ann', overdue: true }, activePolicyPack: null })
		await methodCall('refreshCases', { caseFilters: { status: null, handler: null, overdue: false }, activePolicyPack: { pack: 1 } })

		expect(mockStore.fetchCases.mock.calls).toEqual([
			[{ status: 'received', handler: 'ann', isOverdue: 'true' }],
			[{}],
		])
		expect(mockStore.fetchActivePolicyPack).toHaveBeenCalledTimes(1)
	})

	it('swallows a failed case refresh', async () => {
		mockStore.fetchCases.mockRejectedValue(new Error('down'))

		await expect(methodCall('refreshCases', { caseFilters: {}, activePolicyPack: {} })).resolves.toBeUndefined()
	})

	it('selects a case: resets the panels, loads it, then its jurisdiction\'s pack', async () => {
		const ctx = {
			selectedCaseId: null,
			identityResult: { status: 'ok' },
			escalateResult: { status: 'sent' },
			regulatorReferenceDraft: 'draft',
			selectedCase: { jurisdiction: 'NL' },
			caseId: (c) => methodCall('caseId', {}, c),
		}

		await methodCall('selectCase', ctx, { '@self': { id: 'c9' } })

		expect(ctx.selectedCaseId).toBe('c9')
		expect(ctx.identityResult).toBeNull()
		expect(ctx.escalateResult).toBeNull()
		expect(ctx.regulatorReferenceDraft).toBe('')
		expect(mockStore.fetchCase).toHaveBeenCalledWith('c9')
		expect(mockStore.fetchActivePolicyPack).toHaveBeenCalledWith({ jurisdiction: 'NL' })
	})

	it('goes back to the list and clears the case panels', () => {
		const ctx = { selectedCaseId: 'c1', identityResult: {}, escalateResult: {} }

		methodCall('backToList', ctx)

		expect(ctx).toEqual({ selectedCaseId: null, identityResult: null, escalateResult: null })
	})

	it('labels a transition from its camel-cased action', () => {
		expect(methodCall('transitionLabel', {}, 'finaliseDenial')).toBe('Finalise Denial')
		expect(methodCall('transitionLabel', {}, 'assign')).toBe('Assign')
	})

	it('gives a tier its class, on-track when there is none', () => {
		expect(methodCall('tierClass', {}, 'breached')).toBe('tier tier-breached')
		expect(methodCall('tierClass', {}, null)).toBe('tier tier-on-track')
	})

	it('describes the deadline: overdue, days left, or unknown', () => {
		expect(methodCall('deadlineText', {}, { isOverdue: true, daysRemaining: 3 })).toBe('Overdue')
		expect(methodCall('deadlineText', {}, { isOverdue: false, daysRemaining: 3 })).toBe('3 day(s) left')
		expect(methodCall('deadlineText', {}, { isOverdue: false })).toBe('—')
	})

	it('describes the days remaining, counting overdue days as positive', () => {
		expect(methodCall('daysRemainingText', {}, {})).toBe('—')
		expect(methodCall('daysRemainingText', {}, { daysRemaining: -4 })).toBe('Overdue by 4 day(s)')
		expect(methodCall('daysRemainingText', {}, { isOverdue: true, daysRemaining: 0 })).toBe('Overdue by 0 day(s)')
		expect(methodCall('daysRemainingText', {}, { daysRemaining: 12 })).toBe('12 day(s) remaining')
	})

	it('records a regulator reference, clears the draft and reloads the case', async () => {
		const ctx = { selectedCaseId: 'c1', regulatorReferenceDraft: 'AP-2026-1', reloadSelectedCase: jest.fn().mockResolvedValue() }

		await methodCall('recordRegulatorReference', ctx)

		expect(mockStore.updateCase).toHaveBeenCalledWith('c1', { regulatorReference: 'AP-2026-1' })
		expect(ctx.regulatorReferenceDraft).toBe('')
		expect(ctx.reloadSelectedCase).toHaveBeenCalledTimes(1)
	})

	it('records nothing without a draft, and keeps the draft when the update fails', async () => {
		const empty = { selectedCaseId: 'c1', regulatorReferenceDraft: '', reloadSelectedCase: jest.fn() }
		await methodCall('recordRegulatorReference', empty)
		expect(mockStore.updateCase).not.toHaveBeenCalled()

		mockStore.updateCase.mockRejectedValue(new Error('refused'))
		const failing = { selectedCaseId: 'c1', regulatorReferenceDraft: 'AP-1', reloadSelectedCase: jest.fn() }
		await methodCall('recordRegulatorReference', failing)
		expect(failing.regulatorReferenceDraft).toBe('AP-1')
		expect(failing.reloadSelectedCase).not.toHaveBeenCalled()
	})

	it('closes both case dialogs after a mutation and reloads the case', async () => {
		const ctx = { denialDialogOpen: true, redactionDialogOpen: true, reloadSelectedCase: jest.fn().mockResolvedValue() }

		await methodCall('onCaseMutated', ctx)

		expect(ctx.denialDialogOpen).toBe(false)
		expect(ctx.redactionDialogOpen).toBe(false)
		expect(ctx.reloadSelectedCase).toHaveBeenCalledTimes(1)
	})

	it('harvests evidence for the case and reloads it; a failure reloads nothing', async () => {
		const ctx = { selectedCaseId: 'c1', reloadSelectedCase: jest.fn().mockResolvedValue() }
		await methodCall('harvestEvidence', ctx)
		expect(mockStore.collectEvidence).toHaveBeenCalledWith('c1')
		expect(ctx.reloadSelectedCase).toHaveBeenCalledTimes(1)

		mockStore.collectEvidence.mockRejectedValue(new Error('down'))
		await methodCall('harvestEvidence', ctx)
		expect(ctx.reloadSelectedCase).toHaveBeenCalledTimes(1)
	})

	it('escalates to the regulator and keeps the answer for the case panel', async () => {
		const ctx = { selectedCaseId: 'c1', escalateResult: null, reloadSelectedCase: jest.fn().mockResolvedValue() }

		await methodCall('runEscalate', ctx)

		expect(mockStore.escalateRegulator).toHaveBeenCalledWith('c1')
		expect(ctx.escalateResult).toEqual({ status: 'sent', reference: 'AP-1' })
		expect(ctx.reloadSelectedCase).toHaveBeenCalledTimes(1)
	})

	it('keeps no escalation answer when the escalation fails', async () => {
		mockStore.escalateRegulator.mockRejectedValue(new Error('refused'))
		const ctx = { selectedCaseId: 'c1', escalateResult: null, reloadSelectedCase: jest.fn() }

		await methodCall('runEscalate', ctx)

		expect(ctx.escalateResult).toBeNull()
		expect(ctx.reloadSelectedCase).not.toHaveBeenCalled()
	})
})
