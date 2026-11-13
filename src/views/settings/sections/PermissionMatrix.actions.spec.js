import { flushPromises, mount } from '@vue/test-utils'
/**
 * The permission matrix's write paths: the public-access switches of a
 * register and of a schema, the bulk role assignment, and how the matrix loads
 * and labels its groups.
 *
 * @spec exclude Client-side settings section behaviour.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import PermissionMatrix from './PermissionMatrix.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => 't:' + text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
}))
jest.mock('@nextcloud/vue', () =>
	require('../../__mocks__/goldenDom.js').library(),
)
jest.mock('../../../components/shared/SettingsSection.vue', () => ({
	__esModule: true,
	default: { template: '<section><slot /></section>' },
}))
jest.mock('../../../store/modules/register.js', () => {
	const useRegisterStore = () => globalThis.pmRegisterStore
	useRegisterStore.$id = 'register'
	return { useRegisterStore }
})
jest.mock('../../../store/modules/schema.js', () => {
	const useSchemaStore = () => globalThis.pmSchemaStore
	useSchemaStore.$id = 'schema'
	return { useSchemaStore }
})
jest.mock('pinia', () => ({
	mapStores: (...stores) => Object.fromEntries(stores.map((s) => [s.$id + 'Store', s])),
}))

/**
 * The registers the store answers with.
 *
 * @return {Array<object>} The registers
 */
function registers() {
	return [
		{
			id: 1,
			title: 'Reg One',
			schemas: [10, '11', 12],
			authorization: { read: ['staff'], update: ['editors'] },
			configuration: { roles: [{ name: 'viewer', actions: ['read'] }, { name: 'owner' }] },
		},
	]
}

/**
 * The schemas the store answers with.
 *
 * @return {Array<object>} The schemas
 */
function schemas() {
	return [
		{ id: 10, title: 'Direct', authorization: { read: ['readers', 'public'] } },
		{ id: 11, title: 'Inheriting' },
		{ id: 12, title: 'Empty', authorization: {} },
		{ id: 99, title: 'Elsewhere' },
	]
}

/**
 * Mounts the section against fresh store doubles.
 *
 * @param {object} overrides Store members to replace
 * @return {Promise<object>} The wrapper
 */
async function section(overrides = {}) {
	globalThis.pmRegisterStore = {
		refreshRegisterList: jest.fn(async () => ({ data: registers() })),
		registerList: [],
		saveRegister: jest.fn(async () => {}),
		...overrides.register,
	}
	globalThis.pmSchemaStore = {
		refreshSchemaList: jest.fn(async () => ({ data: schemas() })),
		schemaList: [],
		saveSchema: jest.fn(async () => {}),
		...overrides.schema,
	}
	const { translate: t } = jest.requireMock('@nextcloud/l10n')
	const bindings = { ...PermissionMatrix.components, t }
	const wrapper = mount({ ...PermissionMatrix, setup: () => bindings }, {
		global: { config: { warnHandler: () => {} } },
	})
	await flushPromises()
	return wrapper
}

let consoleError

beforeEach(() => {
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
})

