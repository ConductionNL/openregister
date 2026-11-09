import { mount, flushPromises } from '@vue/test-utils'
/**
 * Golden record of the permission matrix: its rendered rows and the group
 * helpers the cells, tooltips and bulk selects read.
 *
 * @spec exclude Golden record of client-side settings markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import PermissionMatrix from './PermissionMatrix.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => 't:' + text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: { template: '<button><slot /></button>' },
	NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<i class="switch" :data-on="String(modelValue)"><slot /></i>' },
	NcSelect: { props: ['options'], template: '<i class="select" :data-options="JSON.stringify(options)" />' },
}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({
	__esModule: true,
	default: { template: '<section><slot /></section>' },
}))

const REGISTERS = [
	{
		id: 1,
		title: 'Reg One',
		schemas: [10, '11', 12],
		authorization: { read: ['staff', { group: 'auditors' }, null], update: [{ group: 'editors' }] },
		configuration: { roles: [{ name: 'viewer', actions: ['read'] }] },
	},
	{ id: 2, name: 'reg-two', schemas: [], authorization: {} },
]
const SCHEMAS = [
	{ id: 10, title: 'Direct', authorization: { read: [{ group: 'readers' }, 'public'], delete: ['admin'] } },
	{ id: 11, title: 'Inheriting' },
	{ id: 12, name: 'empty-auth', authorization: {} },
]

jest.mock('../../../store/modules/register.js', () => {
	const useRegisterStore = () => ({ refreshRegisterList: async () => ({ data: globalThis.pmRegisters }), registerList: [] })
	useRegisterStore.$id = 'register'
	return { useRegisterStore }
})
jest.mock('../../../store/modules/schema.js', () => {
	const useSchemaStore = () => ({ refreshSchemaList: async () => ({ data: globalThis.pmSchemas }), schemaList: [], saveSchema: async () => {} })
	useSchemaStore.$id = 'schema'
	return { useSchemaStore }
})
jest.mock('pinia', () => ({
	mapStores: (...stores) => Object.fromEntries(stores.map((s) => [s.$id + 'Store', s])),
}))

/**
 * Mounts the section with every register expanded.
 *
 * @return {Promise<object>} The wrapper
 */
async function section() {
	globalThis.pmRegisters = JSON.parse(JSON.stringify(REGISTERS))
	globalThis.pmSchemas = JSON.parse(JSON.stringify(SCHEMAS))
	const wrapper = mount(PermissionMatrix, { global: { config: { warnHandler: () => {} } } })
	await flushPromises()
	for (const button of wrapper.findAll('button.expand-toggle')) {
		await button.trigger('click')
	}
	await flushPromises()
	return wrapper
}

describe('PermissionMatrix golden', () => {
	it('renders the matrix rows', async () => {
		const wrapper = await section()
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('resolves groups, classes and tooltips', async () => {
		const { vm } = await section()
		const [reg] = vm.registers
		const out = {}
		for (const schema of vm.schemas) {
			for (const action of vm.actions) {
				out[schema.id + ':' + action] = [
					vm.getEffectiveGroups(schema, reg, action),
					vm.getPermissionClass(schema, reg, action),
					vm.getEffectiveTooltip(schema, reg, action),
				]
			}
		}
		out.register = vm.actions.map((a) => vm.getRegisterGroups(reg, a))
		out.options = vm.getGroupOptions()
		out.roles = vm.getRoleOptions(reg)
		expect(out).toMatchSnapshot()
	})
})
