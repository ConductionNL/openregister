/**
 * The relation fields of a schema property: which fields are disabled for
 * the loading state and an unset register or schema, which write-back
 * switches and hints show for the inversed relation, and which event each
 * field sends when it changes.
 *
 * @spec exclude Presentational part of the EditSchemaProperty dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import SchemaRelationFields from './SchemaRelationFields.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => `t:${s}` }))
jest.mock('@nextcloud/vue', () => ({}))

/**
 * A stand-in field that exposes its props as attributes and emits
 * `update:modelValue` on demand.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function field(name) {
	return {
		name,
		props: ['modelValue', 'disabled', 'inputLabel', 'label', 'options', 'placeholder'],
		emits: ['update:modelValue'],
		setup(props, { slots }) {
			return () => h('div', { class: name, 'data-label': props.inputLabel || props.label }, slots.default?.())
		},
	}
}

const stubs = {
	NcSelect: field('NcSelect'),
	NcTextField: field('NcTextField'),
	NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
}

/**
 * Mount the fields with a relation and optional extra props.
 *
 * @param {object} relation The relation object
 * @param {object} [extra] Further props
 * @return {object} The wrapper and its props
 */
function render(relation = {}, extra = {}) {
	const props = {
		handlingConfig: { handling: 'related-object' },
		handlingOptions: { options: ['related-object', 'nested-object'] },
		relation,
		queryConfig: { queryParams: '' },
		inversedByOptions: { options: ['owner'] },
		registers: [{ id: 1, label: 'People' }],
		schemas: [{ id: 2, label: 'Person' }],
		...extra,
	}
	return { wrapper: mount(SchemaRelationFields, { props, global: { stubs } }), props }
}

function byLabel (wrapper, label) {
  return wrapper.findAllComponents({ name: 'NcSelect' })
	.concat(wrapper.findAllComponents({ name: 'NcTextField' }))
	.find((c) => c.props('inputLabel') === label || c.props('label') === label)
}

describe('SchemaRelationFields', () => {
	it('disables the schema, query and inversed fields until a register and schema are set', () => {
		const { wrapper } = render({})
		expect(byLabel(wrapper, 't:Register').props('disabled')).toBe(false)
		expect(byLabel(wrapper, 't:Schema reference ($ref)').props('disabled')).toBe(true)
		expect(byLabel(wrapper, 't:Extra Query Parameters').props('disabled')).toBe(true)
		expect(byLabel(wrapper, 't:Property name of inversed relation').props('disabled')).toBe(true)
	})

	it('enables the schema field once a register is chosen, and the rest once a schema is', () => {
		let { wrapper } = render({ register: 1 })
		expect(byLabel(wrapper, 't:Schema reference ($ref)').props('disabled')).toBe(false)
		expect(byLabel(wrapper, 't:Extra Query Parameters').props('disabled')).toBe(true);
		({ wrapper } = render({ register: 1, $ref: 2 }))
		expect(byLabel(wrapper, 't:Extra Query Parameters').props('disabled')).toBe(false)
		expect(byLabel(wrapper, 't:Property name of inversed relation').props('disabled')).toBe(false)
	})

	it('disables the dependent fields while loading and the register field on request', () => {
		const { wrapper } = render({ register: 1, $ref: 2 }, { loading: true, registerDisabled: true })
		expect(byLabel(wrapper, 't:Register').props('disabled')).toBe(true)
		expect(byLabel(wrapper, 't:Schema reference ($ref)').props('disabled')).toBe(true)
		expect(byLabel(wrapper, 't:Extra Query Parameters').props('disabled')).toBe(true)
	})

	it('shows no write-back switch without an inversed relation', () => {
		const { wrapper } = render({ register: 1, $ref: 2 })
		expect(wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })).toHaveLength(0)
		expect(wrapper.findAll('.helper-text')).toHaveLength(0)
	})

	it('offers write-back with its hint once the relation is inversed', () => {
		const { wrapper } = render({ register: 1, $ref: 2, inversedBy: 'owner', writeBack: false })
		const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(switches).toHaveLength(1)
		expect(switches[0].text()).toBe('t:Enable write-back to target objects')
		expect(wrapper.find('.helper-text').text()).toContain('update the target objects to include a reference back')
	})

	it('offers removal after write-back with its hint once write-back is on', () => {
		const { wrapper } = render({ register: 1, $ref: 2, inversedBy: 'owner', writeBack: true, removeAfterWriteBack: false })
		const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(switches.map((s) => s.text())).toEqual([
			't:Enable write-back to target objects',
			't:Remove property after write-back',
		])
		const hints = wrapper.findAll('.helper-text')
		expect(hints).toHaveLength(1)
		expect(hints[0].text()).toContain('removed from the source object')
	})

	it('hides both hints once removal after write-back is on', () => {
		const { wrapper } = render({ inversedBy: 'owner', writeBack: true, removeAfterWriteBack: true })
		expect(wrapper.findAll('.helper-text')).toHaveLength(0)
	})

	it('sends each change as the matching event', async () => {
		const relation = { register: 1, $ref: 2, inversedBy: 'owner', writeBack: true }
		const { wrapper, props } = render(relation)
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		const [handling, register, schema, inversed] = selects
		handling.vm.$emit('update:modelValue', 'nested-object')
		register.vm.$emit('update:modelValue', { id: 3 })
		schema.vm.$emit('update:modelValue', { id: 4 })
		inversed.vm.$emit('update:modelValue', 'author')
		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'status=active')
		const [writeBack, removeAfter] = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		writeBack.vm.$emit('update:modelValue', false)
		removeAfter.vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('registerChange')).toEqual([[{ id: 3 }]])
		expect(wrapper.emitted('schemaChange')).toEqual([[{ id: 4 }]])
		expect(wrapper.emitted('inversedByChange')).toEqual([['author']])
		expect(wrapper.emitted('set')).toEqual([
			[props.handlingConfig, 'handling', 'nested-object'],
			[props.queryConfig, 'queryParams', 'status=active'],
			[relation, 'writeBack', false],
			[relation, 'removeAfterWriteBack', true],
		])
	})

	it('passes the current values and options to the fields', () => {
		const { wrapper } = render({ register: 1, $ref: 2, inversedBy: 'owner' })
		expect(byLabel(wrapper, 't:Register').props()).toMatchObject({ modelValue: 1, options: [{ id: 1, label: 'People' }] })
		expect(byLabel(wrapper, 't:Schema reference ($ref)').props()).toMatchObject({ modelValue: 2, options: [{ id: 2, label: 'Person' }] })
		expect(byLabel(wrapper, 't:Property name of inversed relation').props('modelValue')).toBe('owner')
		expect(byLabel(wrapper, 'Properties Object Configuration Handling').props('modelValue')).toBe('related-object')
	})
})
