/**
 * The migration modal as rendered at each step: the overview of source and
 * target, the selection, the register and schema choice, the property
 * mapping, the report, and which buttons each step offers.
 *
 * @spec exclude Client-side dialog markup and its event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import MigrationObject from './MigrationObject.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		`t:${text.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match))}`,
	translatePlural: (app, one, many, count, vars) =>
		`n:${(count === 1 ? one : many).replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match))}`,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
// With a setup block next to the options block, the compiled template reaches
// the library components through the `vue` module, as selectedObjects.dom does.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcButton: mockLibrary().NcButton,
	NcDialog: mockLibrary().NcDialog,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
	NcNoteCard: mockLibrary().NcNoteCard,
	NcSelect: mockLibrary().NcSelect,
}))
jest.mock('../../components/shared/SelectedObjectsList.vue', () => mockIcon('SelectedObjectsList'))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockIcon('ArrowLeft'))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => mockIcon('ArrowRight'))
jest.mock('vue-material-design-icons/Cancel.vue', () => mockIcon('Cancel'))
jest.mock('vue-material-design-icons/DatabaseExport.vue', () => mockIcon('DatabaseExport'))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => mockIcon('DatabaseOutline'))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => mockIcon('FileTreeOutline'))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: {
		selectedObjects: [],
		searchCollection: [],
		refetchSearchCollection: jest.fn(),
		refreshObjectList: jest.fn(() => Promise.resolve()),
	},
	registerStore: { registerItem: { id: 1, title: 'Zaken' } },
	schemaStore: {
		schemaItem: {
			id: 2,
			title: 'Melding',
			properties: { title: { type: 'string' }, size: { type: 'integer' }, note: {} },
		},
	},
}))

const { navigationStore, objectStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name, shared
 * between the `@nextcloud/vue` and `vue` mocks.
 *
 * @return {object} The module
 */
function mockLibrary() {
	globalThis.migrationStubs = globalThis.migrationStubs || {}
	const made = globalThis.migrationStubs
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A component module whose default export is a stub.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockIcon(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(([key]) => !/^on[A-Z]/.test(key) && typeof this.$attrs[key] !== 'object'),
			)
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => render(`slot-${slot}`, this.$slots[slot]({}))),
			)
		},
	}
}

const REGISTERS = [
	{ id: 3, title: 'Archief', schemas: [{ id: 4, title: 'Dossier' }] },
]

/**
 * Answer every fetch from a table of url prefixes.
 *
 * @param {object} answers Body per url prefix
 * @return {void}
 */
function answerFetches(answers) {
	global.fetch = jest.fn((url) => {
		const prefix = Object.keys(answers).find((key) => url.startsWith(key))
		const body = answers[prefix] ?? {}
		return Promise.resolve({ ok: !body.error, json: () => Promise.resolve(body) })
	})
}

/**
 * The dialog, mounted over two selected objects with the registers loaded.
 *
 * @return {Promise<object>} The wrapper
 */
