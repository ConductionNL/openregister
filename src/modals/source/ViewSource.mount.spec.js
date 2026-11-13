/**
 * The view-source modal mounted: what it shows of the selected source, the
 * registers that belong to it, the empty and logs tabs, and the actions that
 * edit or delete the source, open or edit a register and close the modal.
 *
 * @spec exclude Client-side view-source modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ViewSource from './ViewSource.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The modal mixes <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcActionButton: mockLibrary().NcActionButton,
	NcActions: mockLibrary().NcActions,
	NcDialog: mockLibrary().NcDialog,
}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => mockModule('DatabaseOutline'))
jest.mock('vue-material-design-icons/Eye.vue', () => mockModule('Eye'))
jest.mock('vue-material-design-icons/Pencil.vue', () => mockModule('Pencil'))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => mockModule('TrashCanOutline'))
jest.mock('../../components/shared/CloseCancelButton.vue', () => mockModule('CloseCancelButton'))
jest.mock('../register/EditRegisterFormDialog.vue', () => mockModule('EditRegisterFormDialog'))
jest.mock('../shared/EmptyLogsTab.vue', () => mockModule('EmptyLogsTab'))
jest.mock('../shared/TabEmptyContent.vue', () => mockModule('TabEmptyContent'))
jest.mock('../shared/TimestampProperties.vue', () => mockModule('TimestampProperties'))
jest.mock('../shared/ViewDialogTabs.vue', () => mockModule('ViewDialogTabs'))
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		navigationStore: reactive({ modal: 'viewSource', setModal: jest.fn(), setDialog: jest.fn() }),
		registerStore: reactive({ registerList: [], refreshRegisterList: jest.fn(), setRegisterItem: jest.fn() }),
		sourceStore: reactive({ sourceItem: null }),
	}
})

const { navigationStore, registerStore, sourceStore } = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
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
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and every slot.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'function')
					.map(([key, value]) => [key, typeof value === 'object' ? JSON.stringify(value) : value]),
			)
			const slots = Object.entries(this.$slots).map(([slot, fn]) =>
				render('section', { 'data-slot': slot }, fn()),
			)
			return render(`x-${name}`, attrs, slots)
		},
	}
}

const router = { push: jest.fn() }

/**
 * Mount the modal with a router stand-in.
 *
 * @return {Promise<object>} The wrapper, after the register load settled
 */
