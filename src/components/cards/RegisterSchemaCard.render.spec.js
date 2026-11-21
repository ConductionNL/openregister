import { showError } from '@nextcloud/dialogs'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The register/schema card as rendered: which configuration manages it, the
 * badges and tables it shows for a register and for a schema, the "view
 * more" toggle, and where its header actions go.
 *
 * @spec exclude Unit test of the client-side register/schema card.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RegisterSchemaCard from './RegisterSchemaCard.vue'
import { downloadRegisterOas, viewRegisterOas } from '../../services/registerOas.js'
import {
	configurationStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)) : text,
	translatePlural: (app, one, many) => many,
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@conduction/nextcloud-vue', () => ({ CnFormDialog: {} }))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [] },
	dashboardStore: {},
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: { setRegisterItem: jest.fn() },
	schemaStore: { setSchemaItem: jest.fn() },
}))
jest.mock('../../services/registerOas.js', () => ({
	downloadRegisterOas: jest.fn(),
	viewRegisterOas: jest.fn(),
}))
jest.mock('../../modals/register/EditRegisterFormDialog.vue', () => ({
	name: 'EditRegisterFormDialog',
	props: ['item', 'schemasLabel', 'afterSave'],
	emits: ['close'],
	render: () => null,
}))
// The compiled component binds `@nextcloud/vue` and `vue` to one variable, so
// the library stand-ins have to be reachable through `vue` as well.
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))

/**
 * The library components the card uses, as stand-ins that render their slots.
 *
 * @return {object} The components
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	const slotted = (name, tag) => ({
		name,
		inheritAttrs: false,
		props: ['disabled', 'title', 'variant', 'primary', 'menuName', 'closeAfterClick'],
		emits: ['click'],
		render() {
			return h(
				tag,
				{
					class: name,
					disabled: this.disabled || undefined,
					title: this.title,
					onClick: () => this.$emit('click'),
				},
				[this.$slots.icon?.(), this.$slots.default?.()],
			)
		},
	})
	return {
		NcActions: slotted('NcActions', 'div'),
		NcActionButton: slotted('NcActionButton', 'button'),
		NcButton: slotted('NcButton', 'button'),
	}
}

jest.mock('vue-material-design-icons/Api.vue', () => ({ name: 'ApiIcon', render: () => null }))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({ name: 'CheckCircleIcon', render: () => null }))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({ name: 'ChevronDownIcon', render: () => null }))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({ name: 'ChevronUpIcon', render: () => null }))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({ name: 'CloudUploadOutlineIcon', render: () => null }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ name: 'CogOutlineIcon', render: () => null }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ name: 'DatabaseOutlineIcon', render: () => null }))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => ({ name: 'DeleteOutlineIcon', render: () => null }))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({ name: 'DotsHorizontalIcon', render: () => null }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ name: 'DownloadIcon', render: () => null }))
jest.mock('vue-material-design-icons/Export.vue', () => ({ name: 'ExportIcon', render: () => null }))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => ({ name: 'FileTreeOutlineIcon', render: () => null }))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({ name: 'InformationOutlineIcon', render: () => null }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ name: 'PencilIcon', render: () => null }))
jest.mock('vue-material-design-icons/Sync.vue', () => ({ name: 'SyncIcon', render: () => null }))
jest.mock('vue-material-design-icons/Table.vue', () => ({ name: 'TableIcon', render: () => null }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ name: 'TrashCanOutlineIcon', render: () => null }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ name: 'UploadIcon', render: () => null }))

const push = jest.fn()

/**
 * Mount a card.
 *
 * @param {object} item The register or schema
 * @param {string} type 'register' or 'schema'
 * @return {object} The wrapper
 */
function mountCard(item, type) {
	return mount(RegisterSchemaCard, {
		props: { item, type },
	})
}

/**
 * The header action button with a given label.
 *
 * @param {object} wrapper The wrapper
 * @param {string} label The label
 * @return {object} The button wrapper
 */
