import { mount } from '@vue/test-utils'
/**
 * The relation fields of a schema property as they render: the register,
 * schema, query and inversed-relation controls always there, the write-back
 * switches and their helper text shown by the relation's flags, and each
 * control emitting the parent's `set` / change event.
 *
 * @spec exclude Test of the presentational relation fields of the property dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SchemaRelationFields from './SchemaRelationFields.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, t: (app, s) => s }))

/**
 * The library controls, as stubs that reflect their props and emit on change.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const { h: render } = jest.requireActual('vue')
	const control = (name) => ({
		name,
		props: ['modelValue', 'disabled', 'inputLabel', 'label', 'options'],
		emits: ['update:modelValue'],
		render() {
			return render('div', {
				class: name,
				'data-label': this.inputLabel || this.label,
				'data-disabled': String(Boolean(this.disabled)),
				'data-value': JSON.stringify(this.modelValue ?? null),
				onClick: () => this.$emit('update:modelValue', 'picked'),
			}, this.$slots.default?.())
		},
	})
	return {
		__esModule: true,
		NcSelect: control('NcSelect'),
		NcTextField: control('NcTextField'),
		NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch'),
	}
}
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))

/**
 * Mount the fields with the given relation and defaults for the rest.
 *
 * @param {object} relation The relation object
 * @param {object} props Extra props to override
 * @return {object} The wrapper
 */
function mountFields(relation = {}, props = {}) {
	return mount(SchemaRelationFields, {
		props: {
			handlingConfig: { handling: 'nested' },
			handlingOptions: { options: [] },
			relation: { register: null, $ref: null, inversedBy: null, writeBack: false, removeAfterWriteBack: false, ...relation },
			queryConfig: { queryParams: '' },
			inversedByOptions: { options: [] },
			...props,
		},
	})
}

describe('SchemaRelationFields as it renders', () => {
	it('shows the four base controls and no write-back switch without an inversed relation', () => {
		const wrapper = mountFields()

		const labels = wrapper.findAll('[data-label]').map((el) => el.attributes('data-label'))
		expect(labels).toEqual(expect.arrayContaining(['Register', 'Schema reference ($ref)', 'Extra Query Parameters', 'Property name of inversed relation']))
		expect(wrapper.find('.NcCheckboxRadioSwitch').exists()).toBe(false)
	})

	it('disables the schema and query controls until a register and a ref are chosen', () => {
		const empty = mountFields()
		const schemaSelect = empty.findAll('.NcSelect').find((el) => el.attributes('data-label') === 'Schema reference ($ref)')
		expect(schemaSelect.attributes('data-disabled')).toBe('true')

		const filled = mountFields({ register: 'reg-1', $ref: 'schema-1' })
		const schema = filled.findAll('.NcSelect').find((el) => el.attributes('data-label') === 'Schema reference ($ref)')
		expect(schema.attributes('data-disabled')).toBe('false')
	})

	it('shows the write-back switch and its helper once an inversed relation is set', () => {
		const wrapper = mountFields({ inversedBy: 'owner', writeBack: false })

		expect(wrapper.findAll('.NcCheckboxRadioSwitch')).toHaveLength(1)
		expect(wrapper.text()).toContain('Enable write-back to target objects')
		expect(wrapper.find('.helper-text').text()).toContain('saving this object will also update the target objects')
	})

	it('shows the remove-after switch once write-back is on', () => {
		const wrapper = mountFields({ inversedBy: 'owner', writeBack: true, removeAfterWriteBack: false })

		expect(wrapper.text()).toContain('Remove property after write-back')
		expect(wrapper.find('.helper-text').text()).toContain('removed from the source object')
	})

	it('hides the remove helper once the property is removed after write-back', () => {
		const wrapper = mountFields({ inversedBy: 'owner', writeBack: true, removeAfterWriteBack: true })

		expect(wrapper.findAll('.helper-text')).toHaveLength(0)
	})

	it('emits the register and schema changes', async () => {
		const wrapper = mountFields({ register: 'r', $ref: 's' })
		const select = (label) => wrapper.findAll('.NcSelect').find((el) => el.attributes('data-label') === label)

		await select('Register').trigger('click')
		await select('Schema reference ($ref)').trigger('click')

		expect(wrapper.emitted('registerChange')).toEqual([['picked']])
		expect(wrapper.emitted('schemaChange')).toEqual([['picked']])
	})

	it('emits set for the query parameters with their config and key', async () => {
		const wrapper = mountFields({ $ref: 's' })

		await wrapper.find('.NcTextField').trigger('click')

		expect(wrapper.emitted('set')).toEqual([[{ queryParams: '' }, 'queryParams', 'picked']])
	})
})
