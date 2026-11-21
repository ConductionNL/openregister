/**
 * The AVG dialogs' own logic: the denial composer's ground lookup, template
 * reference and submit outcomes, and the processing-activity form's defaults,
 * options and category text fields.
 *
 * @spec exclude Unit coverage of client-side AVG dialog logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DenialComposerDialog from './DenialComposerDialog.vue'
import EditActivityDialog from './EditActivityDialog.vue'
import RedactionDialog from './RedactionDialog.vue'
import { resolveGroundOptions, resolveTemplateRef } from '../../store/modules/avg.js'
import { avgStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('./AvgFormFooter.vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('../../store/modules/avg.js', () => ({
	LEGAL_BASIS_VOCABULARY: ['public_task', 'legal_obligation'],
	STATUS_VOCABULARY: ['concept', 'active'],
	resolveGroundOptions: jest.fn(),
	resolveTemplateRef: jest.fn(),
}))
jest.mock('../../store/store.js', () => ({ avgStore: {} }))

/**
 * A component context with its data, methods and computed getters bound.
 *
 * @param {object} component The component options
 * @param {object} props The props
 * @return {object} The context
 */
function context(component, props = {}) {
	const emitted = []
	const ctx = { $emit: (...args) => emitted.push(args), emitted, ...props }
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	Object.assign(ctx, component.data.call(ctx))
	for (const [name, def] of Object.entries(component.computed)) {
		const get = typeof def === 'function' ? def : def.get
		Object.defineProperty(ctx, name, {
			get: get.bind(ctx),
			set: def.set ? def.set.bind(ctx) : undefined,
			configurable: true,
		})
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	for (const key of Object.keys(avgStore)) delete avgStore[key]
})

describe('DenialComposerDialog', () => {
	const GROUNDS = [
		{ value: 'art15-4', label: 'Rights of others', citation: 'Art. 15(4)' },
		{ value: 'art12-5', label: 'Manifestly unfounded', citation: 'Art. 12(5)' },
	]

	beforeEach(() => {
		resolveGroundOptions.mockReturnValue(GROUNDS)
	})

	it('starts with no ground, not saving and no error', () => {
		const ctx = context(DenialComposerDialog, { caseId: 'c-1', pack: null })
		expect(ctx).toMatchObject({ ground: '', saving: false, error: null })
	})

	it('offers the grounds of its pack', () => {
		const pack = { id: 'nl' }
		const ctx = context(DenialComposerDialog, { caseId: 'c-1', pack })
		expect(ctx.groundOptions).toBe(GROUNDS)
		expect(resolveGroundOptions).toHaveBeenCalledWith(pack)
	})

	it('finds the selected ground, or none', () => {
		const ctx = context(DenialComposerDialog, { caseId: 'c-1', pack: null })
		expect(ctx.selectedGround).toBeNull()
		ctx.ground = 'art12-5'
		expect(ctx.selectedGround).toBe(GROUNDS[1])
		ctx.ground = 'unknown'
		expect(ctx.selectedGround).toBeNull()
	})

	it('resolves the denial template of its pack', () => {
		resolveTemplateRef.mockReturnValue('tpl-denial')
		const pack = { id: 'nl' }
		const ctx = context(DenialComposerDialog, { caseId: 'c-1', pack })
		expect(ctx.templateRef).toBe('tpl-denial')
		expect(resolveTemplateRef).toHaveBeenCalledWith(pack, 'denial')
	})

	it('does not submit without a ground', async () => {
		avgStore.draftDenial = jest.fn()
		const ctx = context(DenialComposerDialog, { caseId: 'c-1' })
		await ctx.onSubmit()
		expect(avgStore.draftDenial).not.toHaveBeenCalled()
		expect(ctx.saving).toBe(false)
		expect(ctx.emitted).toEqual([])
	})

	it('drafts the denial for the case and ground, then emits drafted', async () => {
		let during
		const ctx = context(DenialComposerDialog, { caseId: 'c-1' })
		avgStore.draftDenial = jest.fn(() => {
			during = ctx.saving
			return Promise.resolve()
		})
		ctx.ground = 'art15-4'
		ctx.error = 'old'
		await ctx.onSubmit()
		expect(avgStore.draftDenial).toHaveBeenCalledWith('c-1', 'art15-4')
		expect(during).toBe(true)
		expect(ctx.emitted).toEqual([['drafted']])
		expect(ctx).toMatchObject({ saving: false, error: null })
	})

	it.each([
		['the store error first', 'Store says no', new Error('Forbidden'), 'Store says no'],
		['the exception message', undefined, new Error('Forbidden'), 'Forbidden'],
		['a fallback', undefined, {}, 'Draft failed'],
	])('shows %s on a refused draft', async (name, storeError, thrown, shown) => {
		if (storeError !== undefined) avgStore.getError = storeError
		avgStore.draftDenial = jest.fn(() => Promise.reject(thrown))
		const ctx = context(DenialComposerDialog, { caseId: 'c-1' })
		ctx.ground = 'art15-4'
		await ctx.onSubmit()
		expect(ctx.error).toBe(shown)
		expect(ctx.saving).toBe(false)
		expect(ctx.emitted).toEqual([])
	})
})