function action(wrapper, label) {
	const found = wrapper
		.findAll('button.NcActionButton')
		.find((b) => b.text().trim() === label)
	if (!found) {
		throw new Error(`no action ${label}`)
	}
	return found
}

/**
 * A schema of a register with a given number of objects.
 *
 * @param {number} id The id
 * @param {object} extra Extra fields
 * @return {object} The schema
 */
function schemaRow(id, extra = {}) {
	return { id, title: `Schema ${id}`, stats: { objects: { total: 0, deleted: 0 } }, ...extra }
}

beforeEach(() => {
	jest.clearAllMocks()
	configurationStore.configurationList = []
})

describe('managing configuration', () => {
	it('marks a register in an external configuration as managed and disables edit', () => {
		configurationStore.configurationList = [
			{ title: 'Other', registers: [99] },
			{ title: 'Remote config', sourceType: 'github', registers: [7] },
		]
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas: [] }, 'register')

		expect(wrapper.classes()).toContain('registerSchemaCard--managed')
		expect(wrapper.find('.managedBadge--external').text()).toBe('Managed')
		const edit = action(wrapper, 'Edit')
		expect(edit.attributes('disabled')).toBeDefined()
		expect(edit.attributes('title')).toBe(
			'Cannot edit: This register is managed by external configuration Remote config',
		)
	})

	it('treats a configuration with isLocal false as external', () => {
		configurationStore.configurationList = [{ title: 'C', isLocal: false, registers: [7] }]
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas: [] }, 'register')
		expect(wrapper.classes()).toContain('registerSchemaCard--managed')
	})

	it.each([
		[{ sourceType: 'local' }],
		[{ sourceType: 'manual' }],
		[{ isLocal: true }],
	])('marks a schema in a local configuration %j as local', (flags) => {
		configurationStore.configurationList = [
			{ title: 'L', schemas: [{ id: 5 }], ...flags },
		]
		const wrapper = mountCard({ id: 5, title: 'S', properties: {} }, 'schema')

		expect(wrapper.classes()).toContain('registerSchemaCard--local')
		expect(wrapper.classes()).not.toContain('registerSchemaCard--managed')
		expect(wrapper.find('.managedBadge--local').text()).toBe('Local')
		expect(action(wrapper, 'Edit').attributes('disabled')).toBeUndefined()
	})

	it('shows no badge when no configuration holds the item', () => {
		configurationStore.configurationList = [{ title: 'X', schemas: [{ id: 1 }] }]
		const wrapper = mountCard({ id: 5, title: 'S', properties: {} }, 'schema')
		expect(wrapper.find('.managedBadge').exists()).toBe(false)
		expect(wrapper.classes()).not.toContain('registerSchemaCard--local')
	})

	it('shows no badge for an item without an id', () => {
		configurationStore.configurationList = [{ title: 'X', sourceType: 'url', registers: [undefined] }]
		const wrapper = mountCard({ title: 'New', schemas: [] }, 'register')
		expect(wrapper.find('.managedBadge').exists()).toBe(false)
	})
})