async function open() {
	const wrapper = mount(ViewSource, {
		global: {
			config: { globalProperties: { $router: router, t: (app, text) => `t:${text}` } },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The action button with the given label.
 *
 * @param {object} wrapper The mounted modal
 * @param {string} label Its text
 * @return {object} The button stub
 */
function action(wrapper, label) {
	const button = wrapper
		.findAllComponents({ name: 'NcActionButton' })
		.find((candidate) => candidate.text().trim().endsWith(label))
	expect(button).toBeDefined()
	return button
}

beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.modal = 'viewSource'
	sourceStore.sourceItem = {
		id: 5,
		title: 'Main DB',
		description: 'The primary store',
		type: 'postgresql',
		databaseUrl: 'postgres://db/main',
		created: '2026-01-01',
		updated: '2026-02-01',
	}
	registerStore.registerList = [
		{ id: 1, title: 'People', description: 'Inhabitants', source: 5 },
		{ id: 2, title: 'Cars', source: '5' },
		{ id: 3, title: 'Elsewhere', source: 9 },
		{ id: 4, title: 'Sourceless' },
	]
	registerStore.refreshRegisterList.mockResolvedValue()
})

describe('ViewSource mounted', () => {
	it('renders nothing while another modal is open', async () => {
		navigationStore.modal = 'editSource'
		const wrapper = await open()
		expect(wrapper.findComponent({ name: 'NcDialog' }).exists()).toBe(false)
	})

	it('names the dialog after the source and shows its properties', async () => {
		const wrapper = await open()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		expect(dialog.attributes('name')).toBe('View Source: Main DB')
		expect(wrapper.get('h2').text()).toBe('Main DB')
		expect(wrapper.get('.sourceDescription').text()).toBe('The primary store')
		const properties = wrapper.findAll('.propertyItem').map((item) => item.text())
		expect(properties).toEqual(['t:Type:postgresql', 't:Database URL:postgres://db/main'])
		const timestamps = wrapper.findComponent({ name: 'TimestampProperties' })
		expect(timestamps.attributes()).toMatchObject({ created: '2026-01-01', updated: '2026-02-01' })
	})

	it('falls back to Unknown and hides the optional properties', async () => {
		sourceStore.sourceItem = { id: 5 }
		const wrapper = await open()
		expect(wrapper.findComponent({ name: 'NcDialog' }).attributes('name')).toBe('View Source: Unknown')
		expect(wrapper.find('.sourceDescription').exists()).toBe(false)
		expect(wrapper.findAll('.propertyItem').map((item) => item.text())).toEqual(['t:Type:Unknown'])
	})

	it('loads the registers on mount and lists only those of this source', async () => {
		const wrapper = await open()
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.registersLoading).toBe(false)
		const cards = wrapper.findAll('.registerCard')
		expect(cards.map((card) => card.get('h3').text())).toEqual(['People', 'Cars'])
		expect(cards[0].get('.registerDescription').text()).toBe('Inhabitants')
		expect(cards[1].find('.registerDescription').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TabEmptyContent' }).exists()).toBe(false)
	})

	it('clears the loading flag when the register load fails', async () => {
		registerStore.refreshRegisterList.mockRejectedValue(new Error('down'))
		const wrapper = await open()
		expect(wrapper.vm.registersLoading).toBe(false)
	})

	it('shows the empty state when no register belongs to the source', async () => {
		registerStore.registerList = [{ id: 3, title: 'Elsewhere', source: 9 }]
		const wrapper = await open()
		expect(wrapper.find('.registerCard').exists()).toBe(false)
		const empty = wrapper.findComponent({ name: 'TabEmptyContent' })
		expect(empty.attributes()).toMatchObject({
			name: 't:No registers found',
			description: 't:This source has no associated registers.',
		})
	})

	it('shows the logs tab for the source when the second tab is active', async () => {
		const wrapper = await open()
		const tabs = wrapper.findComponent({ name: 'ViewDialogTabs' })
		expect(tabs.attributes('tabs')).toBe(JSON.stringify(['t:Registers', 't:Logs']))
		await tabs.vm.$emit('update:modelValue', 1)
		expect(wrapper.find('.registerCard').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'EmptyLogsTab' }).attributes('subject')).toBe('source')
	})

	it('opens a register in the registers view and closes itself', async () => {
		const wrapper = await open()
		const card = wrapper.findAll('.registerCard')[0]
		card.findAllComponents({ name: 'NcActionButton' })[0].vm.$emit('click')
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(registerStore.registerList[0])
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(router.push).toHaveBeenCalledWith('/registers')
	})

	it('edits a register in its own dialog and refreshes after a save', async () => {
		const wrapper = await open()
		expect(wrapper.findComponent({ name: 'EditRegisterFormDialog' }).exists()).toBe(false)
		const card = wrapper.findAll('.registerCard')[1]
		await card.findAllComponents({ name: 'NcActionButton' })[1].vm.$emit('click')
		const dialog = wrapper.findComponent({ name: 'EditRegisterFormDialog' })
		expect(dialog.vm.$attrs.item).toEqual({ id: 2, title: 'Cars', source: '5' })

		dialog.vm.$attrs.afterSave()
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(2)

		await dialog.vm.$emit('close')
		expect(wrapper.findComponent({ name: 'EditRegisterFormDialog' }).exists()).toBe(false)
	})

	it('edits, deletes and closes through its dialog actions', async () => {
		const wrapper = await open()
		action(wrapper, 'Edit Source').vm.$emit('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('editSource')

		action(wrapper, 'Delete Source').vm.$emit('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteSource')

		navigationStore.setModal.mockClear()
		wrapper.findComponent({ name: 'CloseCancelButton' }).vm.$emit('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})
