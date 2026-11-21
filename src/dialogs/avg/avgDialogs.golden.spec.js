/**
 * Golden record of the two AVG dialogs' writes: what saving a processing
 * activity (new or existing) and applying a redaction send to the store,
 * which event they emit, and what a refused write shows.
 *
 * @spec exclude Golden record of client-side AVG dialog writes.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditActivityDialog from './EditActivityDialog.vue'
import RedactionDialog from './RedactionDialog.vue'
import { avgStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('./AvgFormFooter.vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('../../store/modules/avg.js', () => ({
	LEGAL_BASIS_VOCABULARY: [],
	STATUS_VOCABULARY: [],
	resolveGroundOptions: () => [],
}))
jest.mock('../../store/store.js', () => ({ avgStore: {} }))

/**
 * A dialog context with the dialog's methods bound to it.
 *
 * @param {object} component The dialog options
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(component, state) {
	const emitted = []
	const ctx = { $emit: (...args) => emitted.push(args), emitted, ...state }
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const ANSWERS = {
	accepted: () => Promise.resolve({}),
	refusedWithStoreError: () => {
		avgStore.getError = 'Store says no'
		return Promise.reject(new Error('Forbidden'))
	},
	refused: () => Promise.reject(new Error('Forbidden')),
	failedWithoutMessage: () => Promise.reject({}),
}

beforeEach(() => {
	for (const key of Object.keys(avgStore)) delete avgStore[key]
})

describe('EditActivityDialog.onSave (golden)', () => {
	it.each(
		Object.keys(ANSWERS).flatMap((answer) => [
			['a new activity', answer],
			['an existing activity', answer],
		]),
	)('saves %s, %s', async (which, answer) => {
		const calls = []
		const record = (name) => (...args) => {
			calls.push([name, ...args])
			return ANSWERS[answer]()
		}
		avgStore.createActivity = record('create')
		avgStore.updateActivity = record('update')
		const ctx = context(EditActivityDialog, {
			activity: which === 'a new activity' ? null : { uuid: 'act-1' },
			form: { name: 'Payroll', purpose: '', legalBasis: null, status: 'active' },
			error: 'old',
		})
		const pending = ctx.onSave()
		const during = { saving: ctx.saving, error: ctx.error }
		await pending
		expect({ calls, during, saving: ctx.saving, error: ctx.error, emitted: ctx.emitted }).toMatchSnapshot()
	})
})

describe('RedactionDialog.onSubmit (golden)', () => {
	it.each(Object.keys(ANSWERS))('applies a redaction, %s', async (answer) => {
		const calls = []
		avgStore.applyRedaction = (...args) => {
			calls.push(args)
			return ANSWERS[answer]()
		}
		const ctx = context(RedactionDialog, {
			caseId: 'case-1',
			form: { field: 'email', after: '[redacted]', ground: 'art-10' },
			error: 'old',
			saving: false,
		})
		const pending = ctx.onSubmit()
		const during = { saving: ctx.saving, error: ctx.error }
		await pending
		expect({ calls, during, saving: ctx.saving, error: ctx.error, emitted: ctx.emitted }).toMatchSnapshot()
	})

	it.each([
		['without a field', { field: '', after: 'x', ground: 'g' }],
		['without a ground', { field: 'f', after: 'x', ground: '' }],
	])('refuses a redaction %s', async (name, form) => {
		avgStore.applyRedaction = jest.fn()
		const ctx = context(RedactionDialog, { caseId: 'c', form, saving: false, error: null })
		await ctx.onSubmit()
		expect(avgStore.applyRedaction).not.toHaveBeenCalled()
		expect(ctx.saving).toBe(false)
		expect(ctx.emitted).toEqual([])
	})
})