describe('register card', () => {
	it('lists the first five schemas and expands to all of them', async () => {
		const schemas = [1, 2, 3, 4, 5, 6, 7].map((id) => schemaRow(id))
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas }, 'register')

		expect(wrapper.findAll('.schemaNameCell').map((c) => c.text())).toEqual([
			'Schema 1',
			'Schema 2',
			'Schema 3',
			'Schema 4',
			'Schema 5',
		])
		const more = wrapper.find('.viewMoreContainer button')
		expect(more.text()).toBe('View 2 more')

		await more.trigger('click')
		expect(wrapper.findAll('.schemaNameCell')).toHaveLength(7)
		expect(wrapper.find('.viewMoreContainer button').text()).toBe('Show less')
	})

	it('says so when the register has no schemas', () => {
		const wrapper = mountCard({ id: 7, title: 'Reg' }, 'register')
		expect(wrapper.find('.emptyText').text()).toBe('No schemas found')
		expect(wrapper.find('.viewMoreContainer').exists()).toBe(false)
	})

	it('shows the magic table icon only for schemas with a table mapping', () => {
		const schemas = [
			schemaRow(1, { properties: { a: { table: { enabled: true } } } }),
			schemaRow(2, { properties: { a: { type: 'string' } } }),
			schemaRow(3),
		]
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas }, 'register')
		const rows = wrapper.findAll('.schemaNameCell')
		expect(rows[0].findComponent({ name: 'TableIcon' }).exists()).toBe(true)
		expect(rows[1].findComponent({ name: 'TableIcon' }).exists()).toBe(false)
		expect(rows[1].findComponent({ name: 'DatabaseOutlineIcon' }).exists()).toBe(true)
		expect(rows[2].findComponent({ name: 'TableIcon' }).exists()).toBe(false)
	})

	it('disables delete while the register holds objects', () => {
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas: [], stats: { total: 3 } }, 'register')
		const del = action(wrapper, 'Delete')
		expect(del.attributes('disabled')).toBeDefined()
		expect(del.attributes('title')).toBe('Cannot delete: objects are still attached')
	})

	it('opens its own edit dialog and refreshes after a save', async () => {
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas: [] }, 'register')
		await action(wrapper, 'Edit').trigger('click')

		const dialog = wrapper.findComponent({ name: 'EditRegisterFormDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('schemasLabel')).toBe('Schemas')
		expect(navigationStore.setModal).not.toHaveBeenCalled()

		dialog.props('afterSave')()
		expect(wrapper.emitted('refresh')).toHaveLength(1)

		dialog.vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'EditRegisterFormDialog' }).exists()).toBe(false)
	})

	it('selects the register before opening the delete dialog', async () => {
		const item = { id: 7, title: 'Reg', schemas: [] }
		const wrapper = mountCard(item, 'register')
		await action(wrapper, 'Delete').trigger('click')
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(item)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteRegister')
	})

	it('routes to the register detail page', () => {
		// The card is mounted without a router, so the handler runs on a bare context.
		RegisterSchemaCard.methods.viewRegisterDetails.call({ item: { id: 7, title: 'Reg' }, $router: { push } })
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 7 })
		expect(push).toHaveBeenCalledWith('/registers/7')
	})

	it('offers the register-only actions', () => {
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas: [] }, 'register')
		const labels = wrapper.findAll('button.NcActionButton').map((b) => b.text().trim())
		expect(labels).toEqual([
			'Edit',
			'Publish OAS',
			'Import',
			'View API Documentation',
			'Download API Specification',
			'Delete',
			'View Details',
		])
	})

	it('opens the API documentation for the register', async () => {
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas: [] }, 'register')
		await action(wrapper, 'View API Documentation').trigger('click')
		expect(viewRegisterOas).toHaveBeenCalledWith(7)
	})

	it('downloads the API specification', async () => {
		const item = { id: 7, title: 'Reg', schemas: [] }
		const wrapper = mountCard(item, 'register')
		await action(wrapper, 'Download API Specification').trigger('click')
		await flushPromises()
		expect(downloadRegisterOas).toHaveBeenCalledWith(item)
		expect(showError).not.toHaveBeenCalled()
	})

	it('reports a failed download', async () => {
		const spy = jest.spyOn(console, 'error').mockImplementation(() => {})
		downloadRegisterOas.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mountCard({ id: 7, title: 'Reg', schemas: [] }, 'register')
		await action(wrapper, 'Download API Specification').trigger('click')
		await flushPromises()
		expect(showError).toHaveBeenCalledWith('Failed to download API specification')
		spy.mockRestore()
	})
})

