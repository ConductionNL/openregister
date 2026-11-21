/**
 * RegisterSchemaSelector: options from the quality store, the selection it
 * restores from the route or the store, and the selection and route it writes.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RegisterSchemaSelector from './RegisterSchemaSelector.vue'
import { qualityStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		NcSelect: {
			name: 'NcSelect',
			props: { modelValue: null, options: Array, disabled: Boolean },
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'nc-select' })
			},
		},
		NcNoteCard: {
			name: 'NcNoteCard',
			props: { type: String, heading: String },
			render() {
				return h('div', { class: 'nc-note-card' }, [this.heading, ': ', this.$slots.default?.()])
			},
		},
	}
})
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		qualityStore: reactive({
			registers: [],
			schemas: [],
			selectedRegister: null,
			selectedSchema: null,
			fetchRegisters: jest.fn(),
			fetchSchemasForRegister: jest.fn(),
			setSelection: jest.fn(),
		}),
	}
})

const REGISTERS = [{ id: 1, title: 'Reg One', slug: 'catalogus' }, { id: 2, name: 'Named', slug: 'named' }, { id: 3 }]
const SCHEMAS = [{ id: 10, title: 'Person', slug: 'module' }, { id: 11, name: 'Org', slug: 'org' }, { id: 12 }]

/**
 * Mount with a route query.
 *
 * @param {object} query The route query.
 * @return {Promise<object>} The wrapper and the router stub.
 */
async function mountWith(query = {}) {
	const router = { replace: jest.fn(() => Promise.resolve()) }
	const wrapper = mount(RegisterSchemaSelector, {
		global: { mocks: { $route: { query }, $router: router, t: (app, text) => text } },
	})
	await flushPromises()
	const [registerSelect, schemaSelect] = wrapper.findAllComponents({ name: 'NcSelect' })
	return { wrapper, router, registerSelect, schemaSelect }
}

