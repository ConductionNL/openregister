/**
 * EditableCell: a records-list cell edited in place (REQ-RFCE-002).
 *
 * Exercised through the options object against a synthetic `this`, like the
 * other component specs in this repo.
 */
import axios from '@nextcloud/axios'
import EditableCell from './EditableCell.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { patch: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path) => '/index.php' + path,
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (_app, text) => text,
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnCellRenderer: { name: 'CnCellRenderer', render: () => null },
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcSelect: { name: 'NcSelect', render: () => null },
	NcTextField: { name: 'NcTextField', render: () => null },
}))

const method = (key, ctx, ...args) => EditableCell.methods[key].call(ctx, ...args)
const computed = (key, ctx) => EditableCell.computed[key].call(ctx)

/**
 * A synthetic component context.
 *
 * @param {object} over Overrides.
 * @return {object}
 */
function ctx(over = {}) {
	return {
		row: {
			id: 'u-1',
			reference: 'Z-1',
			'@self': { id: 'u-1', register: 3, schema: 7, can: { update: true } },
		},
		field: 'reference',
		value: 'Z-1',
		property: { type: 'string' },
		editing: false,
		draft: null,
		shown: 'Z-1',
		saving: false,
		error: '',
		$emit: jest.fn(),
		$nextTick: (fn) => fn && fn(),
		cancel() {
			return EditableCell.methods.cancel.call(this)
		},
		...over,
	}
}

describe('EditableCell', () => {
	beforeEach(() => axios.patch.mockReset())

	it('offers an editor only when the row grants update', () => {
		expect(computed('canEdit', ctx())).toBe(true)
		const reader = ctx()
		reader.row['@self'].can = { update: false }
		expect(computed('canEdit', reader)).toBe(false)
		const unknown = ctx()
		delete unknown.row['@self'].can
		expect(computed('canEdit', unknown)).toBe(false)
	})

	it('offers no editor for a field that cannot be edited inline', () => {
		expect(computed('canEdit', ctx({ property: { type: 'object' } }))).toBe(
			false,
		)
	})

	it('opens the record instead of an editor for a reader', () => {
		const c = ctx()
		c.row['@self'].can = { update: false }
		method('onDoubleClick', c, { stopPropagation: jest.fn() })
		expect(c.editing).toBe(false)
		expect(c.$emit).toHaveBeenCalledWith('open', c.row)
	})

	it('starts editing with the current value for an editor', () => {
		const c = ctx()
		c.canEdit = true
		const event = { stopPropagation: jest.fn() }
		method('onDoubleClick', c, event)
		expect(c.editing).toBe(true)
		expect(c.draft).toBe('Z-1')
		expect(event.stopPropagation).toHaveBeenCalled()
	})

	it('saves one field through the same PATCH as the record form', async () => {
		axios.patch.mockResolvedValue({ data: { reference: 'Z-2' } })
		const c = ctx({ editing: true, draft: 'Z-2' })
		await method('save', c)
		expect(axios.patch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/3/7/u-1',
			{ reference: 'Z-2' },
		)
		expect(c.shown).toBe('Z-2')
		expect(c.editing).toBe(false)
		expect(c.error).toBe('')
		expect(c.$emit).toHaveBeenCalledWith('saved', {
			row: c.row,
			field: 'reference',
			value: 'Z-2',
		})
	})

	it('sends a number field as a number', async () => {
		axios.patch.mockResolvedValue({ data: {} })
		const c = ctx({
			editing: true,
			draft: '42',
			property: { type: 'integer' },
			field: 'count',
			value: 1,
			shown: 1,
		})
		await method('save', c)
		expect(axios.patch).toHaveBeenCalledWith(expect.any(String), { count: 42 })
	})

	it('shows the refusal and keeps the old value', async () => {
		axios.patch.mockRejectedValue({
			response: { data: { message: 'reference is frozen in state closed' } },
		})
		const c = ctx({ editing: true, draft: 'Z-2' })
		await method('save', c)
		expect(c.shown).toBe('Z-1')
		expect(c.error).toBe('reference is frozen in state closed')
		expect(c.editing).toBe(false)
		expect(c.$emit).not.toHaveBeenCalledWith('saved', expect.anything())
	})

	it('does not save an unchanged value', async () => {
		const c = ctx({ editing: true, draft: 'Z-1' })
		await method('save', c)
		expect(axios.patch).not.toHaveBeenCalled()
		expect(c.editing).toBe(false)
	})

	it('cancel restores the value without saving', () => {
		const c = ctx({ editing: true, draft: 'Z-9' })
		method('cancel', c)
		expect(c.editing).toBe(false)
		expect(c.draft).toBe(null)
		expect(axios.patch).not.toHaveBeenCalled()
	})
})