describe('PermissionMatrix loading', () => {
	it('falls back to the store lists when a refresh answers without data', async () => {
		const wrapper = await section({
			register: { refreshRegisterList: jest.fn(async () => undefined), registerList: [{ id: 5, title: 'Listed', schemas: [] }] },
			schema: { refreshSchemaList: jest.fn(async () => ({})), schemaList: [{ id: 6 }] },
		})
		expect(wrapper.vm.registers).toEqual([{ id: 5, title: 'Listed', schemas: [] }])
		expect(wrapper.vm.schemas).toEqual([{ id: 6 }])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('logs a failed load, stops loading and shows the empty state', async () => {
		const failure = new Error('down')
		const wrapper = await section({ register: { refreshRegisterList: jest.fn(async () => { throw failure }) } })
		expect(consoleError).toHaveBeenCalledWith('Failed to load permission matrix data:', failure)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.empty-state').exists()).toBe(true)
		expect(wrapper.find('.permission-matrix').exists()).toBe(false)
	})

	it('tells a non-admin they lack access', async () => {
		const wrapper = await section()
		wrapper.vm.isAdminUser = false
		await flushPromises()
		expect(wrapper.find('.access-denied').text()).toContain('Admin access is required.')
		expect(wrapper.find('.permission-matrix').exists()).toBe(false)
	})
})

describe('PermissionMatrix group labels', () => {
	it('shortens more than two groups to two and a count', async () => {
		const { vm } = await section()
		expect(vm.formatGroups([])).toBe('-')
		expect(vm.formatGroups(['a', 'b'])).toBe('a, b')
		expect(vm.formatGroups(['a', 'b', 'c', 'd'])).toBe('a, b +2')
	})

	it('names the groups in a tooltip, or says there are no restrictions', async () => {
		const { vm } = await section()
		expect(vm.getGroupsTooltip([])).toBe('No restrictions')
		expect(vm.getGroupsTooltip(['a', 'b'])).toBe('a, b')
	})

	it('detects public read access as a string or a group entry only', async () => {
		const { vm } = await section()
		expect(vm.isPublicAccess(null)).toBe(false)
		expect(vm.isPublicAccess({ update: ['public'] })).toBe(false)
		expect(vm.isPublicAccess({ read: ['staff', null] })).toBe(false)
		expect(vm.isPublicAccess({ read: ['public'] })).toBe(true)
		expect(vm.isPublicAccess({ read: [{ group: 'public' }] })).toBe(true)
	})

	it('lists only the schemas of a register, matching string ids too', async () => {
		const { vm } = await section()
		expect(vm.getRegisterSchemas(vm.registers[0]).map((s) => s.id)).toEqual([10, 11, 12])
		expect(vm.getRegisterSchemas({ id: 3 })).toEqual([])
	})

	it('uses the schema authorization when it has one, else the register one', async () => {
		const { vm } = await section()
		const [register] = vm.registers
		expect(vm.getEffectiveAuth(vm.schemas[0], register)).toBe(vm.schemas[0].authorization)
		expect(vm.getEffectiveAuth(vm.schemas[2], register)).toBe(register.authorization)
		expect(vm.getEffectiveAuth(vm.schemas[1], { id: 2 })).toEqual({})
	})
})

describe('PermissionMatrix register public access', () => {
	it('adds public read access once, saves the register and reloads', async () => {
		const wrapper = await section()
		const [register] = wrapper.vm.registers
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(globalThis.pmRegisterStore.saveRegister).toHaveBeenCalledWith({
			...register,
			authorization: { read: ['staff', 'public'], update: ['editors'] },
		})
		expect(globalThis.pmRegisterStore.refreshRegisterList).toHaveBeenCalledTimes(2)
		await wrapper.vm.togglePublicAccess({ id: 4, authorization: { read: ['public'] } }, true)
		expect(globalThis.pmRegisterStore.saveRegister).toHaveBeenLastCalledWith({ id: 4, authorization: { read: ['public'] } })
	})

	it('creates the read list for a register without one', async () => {
		const { vm } = await section()
		await vm.togglePublicAccess({ id: 4 }, true)
		expect(globalThis.pmRegisterStore.saveRegister).toHaveBeenCalledWith({ id: 4, authorization: { read: ['public'] } })
	})

	it('removes public read access and keeps the other groups', async () => {
		const { vm } = await section()
		await vm.togglePublicAccess({ id: 4, authorization: { read: ['staff', 'public'] } }, false)
		expect(globalThis.pmRegisterStore.saveRegister).toHaveBeenCalledWith({ id: 4, authorization: { read: ['staff'] } })
		await vm.togglePublicAccess({ id: 5, authorization: { update: ['x'] } }, false)
		expect(globalThis.pmRegisterStore.saveRegister).toHaveBeenLastCalledWith({ id: 5, authorization: { update: ['x'] } })
	})

	it('logs a failed save without reloading', async () => {
		const failure = new Error('refused')
		const { vm } = await section({ register: { saveRegister: jest.fn(async () => { throw failure }) } })
		await vm.togglePublicAccess({ id: 4 }, true)
		expect(consoleError).toHaveBeenCalledWith('Failed to toggle public access:', failure)
		expect(globalThis.pmRegisterStore.refreshRegisterList).toHaveBeenCalledTimes(1)
	})
})

describe('PermissionMatrix schema public access', () => {
	/**
	 * The expanded section's schema switches.
	 *
	 * @return {Promise<Array<object>>} The wrapper and the switches of the schema rows
	 */
	async function expanded() {
		const wrapper = await section()
		await wrapper.find('button.expand-toggle').trigger('click')
		await flushPromises()
		const switches = wrapper.findAll('tr.schema-row').map((row) => row.findComponent({ name: 'NcCheckboxRadioSwitch' }))
		return { wrapper, switches }
	}

	it('shows the effective public access of each schema', async () => {
		const { switches } = await expanded()
		expect(switches.map((item) => item.vm.$attrs.modelValue)).toEqual([true, false, false])
	})

	it('removes public access from a schema with its own authorization', async () => {
		const { wrapper, switches } = await expanded()
		const schema = wrapper.vm.schemas[0]
		switches[0].vm.$emit('update:modelValue', false)
		await flushPromises()
		expect(globalThis.pmSchemaStore.saveSchema).toHaveBeenCalledWith({ ...schema, authorization: { read: ['readers'] } })
		expect(globalThis.pmRegisterStore.refreshRegisterList).toHaveBeenCalledTimes(2)
	})

	it('copies the register authorization into an inheriting schema it makes public', async () => {
		const { wrapper, switches } = await expanded()
		const schema = wrapper.vm.schemas[1]
		switches[1].vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(globalThis.pmSchemaStore.saveSchema).toHaveBeenCalledWith({
			...schema,
			authorization: { read: ['staff', 'public'], update: ['editors'] },
		})
	})

	it('creates the read list and does not repeat public', async () => {
		const { vm } = await section()
		await vm.toggleSchemaPublicAccess({ id: 20 }, { id: 4 }, true)
		expect(globalThis.pmSchemaStore.saveSchema).toHaveBeenLastCalledWith({ id: 20, authorization: { read: ['public'] } })
		await vm.toggleSchemaPublicAccess({ id: 21, authorization: { read: ['public'] } }, { id: 4 }, true)
		expect(globalThis.pmSchemaStore.saveSchema).toHaveBeenLastCalledWith({ id: 21, authorization: { read: ['public'] } })
		await vm.toggleSchemaPublicAccess({ id: 22, authorization: { update: ['x'] } }, { id: 4 }, false)
		expect(globalThis.pmSchemaStore.saveSchema).toHaveBeenLastCalledWith({ id: 22, authorization: { update: ['x'] } })
	})

	it('logs a failed save without reloading', async () => {
		const failure = new Error('refused')
		const { vm } = await section({ schema: { saveSchema: jest.fn(async () => { throw failure }) } })
		await vm.toggleSchemaPublicAccess({ id: 20 }, { id: 4 }, true)
		expect(consoleError).toHaveBeenCalledWith('Failed to toggle schema public access:', failure)
		expect(globalThis.pmRegisterStore.refreshRegisterList).toHaveBeenCalledTimes(1)
	})
})

describe('PermissionMatrix bulk role assignment', () => {
	/**
	 * The section with the first register expanded, so its bulk actions show.
	 *
	 * @param {object} overrides Store members to replace
	 * @return {Promise<object>} The wrapper
	 */
	async function expanded(overrides) {
		const wrapper = await section(overrides)
		wrapper.vm.toggleRegister(1)
		await flushPromises()
		return wrapper
	}

	it('offers bulk actions only for an expanded register with roles', async () => {
		const wrapper = await section()
		expect(wrapper.find('.bulk-actions').exists()).toBe(false)
		wrapper.vm.toggleRegister(1)
		await flushPromises()
		expect(wrapper.find('.bulk-actions h4').text()).toBe('t:Bulk Role Assignment: Reg One')
		const [roles, groups] = wrapper.find('.bulk-actions').findAllComponents({ name: 'NcSelect' })
		expect(roles.vm.$attrs.options).toEqual([
			{ label: 'viewer (read)', value: 'viewer' },
			{ label: 'owner ()', value: 'owner' },
		])
		expect(groups.vm.$attrs.options.map((o) => o.value)).toEqual(['admin', 'editors', 'public', 'readers', 'staff'])
		wrapper.vm.toggleRegister(1)
		await flushPromises()
		expect(wrapper.find('.bulk-actions').exists()).toBe(false)
	})

	it('keeps Apply disabled until both a role and a group are picked', async () => {
		const wrapper = await expanded()
		const apply = () => wrapper.find('.bulk-actions').findComponent({ name: 'NcButton' }).vm.$attrs.disabled
		expect(apply()).toBe(true)
		const [roles, groups] = wrapper.find('.bulk-actions').findAllComponents({ name: 'NcSelect' })
		roles.vm.$emit('update:modelValue', { value: 'viewer' })
		await flushPromises()
		expect(apply()).toBe(true)
		groups.vm.$emit('update:modelValue', { value: 'staff' })
		await flushPromises()
		expect(apply()).toBe(false)
	})

	it('assigns the role to every schema without its own authorization, then reloads', async () => {
		const wrapper = await expanded()
		const [, inheriting, empty] = wrapper.vm.schemas
		wrapper.vm.bulkRole[1] = { value: 'viewer' }
		wrapper.vm.bulkGroup[1] = { value: 'staff' }
		await flushPromises()
		wrapper.find('.bulk-actions').findComponent({ name: 'NcButton' }).vm.$emit('click')
		await flushPromises()
		expect(globalThis.pmSchemaStore.saveSchema.mock.calls).toEqual([
			[{ ...inheriting, authorization: { roles: { viewer: ['staff'] } } }],
			[{ ...empty, authorization: { roles: { viewer: ['staff'] } } }],
		])
		expect(globalThis.pmRegisterStore.refreshRegisterList).toHaveBeenCalledTimes(2)
	})

	it('does nothing without both a role and a group', async () => {
		const wrapper = await expanded()
		wrapper.vm.bulkRole[1] = { value: 'viewer' }
		await wrapper.vm.applyBulkRole(wrapper.vm.registers[0])
		expect(globalThis.pmSchemaStore.saveSchema).not.toHaveBeenCalled()
	})

	it('logs each failed schema and reloads only when one succeeded', async () => {
		const failure = new Error('refused')
		const saveSchema = jest.fn()
			.mockRejectedValueOnce(failure)
			.mockResolvedValueOnce(undefined)
		const wrapper = await expanded({ schema: { saveSchema } })
		wrapper.vm.bulkRole[1] = { value: 'viewer' }
		wrapper.vm.bulkGroup[1] = { value: 'staff' }
		await wrapper.vm.applyBulkRole(wrapper.vm.registers[0])
		expect(consoleError).toHaveBeenCalledWith('Failed to apply role to schema:', 11, failure)
		expect(globalThis.pmRegisterStore.refreshRegisterList).toHaveBeenCalledTimes(2)
	})

	it('does not reload when every schema failed', async () => {
		const wrapper = await expanded({ schema: { saveSchema: jest.fn(async () => { throw new Error('no') }) } })
		wrapper.vm.bulkRole[1] = { value: 'viewer' }
		wrapper.vm.bulkGroup[1] = { value: 'staff' }
		await wrapper.vm.applyBulkRole(wrapper.vm.registers[0])
		expect(consoleError).toHaveBeenCalledTimes(2)
		expect(globalThis.pmRegisterStore.refreshRegisterList).toHaveBeenCalledTimes(1)
	})
})