describe('schema card', () => {
	it('sorts ordered properties first, then by creation date, and marks required ones', () => {
		const item = {
			id: 5,
			title: 'S',
			required: ['b'],
			properties: {
				a: { type: 'string', created: '2026-02-01' },
				b: { type: 'integer', order: 2 },
				c: { type: 'boolean', created: '2026-01-01' },
				d: { type: 'number', order: 1 },
			},
		}
		const wrapper = mountCard(item, 'schema')
		const rows = wrapper.findAll('tbody tr').map((r) => r.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['d', 'number'],
			['b (required)', 'integer'],
			['c', 'boolean'],
			['a', 'string'],
		])
	})

	it('shows five properties and expands to all', async () => {
		const properties = Object.fromEntries(
			[1, 2, 3, 4, 5, 6].map((n) => [`p${n}`, { type: 'string', order: n }]),
		)
		const wrapper = mountCard({ id: 5, title: 'S', properties }, 'schema')
		expect(wrapper.findAll('tbody tr')).toHaveLength(5)
		const more = wrapper.find('.viewMoreContainer button')
		expect(more.text()).toBe('View 1 more')
		await more.trigger('click')
		expect(wrapper.findAll('tbody tr')).toHaveLength(6)
	})

	it('says so when the schema has no properties', () => {
		const wrapper = mountCard({ id: 5, title: 'S' }, 'schema')
		expect(wrapper.find('.emptyText').text()).toBe('No properties found')
	})

	it('shows extended and in-use pills and disables delete while objects exist', () => {
		const wrapper = mountCard(
			{ id: 5, title: 'S', extend: 'base', properties: {}, stats: { objects: { total: 2 } } },
			'schema',
		)
		expect(wrapper.classes()).toContain('registerSchemaCard--in-use')
		expect(wrapper.findAll('.statusPill').map((p) => p.text())).toEqual(['Extended', 'In use'])
		expect(action(wrapper, 'Delete').attributes('disabled')).toBeDefined()
	})

	it('offers no register-only actions', () => {
		const wrapper = mountCard({ id: 5, title: 'S', properties: {} }, 'schema')
		const labels = wrapper.findAll('button.NcActionButton').map((b) => b.text().trim())
		expect(labels).toEqual(['Edit', 'Delete'])
	})

	it('selects the schema before opening the edit modal', async () => {
		const item = { id: 5, title: 'S', properties: {} }
		const wrapper = mountCard(item, 'schema')
		await action(wrapper, 'Edit').trigger('click')
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(item)
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchema')
		expect(wrapper.findComponent({ name: 'EditRegisterFormDialog' }).exists()).toBe(false)
	})

	it('selects the schema before opening the delete dialog', async () => {
		const item = { id: 5, title: 'S', properties: {} }
		const wrapper = mountCard(item, 'schema')
		await action(wrapper, 'Delete').trigger('click')
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(item)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteSchema')
	})
})

describe('description', () => {
	it('toggles an existing description by click and keyboard', async () => {
		const wrapper = mountCard({ id: 5, title: 'S', description: 'Long text', properties: {} }, 'schema')
		const text = wrapper.find('.registerDescription')
		const toggle = text.element.parentElement
		expect(text.classes()).not.toContain('registerDescription--expanded')

		await toggle.dispatchEvent(new Event('click'))
		await flushPromises()
		expect(wrapper.find('.registerDescription').classes()).toContain('registerDescription--expanded')

		await wrapper.find('.registerDescription').element.parentElement.dispatchEvent(
			new KeyboardEvent('keydown', { key: 'Enter' }),
		)
		await flushPromises()
		expect(wrapper.find('.registerDescription').classes()).not.toContain('registerDescription--expanded')

		await wrapper.find('.registerDescription').element.parentElement.dispatchEvent(
			new KeyboardEvent('keydown', { key: ' ' }),
		)
		await flushPromises()
		expect(wrapper.find('.registerDescription').classes()).toContain('registerDescription--expanded')
	})

	it('shows a placeholder and does not expand without a description', async () => {
		const wrapper = mountCard({ id: 5, title: 'S', properties: {} }, 'schema')
		const text = wrapper.find('.registerDescription')
		expect(text.text()).toBe('No description found')
		expect(text.classes()).toContain('registerDescription--empty')
		await text.element.parentElement.dispatchEvent(new Event('click'))
		await text.element.parentElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
		await text.element.parentElement.dispatchEvent(new KeyboardEvent('keydown', { key: ' ' }))
		await flushPromises()
		expect(wrapper.find('.registerDescription').classes()).not.toContain('registerDescription--expanded')
	})
})