async function dialog() {
	objectStore.selectedObjects = [
		{ '@self': { id: 'a' }, title: 'One' },
		{ '@self': { id: 'b' }, title: 'Two' },
	]
	answerFetches({
		'/index.php/apps/openregister/api/registers': { results: REGISTERS },
		'/index.php/apps/openregister/api/schemas/4': {
			properties: { title: { type: 'string' }, weight: { type: 'number' } },
			required: ['title'],
		},
	})
	const wrapper = mount(MigrationObject, {
		global: { config: { warnHandler: () => {} } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The action buttons by their text.
 *
 * @param {object} wrapper The mounted dialog
 * @return {Array<string>} The texts
 */
function buttons(wrapper) {
	return wrapper.findAllComponents({ name: 'NcButton' }).map((b) => b.text().trim())
}

/**
 * The action button showing the text.
 *
 * @param {object} wrapper The mounted dialog
 * @param {string} text The button text
 * @return {object} The button
 */
function button(wrapper, text) {
	const found = wrapper
		.findAllComponents({ name: 'NcButton' })
		.find((b) => b.text().trim() === `t:${text}`)
	expect(found).toBeDefined()
	return found
}

/**
 * Walk the dialog to the mapping step for register 3 and schema 4.
 *
 * @param {object} wrapper The mounted dialog
 * @return {Promise<void>}
 */
async function toMapping(wrapper) {
	button(wrapper, 'Next').vm.$attrs.onClick()
	await flushPromises()
	wrapper.vm.$.proxy.targetRegister = REGISTERS[0]
	wrapper.vm.$.proxy.onRegisterChange()
	wrapper.vm.$.proxy.targetSchema = REGISTERS[0].schemas[0]
	await wrapper.vm.$.proxy.onSchemaChange()
	await flushPromises()
	button(wrapper, 'Next').vm.$attrs.onClick()
	await flushPromises()
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	registerStore.registerItem = { id: 1, title: 'Zaken' }
})
afterEach(() => console.error.mockRestore())

describe('MigrationObject step 1', () => {
	it('names the count, the source and an unchosen target', async () => {
		const wrapper = await dialog()
		const name = wrapper.findComponent({ name: 'NcDialog' }).vm.$attrs.name
		expect(name).toBe('n:Migrate 2 objects')
		const values = wrapper.findAll('.card-value').map((v) => v.text())
		expect(values).toEqual(['Zaken', 'Melding', 't:Not selected', 't:Not selected'])
		expect(wrapper.find('.step-1').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'SelectedObjectsList' }).vm.$attrs.objects).toEqual([
			{ '@self': { id: 'a' }, title: 'One', id: 'a' },
			{ '@self': { id: 'b' }, title: 'Two', id: 'b' },
		])
		expect(buttons(wrapper)).toEqual(['t:Cancel', 't:Next'])
		expect(button(wrapper, 'Next').vm.$attrs.disabled).toBe(false)
	})

	it('falls back to the source ids when they have no title', async () => {
		registerStore.registerItem = { id: 1 }
		const title = schemaStore.schemaItem.title
		delete schemaStore.schemaItem.title
		try {
			const wrapper = await dialog()
			expect(wrapper.findAll('.card-value').map((v) => v.text()).slice(0, 2)).toEqual(['1', '2'])
		} finally {
			schemaStore.schemaItem.title = title
		}
	})

	it('reads the registers with their schemas', async () => {
		const wrapper = await dialog()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/registers?_extend[]=schemas')
		expect(wrapper.vm.$.proxy.availableRegisters).toEqual(REGISTERS)
		expect(wrapper.vm.$.proxy.loading).toBe(false)
	})

	it('cancels through the navigation store', async () => {
		const wrapper = await dialog()
		button(wrapper, 'Cancel').vm.$attrs.onClick()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})

describe('MigrationObject step 2', () => {
	it('offers the registers, and the schemas only once a register is chosen', async () => {
		const wrapper = await dialog()
		button(wrapper, 'Next').vm.$attrs.onClick()
		await flushPromises()

		expect(wrapper.find('.migration-step h3').text()).toBe('t:Choose the target register and schema')
		expect(wrapper.find('.migration-step p').text()).toBe('n:Choose where 2 selected objects go.')
		let selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects).toHaveLength(1)
		expect(selects[0].vm.$attrs.options).toEqual(REGISTERS)
		expect(buttons(wrapper)).toEqual(['t:Cancel', 't:Back', 't:Next'])
		expect(button(wrapper, 'Next').vm.$attrs.disabled).toBe(true)

		wrapper.vm.$.proxy.targetRegister = REGISTERS[0]
		wrapper.vm.$.proxy.onRegisterChange()
		await flushPromises()
		selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects).toHaveLength(2)
		expect(selects[1].vm.$attrs.options).toEqual([{ id: 4, title: 'Dossier' }])
		expect(wrapper.findAll('.card-value').map((v) => v.text())[2]).toBe('Archief')
		expect(button(wrapper, 'Next').vm.$attrs.disabled).toBe(true)
	})

	it('goes back to the selection', async () => {
		const wrapper = await dialog()
		button(wrapper, 'Next').vm.$attrs.onClick()
		await flushPromises()
		button(wrapper, 'Back').vm.$attrs.onClick()
		await flushPromises()
		expect(wrapper.find('.step-1').exists()).toBe(true)
	})
})

describe('MigrationObject step 3', () => {
	it('lists each source property with a target select, auto-mapping same names', async () => {
		const wrapper = await dialog()
		await toMapping(wrapper)

		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/schemas/4')
		expect(wrapper.find('.migration-step h3').text()).toBe('t:Property mapping')
		expect(wrapper.text()).toContain('t:You move objects from Melding to Dossier.')
		const rows = wrapper.findAll('.mapping-row')
		expect(rows.map((row) => row.find('.property-name').text())).toEqual(['title', 'size', 'note'])
		expect(rows.map((row) => row.find('.property-type').text())).toEqual(['string', 'integer', 'string'])

		const select = rows[0].findComponent({ name: 'NcSelect' })
		expect(select.vm.$attrs.inputLabel).toBe('t:Target property for title')
		expect(select.vm.$attrs.options).toEqual([
			{ label: 'title (string)', value: 'title' },
			{ label: 'weight (number)', value: 'weight' },
			{ label: 't:(Do not map)', value: null },
		])
		expect(select.vm.$attrs.modelValue).toEqual({ label: 'title (string)', value: 'title' })
		expect(rows[1].findComponent({ name: 'NcSelect' }).vm.$attrs.modelValue).toBeUndefined()
		expect(wrapper.vm.$.proxy.mapping).toEqual({ title: 'title' })
		expect(buttons(wrapper)).toEqual(['t:Cancel', 't:Back', 't:Migrate objects'])
		expect(button(wrapper, 'Migrate objects').vm.$attrs.disabled).toBe(false)
	})

	it('maps a property chosen in its select, and is not migratable with nothing mapped', async () => {
		const wrapper = await dialog()
		await toMapping(wrapper)

		const sizeSelect = wrapper.findAll('.mapping-row')[1].findComponent({ name: 'NcSelect' })
		sizeSelect.vm.$emit('update:modelValue', { label: 'weight (number)', value: 'weight' })
		await flushPromises()
		expect(wrapper.vm.$.proxy.mapping).toEqual({ title: 'title', weight: 'size' })

		wrapper.vm.$.proxy.uiMappings = { title: { label: 't:(Do not map)', value: null } }
		await flushPromises()
		expect(button(wrapper, 'Migrate objects').vm.$attrs.disabled).toBe(true)
	})

	it('migrates through the endpoint and shows the report', async () => {
		const wrapper = await dialog()
		await toMapping(wrapper)
		global.fetch = jest.fn(() => Promise.resolve({
			ok: true,
			json: () => Promise.resolve({
				success: true,
				statistics: { objectsMigrated: 2, objectsFailed: 0, propertiesMapped: 1, propertiesDiscarded: 2 },
				details: [
					{ objectId: 'a', objectTitle: 'One', success: true },
					{ objectId: 'b', success: false, error: 'Schema mismatch' },
				],
				warnings: ['Two properties discarded'],
			}),
		}))

		await button(wrapper, 'Migrate objects').vm.$attrs.onClick()
		await flushPromises()

		expect(global.fetch.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/migrate')
		expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({
			sourceRegister: 1,
			sourceSchema: 2,
			targetRegister: 3,
			targetSchema: 4,
			objects: ['a', 'b'],
			mapping: { title: 'title' },
		})
		expect(wrapper.find('.report-title').text()).toBe('t:Migration report')
		const notes = wrapper.findAllComponents({ name: 'NcNoteCard' }).map((note) => note.vm.$attrs.type)
		expect(notes).toEqual(['success'])
		expect(wrapper.find('.object-count').text()).toBe('n:2 objects')
		expect(wrapper.findAll('.migration-meta span')[0].text()).toBe('Zaken / Melding')
		expect(wrapper.findAll('.report-section ul')[0].findAll('li').map((li) => li.text())).toEqual([
			't:Objects migrated: 2',
			't:Objects failed: 0',
			't:Properties mapped: 1',
			't:Properties discarded: 2',
		])
		const details = wrapper.findAll('.migration-detail-item')
		expect(details.map((d) => d.find('strong').text())).toEqual(['One', 'b'])
		expect(details.map((d) => d.find('.status').classes())).toEqual([
			['status', 'success'],
			['status', 'error'],
		])
		expect(details.map((d) => d.find('.status').text())).toEqual(['t:Success', 't:Failed'])
		expect(details[1].find('.detail-error').text()).toBe('Schema mismatch')
		expect(details[0].find('.detail-error').exists()).toBe(false)
		expect(wrapper.find('.warning-text').text()).toBe('Two properties discarded')
		expect(buttons(wrapper)).toEqual(['t:Close'])
		expect(objectStore.refetchSearchCollection).toHaveBeenCalledTimes(1)
	})

	it('reports a refused migration with zero statistics', async () => {
		const wrapper = await dialog()
		await toMapping(wrapper)
		global.fetch = jest.fn(() => Promise.resolve({
			ok: false,
			json: () => Promise.resolve({ error: 'Target schema is locked' }),
		}))

		await button(wrapper, 'Migrate objects').vm.$attrs.onClick()
		await flushPromises()

		const notes = wrapper.findAllComponents({ name: 'NcNoteCard' }).map((note) => note.vm.$attrs.type)
		expect(notes).toEqual(['error'])
		expect(wrapper.text()).toContain('t:Migration failed. Check the details below.')
		expect(wrapper.findAll('.report-section ul')[0].findAll('li').map((li) => li.text())).toEqual([
			't:Objects migrated: 0',
			't:Objects failed: 0',
			't:Properties mapped: 0',
			't:Properties discarded: 0',
		])
		expect(wrapper.find('.migration-details').exists()).toBe(false)
		expect(wrapper.find('.error-text').text()).toBe('Target schema is locked')
		expect(objectStore.refetchSearchCollection).not.toHaveBeenCalled()
	})

	it('goes back to the register and schema choice', async () => {
		const wrapper = await dialog()
		await toMapping(wrapper)
		button(wrapper, 'Back').vm.$attrs.onClick()
		await flushPromises()
		expect(wrapper.find('.migration-step h3').text()).toBe('t:Choose the target register and schema')
	})
})

describe('MigrationObject loading the registers', () => {
	it('offers no registers when they cannot be read', async () => {
		objectStore.selectedObjects = [{ '@self': { id: 'a' } }]
		global.fetch = jest.fn(() => Promise.reject(new Error('offline')))
		const wrapper = mount(MigrationObject, { global: { config: { warnHandler: () => {} } } })
		await flushPromises()

		expect(wrapper.vm.$.proxy.availableRegisters).toEqual([])
		expect(wrapper.vm.$.proxy.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Error loading registers:', expect.any(Error))
		expect(wrapper.findComponent({ name: 'NcDialog' }).vm.$attrs.name).toBe('n:Migrate 1 object')
	})
})
