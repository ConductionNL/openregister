/**
 * The view-configuration modal: what it shows of the selected configuration
 * per tab, and where its actions send the user.
 *
 * @spec exclude Client-side modal markup and navigation plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import ViewConfiguration from './ViewConfiguration.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
// The compiled component binds `@nextcloud/vue` and `vue` to one variable, so
// the library stand-ins have to be reachable through `vue` as well.
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({
	__esModule: true,
	default: {
	name: 'CloseCancelButton',
	props: ['label'],
	emits: ['click'],
	render() {
		const { h } = jest.requireActual('vue')
		return h('button', { class: 'close', onClick: () => this.$emit('click') }, this.label)
	},},
}))
jest.mock('../shared/EmptyLogsTab.vue', () => ({
	__esModule: true,
	default: {
	name: 'EmptyLogsTab',
	props: ['subject'],
	render: () => null,},
}))
jest.mock('../shared/TabEmptyContent.vue', () => ({
	__esModule: true,
	default: {
	name: 'TabEmptyContent',
	props: ['name', 'description'],
	render: () => null,},
}))
jest.mock('../shared/TimestampProperties.vue', () => ({
	__esModule: true,
	default: {
	name: 'TimestampProperties',
	props: ['created', 'updated'],
	render: () => null,},
}))
jest.mock('../shared/ViewDialogTabs.vue', () => ({
	__esModule: true,
	default: {
	name: 'ViewDialogTabs',
	props: ['modelValue', 'tabs'],
	render() {
		return this.$slots.default?.()
	},},
}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationItem: null },
	navigationStore: { modal: null, setModal: jest.fn(), setDialog: jest.fn() },
}))

const { configurationStore, navigationStore } = jest.requireMock('../../store/store.js')

/**
 * Stand-ins for the dialog and its action buttons.
 *
 * @return {object} The components
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	return {
		NcDialog: {
			name: 'NcDialog',
			props: ['name'],
			render() {
				return h('x-dialog', [this.$slots.default?.(), this.$slots.actions?.()])
			},
		},
		NcActionButton: {
			name: 'NcActionButton',
			emits: ['click'],
			render() {
				return h(
					'button',
					{ class: 'action', onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		},
	}
}

/**
 * Mount the modal.
 *
 * @return {object} The wrapper
 */
function modal() {
	return mount(ViewConfiguration, {
		global: {
			config: { globalProperties: { t: (app, text) => text } },
		},
	})
}

/**
 * The action button with the given text.
 *
 * @param {object} wrapper The modal
 * @param {string} text The button text
 * @return {object} The button
 */
function action(wrapper, text) {
	return wrapper.findAll('button.action').find((node) => node.text() === text)
}

beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.modal = 'viewConfiguration'
	configurationStore.configurationItem = {
		title: 'Base',
		description: 'The base setup',
		type: 'local',
		owner: 'admin',
		created: '2026-01-01',
		updated: '2026-02-01',
		configuration: { registers: ['a'] },
	}
})

describe('ViewConfiguration', () => {
	it('renders nothing when another modal is open', () => {
		navigationStore.modal = 'editConfiguration'

		expect(modal().findComponent({ name: 'NcDialog' }).exists()).toBe(false)
	})

	it('shows the configuration details and its data', () => {
		const wrapper = modal()

		expect(wrapper.findComponent({ name: 'NcDialog' }).props('name')).toBe(
			'View Configuration: Base',
		)
		expect(wrapper.find('h2').text()).toBe('Base')
		expect(wrapper.find('.configurationDescription').text()).toBe('The base setup')
		const properties = wrapper.findAll('.propertyItem').map((node) => node.text())
		expect(properties).toEqual(['Type:local', 'Owner:admin'])
		expect(wrapper.findComponent({ name: 'TimestampProperties' }).props()).toEqual({
			created: '2026-01-01',
			updated: '2026-02-01',
		})
		expect(wrapper.findComponent({ name: 'ViewDialogTabs' }).props('tabs')).toEqual([
			'Configuration',
			'Logs',
		])
		expect(JSON.parse(wrapper.find('.jsonViewer pre').text())).toEqual({
			registers: ['a'],
		})
		expect(wrapper.findComponent({ name: 'TabEmptyContent' }).exists()).toBe(false)
	})

	it('falls back for a bare configuration', () => {
		configurationStore.configurationItem = {}
		const wrapper = modal()

		expect(wrapper.findComponent({ name: 'NcDialog' }).props('name')).toBe(
			'View Configuration: Unknown',
		)
		expect(wrapper.find('.configurationDescription').exists()).toBe(false)
		expect(wrapper.findAll('.propertyItem').map((node) => node.text())).toEqual([
			'Type:Unknown',
		])
		expect(wrapper.find('.jsonViewer').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'TabEmptyContent' }).props()).toEqual({
			name: 'No configuration data',
			description: 'This configuration has no data defined.',
		})
	})

	it('shows the logs tab', async () => {
		const wrapper = modal()

		wrapper.findComponent({ name: 'ViewDialogTabs' }).vm.$emit('update:modelValue', 1)
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent({ name: 'EmptyLogsTab' }).props('subject')).toBe(
			'configuration',
		)
		expect(wrapper.find('.jsonViewer').exists()).toBe(false)
	})

	it('opens the edit and export modals', async () => {
		const wrapper = modal()

		await action(wrapper, 'Edit Configuration').trigger('click')
		await action(wrapper, 'Export Configuration').trigger('click')

		expect(navigationStore.setModal.mock.calls).toEqual([
			['editConfiguration'],
			['exportConfiguration'],
		])
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('swaps itself for the delete dialog', async () => {
		const wrapper = modal()

		await action(wrapper, 'Delete Configuration').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteConfiguration')
	})

	it('closes', async () => {
		const wrapper = modal()

		await wrapper.find('button.close').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})
})