describe('RegisterSchemaSelector', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		Object.assign(qualityStore, { registers: REGISTERS, schemas: [], selectedRegister: null, selectedSchema: null })
		qualityStore.fetchSchemasForRegister.mockImplementation(async () => {
			qualityStore.schemas = SCHEMAS
		})
	})

	it('labels the options by title, else name, else id and disables the schema without a register', async () => {
		const { registerSelect, schemaSelect, router } = await mountWith()
		expect(qualityStore.fetchRegisters).toHaveBeenCalled()
		expect(registerSelect.props('options')).toEqual([
			{ id: 1, label: 'Reg One' }, { id: 2, label: 'Named' }, { id: 3, label: '3' },
		])
		expect(schemaSelect.props('disabled')).toBe(true)
		expect(router.replace).not.toHaveBeenCalled()
		expect(qualityStore.setSelection).not.toHaveBeenCalled()
	})

	it('restores the selection from the route by id', async () => {
		const { wrapper, registerSelect, schemaSelect } = await mountWith({ register: '2', schema: '11' })
		expect(qualityStore.setSelection).toHaveBeenLastCalledWith(2, 11)
		expect(qualityStore.fetchSchemasForRegister).toHaveBeenCalledWith(2)
		expect(registerSelect.props('modelValue')).toEqual({ id: 2, label: 'Named' })
		expect(schemaSelect.props('modelValue')).toEqual({ id: 11, label: 'Org' })
		expect(schemaSelect.props('options').map((o) => o.label)).toEqual(['Person', 'Org', '12'])
		expect(wrapper.findAll('.nc-note-card')).toHaveLength(0)
	})

	it('restores the selection from the route by slug and selects their ids', async () => {
		const { wrapper, registerSelect, schemaSelect, router } = await mountWith({ register: 'catalogus', schema: 'module' })
		expect(qualityStore.fetchSchemasForRegister).toHaveBeenCalledWith(1)
		expect(qualityStore.setSelection).toHaveBeenLastCalledWith(1, 10)
		expect(registerSelect.props('modelValue')).toEqual({ id: 1, label: 'Reg One' })
		expect(schemaSelect.props('modelValue')).toEqual({ id: 10, label: 'Person' })
		expect(wrapper.findAll('.nc-note-card')).toHaveLength(0)
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('restores a route register without a schema', async () => {
		const { registerSelect, schemaSelect } = await mountWith({ register: 'named' })
		expect(qualityStore.setSelection).toHaveBeenLastCalledWith(2, null)
		expect(registerSelect.props('modelValue')).toEqual({ id: 2, label: 'Named' })
		expect(schemaSelect.props('modelValue')).toBeNull()
	})

	it('names an unknown register, selects nothing and does not load its schemas', async () => {
		qualityStore.selectedRegister = 1
		qualityStore.selectedSchema = 10
		const { wrapper, registerSelect, schemaSelect, router } = await mountWith({ register: 'nope', schema: 'module' })
		expect(qualityStore.setSelection).toHaveBeenCalledTimes(1)
		expect(qualityStore.setSelection).toHaveBeenCalledWith(null, null)
		expect(qualityStore.fetchSchemasForRegister).not.toHaveBeenCalled()
		expect(registerSelect.props('modelValue')).toBeNull()
		expect(schemaSelect.props('modelValue')).toBeNull()
		const notes = wrapper.findAll('.nc-note-card')
		expect(notes).toHaveLength(1)
		expect(notes[0].text()).toBe('Unknown Register: nope — Choose a register')
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('names an unknown schema and keeps only the register selected', async () => {
		const { wrapper, registerSelect, schemaSelect } = await mountWith({ register: '1', schema: 'nope' })
		expect(qualityStore.setSelection).toHaveBeenCalledTimes(1)
		expect(qualityStore.setSelection).toHaveBeenCalledWith(1, null)
		expect(registerSelect.props('modelValue')).toEqual({ id: 1, label: 'Reg One' })
		expect(schemaSelect.props('modelValue')).toBeNull()
		const notes = wrapper.findAll('.nc-note-card')
		expect(notes).toHaveLength(1)
		expect(notes[0].text()).toBe('Unknown Schema: nope — Choose a schema')
	})

	it('drops the notice once the steward chooses', async () => {
		const { wrapper, schemaSelect } = await mountWith({ register: '1', schema: 'nope' })
		schemaSelect.vm.$emit('update:modelValue', { id: 10, label: 'Person' })
		await flushPromises()
		expect(wrapper.findAll('.nc-note-card')).toHaveLength(0)

		const unknown = await mountWith({ register: 'nope' })
		unknown.registerSelect.vm.$emit('update:modelValue', { id: 2, label: 'Named' })
		await flushPromises()
		expect(unknown.wrapper.findAll('.nc-note-card')).toHaveLength(0)
	})

	it('restores the store selection and writes it to the route', async () => {
		qualityStore.selectedRegister = 1
		qualityStore.selectedSchema = 10
		const { registerSelect, schemaSelect, router } = await mountWith()
		expect(registerSelect.props('modelValue')).toEqual({ id: 1, label: 'Reg One' })
		expect(schemaSelect.props('modelValue')).toEqual({ id: 10, label: 'Person' })
		expect(router.replace).toHaveBeenCalledWith({ query: { register: '1', schema: '10' } })
		expect(qualityStore.setSelection).not.toHaveBeenCalled()
	})

	it('restores a store register without a schema', async () => {
		qualityStore.selectedRegister = 5
		const { registerSelect, router } = await mountWith()
		expect(registerSelect.props('modelValue')).toBeNull()
		expect(router.replace).toHaveBeenCalledWith({ query: { register: '5' } })
	})

	it('a new register clears the schema, selects it, loads its schemas and updates the route', async () => {
		const { wrapper, registerSelect, schemaSelect, router } = await mountWith({ register: '1', schema: '10' })
		registerSelect.vm.$emit('update:modelValue', { id: 2, label: 'Named' })
		await flushPromises()
		expect(schemaSelect.props('modelValue')).toBeNull()
		expect(qualityStore.setSelection).toHaveBeenLastCalledWith(2, null)
		expect(router.replace).toHaveBeenLastCalledWith({ query: { register: '2' } })
		expect(qualityStore.fetchSchemasForRegister).toHaveBeenLastCalledWith(2)
		expect(wrapper.vm.registerModel).toEqual({ id: 2, label: 'Named' })
	})

	it('clearing the register empties the schemas without a request', async () => {
		const { registerSelect, router } = await mountWith({ register: '1' })
		qualityStore.fetchSchemasForRegister.mockClear()
		registerSelect.vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(qualityStore.setSelection).toHaveBeenLastCalledWith(null, null)
		expect(router.replace).toHaveBeenLastCalledWith({ query: {} })
		expect(qualityStore.fetchSchemasForRegister).not.toHaveBeenCalled()
		expect(qualityStore.schemas).toEqual([])
	})

	it('a schema choice selects it under the current register and survives a refused route change', async () => {
		const { schemaSelect, router } = await mountWith({ register: '1' })
		router.replace.mockReturnValue(Promise.reject(new Error('duplicated')))
		schemaSelect.vm.$emit('update:modelValue', { id: 12, label: '12' })
		await flushPromises()
		expect(qualityStore.setSelection).toHaveBeenLastCalledWith(1, 12)
		expect(router.replace).toHaveBeenLastCalledWith({ query: { register: '1', schema: '12' } })
		schemaSelect.vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(qualityStore.setSelection).toHaveBeenLastCalledWith(1, null)
	})
})
