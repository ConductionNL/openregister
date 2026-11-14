/**
 * SaveRecordTemplate — options-object tests: the component's computed getters
 * and methods run against a synthetic `this`, as RecordTemplatePicker.spec.js
 * does.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-caseworker-saves-a-melding-as-a-template
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SaveRecordTemplate from './SaveRecordTemplate.vue'
import { saveRecordTemplate } from '../../services/recordTemplates.js'
import { navigationStore, objectStore, schemaStore } from '../../store/store.js'

jest.mock('../../services/recordTemplates.js', () => {
	const actual = jest.requireActual('../../services/recordTemplates.js')
	return { __esModule: true, ...actual, saveRecordTemplate: jest.fn() }
})
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { dialog: false, setDialog: jest.fn() },
	objectStore: { objectItem: null },
	schemaStore: { schemaItem: null, schemaList: [] },
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (_app, text) => text,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: { name: 'NcButton', render: () => null },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', render: () => null },
	NcDialog: { name: 'NcDialog', render: () => null },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcNoteCard: { name: 'NcNoteCard', render: () => null },
	NcTextField: { name: 'NcTextField', render: () => null },
}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ name: 'CloseCancelButton', render: () => null }))

const meldingen = {
	id: 9,
	slug: 'meldingen',
	properties: {
		categorie: { type: 'string', title: 'Categorie' },
		prioriteit: { type: 'integer', title: 'Prioriteit' },
		gemeld: { type: 'string', format: 'date-time', title: 'Gemeld' },
	},
}
const melding = {
	'@self': { id: 'm1', register: 3, schema: 9 },
	categorie: 'Wateroverlast',
	prioriteit: 2,
	gemeld: '2026-11-01T10:00:00Z',
}

/**
 * A synthetic component `this` with the data and methods bound.
 *
 * @param {object} extra Fields to set over the data.
 * @return {object}
 */
function context(extra = {}) {
	const ctx = { ...SaveRecordTemplate.data(), ...extra }
	for (const [name, method] of Object.entries(SaveRecordTemplate.methods)) {
		ctx[name] = method.bind(ctx)
	}
	Object.defineProperty(ctx, 'canSave', { get: () => SaveRecordTemplate.computed.canSave.call(ctx) })
	return ctx
}

describe('SaveRecordTemplate', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		saveRecordTemplate.mockReset()
		navigationStore.setDialog.mockReset()
		objectStore.objectItem = melding
		schemaStore.schemaItem = null
		schemaStore.schemaList = [meldingen]
	})

	afterEach(() => jest.useRealTimers())

	it('opens on the record\'s fields from its schema, the date unticked', () => {
		const ctx = context({ name: 'old', error: 'old', saved: true })
		ctx.reset()

		expect(ctx.choices).toEqual([
			{ field: 'categorie', label: 'Categorie', keep: true },
			{ field: 'prioriteit', label: 'Prioriteit', keep: true },
			{ field: 'gemeld', label: 'Gemeld', keep: false },
		])
		expect([ctx.name, ctx.error, ctx.saved, ctx.visibility]).toEqual(['', '', false, 'private'])
	})

	it('needs a name, a kept field, and a group when shared with one', () => {
		const ctx = context()
		ctx.reset()
		expect(ctx.canSave).toBe(false)
		ctx.name = 'Melding wateroverlast'
		expect(ctx.canSave).toBe(true)
		ctx.visibility = 'group'
		expect(ctx.canSave).toBe(false)
		ctx.group = 'kcc'
		expect(ctx.canSave).toBe(true)
		ctx.choices.forEach((choice) => { choice.keep = false })
		expect(ctx.canSave).toBe(false)
	})

	it('saves the ticked values of the record, shared with the group, then closes', async () => {
		saveRecordTemplate.mockResolvedValue({ id: 't1' })
		const ctx = context()
		ctx.reset()
		ctx.name = ' Melding wateroverlast '
		ctx.description = 'Voor de KCC'
		ctx.visibility = 'group'
		ctx.group = 'kcc'
		ctx.choices[1].keep = false

		await ctx.save()

		expect(saveRecordTemplate).toHaveBeenCalledWith({
			name: 'Melding wateroverlast',
			description: 'Voor de KCC',
			register: '3',
			schema: '9',
			values: { categorie: 'Wateroverlast' },
			isPublic: false,
			sharedWith: [{ group: 'kcc', mode: 'read' }],
		})
		expect(ctx.saved).toBe(true)
		expect(ctx.saving).toBe(false)
		jest.runAllTimers()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('shows the refusal and stays open', async () => {
		saveRecordTemplate.mockRejectedValueOnce(new Error('You may not create records in this schema.'))
		const ctx = context()
		ctx.reset()
		ctx.name = 'Melding'
		await ctx.save()
		expect(ctx.error).toBe('You may not create records in this schema.')
		expect(ctx.saved).toBe(false)

		saveRecordTemplate.mockRejectedValueOnce(new Error(''))
		await ctx.save()
		expect(ctx.error).toBe('The template could not be saved.')
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('sends nothing while the form is incomplete', async () => {
		const ctx = context()
		ctx.reset()
		await ctx.save()
		expect(saveRecordTemplate).not.toHaveBeenCalled()
	})

	it('finds the schema by slug on the selected schema, and none when unknown', () => {
		schemaStore.schemaItem = meldingen
		schemaStore.schemaList = []
		objectStore.objectItem = { '@self': { register: 3, schema: 'meldingen' } }
		expect(context().recordSchema()).toBe(meldingen)
		objectStore.objectItem = { '@self': { register: 3, schema: 'elders' } }
		expect(context().recordSchema()).toBeNull()
	})
})
