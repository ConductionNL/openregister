/**
 * Golden record of the two MDM modals' own logic: which source records the
 * conflict modal reads and which disagreeing values it offers, and the merge
 * wizard's starting state and what confirming a merge does.
 *
 * @spec exclude Golden record of client-side MDM modal logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MdmConflictResolutionModal from './MdmConflictResolutionModal.vue'
import MdmMergeWizardModal from './MdmMergeWizardModal.vue'
import { qualityStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircleOutline.vue', () => ({}))
jest.mock('../../components/shared/BusyButton.vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
}))
jest.mock('../../store/store.js', () => ({ qualityStore: {} }))

const conflictComputed = MdmConflictResolutionModal.computed

/**
 * The source records the conflict modal reads from an object.
 *
 * @param {object} object The master object
 * @return {Array<object>} The records
 */
function sourceRecords(object) {
	return conflictComputed.sourceRecords.call({ object })
}

/**
 * The conflicts the modal offers for some source records.
 *
 * @param {Array<object>} records The source records
 * @return {Array<object>} The conflicts
 */
function conflicts(records) {
	return conflictComputed.conflicts.call({
		sourceRecords: records,
		displayValue: MdmConflictResolutionModal.methods.displayValue,
	})
}

describe('MdmConflictResolutionModal (golden)', () => {
	it.each([
		['no object', null],
		['a string', 'x'],
		['a sources list', { sources: [{ sourceSystem: 'a' }, null, 'x', { b: 1 }] }],
		['sources that are not a list', { sources: 'x', links: [{ sourceSystem: 's' }] }],
		[
			'the first list of source entries',
			{
				empty: [],
				mixed: [{ sourceSystem: 'a' }, { other: 1 }],
				plain: [1, 2],
				hit: [{ sourceSystem: 'b' }, { sourceSystem: 'c' }],
				later: [{ sourceSystem: 'd' }],
			},
		],
		['no list of source entries', { a: [{ x: 1 }], b: 'y' }],
	])('reads the source records of %s', (name, object) => {
		expect(sourceRecords(object)).toMatchSnapshot()
	})

	it('offers only the attributes whose sources disagree', () => {
		expect(
			conflicts([
				{
					sourceSystem: 'crm',
					values: { name: 'Ann', city: 'Ede', zip: '', tags: ['a'], age: 3 },
				},
				{
					sourceSystem: 'erp',
					mappedAttributes: { name: 'Anne', city: 'Ede', zip: null, tags: ['b'] },
				},
				{ values: { name: 'Ann', age: 4, note: undefined } },
				{ sourceSystem: 'web', values: 'not an object' },
				{ sourceSystem: 'none', values: null, mappedAttributes: { age: 3 } },
			]),
		).toMatchSnapshot()
	})

	it('offers nothing without records', () => {
		expect(conflicts([])).toEqual([])
	})
})

/**
 * A wizard context with the wizard's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function wizard(state) {
	const emitted = []
	const ctx = {
		...MdmMergeWizardModal.data.call({}),
		from: 'a',
		into: 'b',
		$emit: (...args) => emitted.push(args),
		emitted,
		...state,
	}
	ctx.canConfirm = MdmMergeWizardModal.computed.canConfirm.call(ctx)
	for (const [name, method] of Object.entries(MdmMergeWizardModal.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe('MdmMergeWizardModal (golden)', () => {
	it('starts loading, without a reason, offering four reasons', () => {
		expect(MdmMergeWizardModal.data.call({})).toMatchSnapshot()
	})

	it.each([
		['merges', () => Promise.resolve({ merged: true })],
		['is refused', () => Promise.reject({ response: { data: { error: 'Locked' } } })],
		['fails', () => Promise.reject(new Error('offline'))],
	])('confirms a merge that %s', async (name, answer) => {
		const calls = []
		qualityStore.executeMerge = (...args) => {
			calls.push(args)
			return answer()
		}
		const ctx = wizard({
			preview: {},
			reasonModel: { id: 'manual-bulk' },
			error: 'old',
		})
		const pending = ctx.confirm()
		const during = { executing: ctx.executing, error: ctx.error }
		await pending
		expect({
			calls,
			during,
			executing: ctx.executing,
			error: ctx.error,
			emitted: ctx.emitted,
		}).toMatchSnapshot()
	})

	it('does nothing it cannot confirm', async () => {
		qualityStore.executeMerge = jest.fn()
		const ctx = wizard({ preview: null, reasonModel: { id: 'x' } })
		await ctx.confirm()
		expect(qualityStore.executeMerge).not.toHaveBeenCalled()
		expect(ctx.executing).toBe(false)
	})
})
