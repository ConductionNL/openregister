/**
 * RecordTemplatePicker — options-object tests (the app runs Vue 2.7 while the
 * installed @vue/test-utils is the Vue 3 build, so the component's computed
 * getters and methods run against a synthetic `this`).
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import RecordTemplatePicker from './RecordTemplatePicker.vue'
import { fetchRecordTemplates } from '../../services/recordTemplates.js'

jest.mock('../../services/recordTemplates.js', () => {
	const actual = jest.requireActual('../../services/recordTemplates.js')
	return { __esModule: true, ...actual, fetchRecordTemplates: jest.fn() }
})
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (_app, text) => text,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcNoteCard: { name: 'NcNoteCard', render: () => null },
	NcSelect: { name: 'NcSelect', render: () => null },
}))

const schema = { id: 9, properties: { categorie: { type: 'string' }, spoed: { type: 'boolean' } } }

describe('RecordTemplatePicker', () => {
	beforeEach(() => fetchRecordTemplates.mockReset())

	it('lists the templates of its register and schema and forgets an earlier choice', async () => {
		fetchRecordTemplates.mockResolvedValue([{ id: 't1', name: 'Afval' }])
		const ctx = { register: 3, schema, chosen: { id: 'old' }, dropped: [{ field: 'x' }], templates: [] }

		await RecordTemplatePicker.methods.load.call(ctx)

		expect(fetchRecordTemplates).toHaveBeenCalledWith({ register: 3, schema: 9 })
		expect(ctx.templates).toEqual([{ id: 't1', name: 'Afval' }])
		expect(ctx.chosen).toBeNull()
		expect(ctx.dropped).toEqual([])
	})

	it('emits the values that still fit and names the dropped field', () => {
		const ctx = { schema, $emit: jest.fn(), dropped: [], chosen: null }
		const template = { id: 't1', values: { categorie: 'Afval', spoed: 'ja', wijk: 'Noord' } }

		RecordTemplatePicker.methods.choose.call(ctx, template)

		expect(ctx.chosen).toBe(template)
		expect(ctx.$emit).toHaveBeenCalledWith('apply', {
			values: { categorie: 'Afval' },
			dropped: [{ field: 'spoed', reason: 'type' }, { field: 'wijk', reason: 'missing' }],
		})
		expect(RecordTemplatePicker.computed.droppedFields.call(ctx)).toBe('spoed, wijk')
	})

	it('clears without applying anything', () => {
		const ctx = { schema, $emit: jest.fn(), dropped: [{ field: 'x' }], chosen: { id: 't1' } }

		RecordTemplatePicker.methods.choose.call(ctx, null)

		expect(ctx.chosen).toBeNull()
		expect(ctx.dropped).toEqual([])
		expect(ctx.$emit).not.toHaveBeenCalled()
	})

	it('keys its list on the register and schema', () => {
		expect(RecordTemplatePicker.computed.scopeKey.call({ register: 3, schema })).toBe('3:9')
		expect(RecordTemplatePicker.computed.scopeKey.call({ register: null, schema: null })).toBe(':')
	})
})