describe('EditActivityDialog form', () => {
	it('seeds a new activity with the Art-30 defaults', () => {
		const ctx = context(EditActivityDialog, { activity: null })
		expect(ctx.form).toEqual({
			name: '',
			code: '',
			description: '',
			purpose: '',
			legalBasis: 'public_task',
			retentionPeriod: '',
			status: 'concept',
			dataSubjectCategories: [],
			personalDataCategories: [],
			technicalMeasures: '',
			organisationalMeasures: '',
		})
		expect(ctx).toMatchObject({ saving: false, error: null })
	})

	it('seeds the form from an existing activity', () => {
		const activity = {
			uuid: 'u-1',
			name: 'Payroll',
			code: 'HR-1',
			legalBasis: 'legal_obligation',
			status: 'active',
			dataSubjectCategories: ['employees'],
		}
		const ctx = context(EditActivityDialog, { activity })
		expect(ctx.form).toMatchObject({
			name: 'Payroll',
			code: 'HR-1',
			description: '',
			legalBasis: 'legal_obligation',
			status: 'active',
			dataSubjectCategories: ['employees'],
			personalDataCategories: [],
		})
	})

	it('titles the dialog by whether it edits or creates', () => {
		expect(context(EditActivityDialog, { activity: null }).dialogTitle).toBe('New processing activity')
		expect(context(EditActivityDialog, { activity: { uuid: 'u' } }).dialogTitle).toBe('Edit processing activity')
	})

	it('labels the legal bases without underscores and the statuses as they are', () => {
		const ctx = context(EditActivityDialog, { activity: null })
		expect(ctx.legalBasisOptions).toEqual([
			{ value: 'public_task', label: 'public task' },
			{ value: 'legal_obligation', label: 'legal obligation' },
		])
		expect(ctx.statusOptions).toEqual([
			{ value: 'concept', label: 'concept' },
			{ value: 'active', label: 'active' },
		])
	})

	it.each([
		['dataSubjectCategoriesText', 'dataSubjectCategories'],
		['personalDataCategoriesText', 'personalDataCategories'],
	])('%s joins the list into lines and splits lines back', (text, field) => {
		const ctx = context(EditActivityDialog, { activity: null })
		ctx.form[field] = ['a', 'b']
		expect(ctx[text]).toBe('a\nb')
		ctx[text] = ' one \n\n two\n  \n'
		expect(ctx.form[field]).toEqual(['one', 'two'])
		ctx[text] = null
		expect(ctx.form[field]).toEqual([])
		ctx.form[field] = 'not a list'
		expect(ctx[text]).toBe('')
	})
})

describe('RedactionDialog', () => {
	it('opens with an empty form, idle and without an error, and refuses to submit it', async () => {
		avgStore.applyRedaction = jest.fn()
		const ctx = context(RedactionDialog, { caseId: 'case-1' })

		expect(ctx.form).toEqual({ field: '', after: '', ground: '' })
		expect(ctx.saving).toBe(false)
		expect(ctx.error).toBeNull()

		await ctx.onSubmit()
		expect(avgStore.applyRedaction).not.toHaveBeenCalled()
		expect(ctx.emitted).toEqual([])
	})

	it('submits exactly the field, the replacement and the ground', async () => {
		avgStore.applyRedaction = jest.fn().mockResolvedValue()
		const ctx = context(RedactionDialog, { caseId: 'case-1' })
		ctx.form.field = 'email'
		ctx.form.after = '[weggelakt]'
		ctx.form.ground = 'art-10-2e'

		await ctx.onSubmit()
		expect(avgStore.applyRedaction).toHaveBeenCalledWith('case-1', { field: 'email', after: '[weggelakt]', ground: 'art-10-2e' })
		expect(ctx.emitted).toEqual([['redacted']])
	})
})
