/**
 * Golden record of the group picker in the application, organisation and view
 * edit modals: its markup, loading text and what its events do.
 *
 * @spec exclude Golden record of client-side modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import EditApplication from './application/EditApplication.vue'
import EditOrganisation from './organisation/EditOrganisation.vue'
import EditView from './view/EditView.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
	t: (app, text) => text,
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(() => Promise.resolve({ data: {} })) },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcButton: mockLibrary().NcButton,
	NcCheckboxRadioSwitch: mockLibrary().NcCheckboxRadioSwitch,
	NcDialog: mockLibrary().NcDialog,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
	NcNoteCard: mockLibrary().NcNoteCard,
	NcSelect: mockLibrary().NcSelect,
	NcTextArea: mockLibrary().NcTextArea,
	NcTextField: mockLibrary().NcTextField,
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => mockIcon('Cancel'))
jest.mock('vue-material-design-icons/Cog.vue', () => mockIcon('Cog'))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () =>
	mockIcon('ContentSaveOutline'),
)
jest.mock('vue-material-design-icons/Database.vue', () => mockIcon('Database'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockIcon('Plus'))
jest.mock('vue-material-design-icons/Shield.vue', () => mockIcon('Shield'))
jest.mock('vue-material-design-icons/AccountMultiple.vue', () =>
	mockIcon('AccountMultiple'),
)
jest.mock('vue-material-design-icons/AccountCircle.vue', () =>
	mockIcon('AccountCircle'),
)
jest.mock('vue-material-design-icons/AccountMinus.vue', () =>
	mockIcon('AccountMinus'),
)
jest.mock('vue-material-design-icons/AccountPlus.vue', () => mockIcon('AccountPlus'))
jest.mock('vue-material-design-icons/Close.vue', () => mockIcon('Close'))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	mockIcon('TrashCanOutline'),
)
jest.mock('vue-material-design-icons/ContentSave.vue', () => mockIcon('ContentSave'))
jest.mock('vue-material-design-icons/ShareVariant.vue', () =>
	mockIcon('ShareVariant'),
)
jest.mock('../components/tabs/AppTab.vue', () => mockTab())
jest.mock('../components/tabs/AppTabs.vue', () => mockTab())
jest.mock('../components/RbacTable.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('../services/groupSearch.js', () => ({
	searchGroupsDebounced: (dialog, options) =>
		globalThis.searches.push(options.query),
}))
jest.mock('../store/store.js', () => {
	const store = () =>
		new Proxy(
			{},
			{
				get(target, key) {
					if (key in target || typeof key !== 'string') return target[key]
					if (key === 'then') return undefined
					if (/Item$/.test(key)) return null
					if (/^(?!refresh|load).*(List|Groups)$/.test(key)) return []
					target[key] = () => Promise.resolve([])
					return target[key]
				},
				set(target, key, value) {
					target[key] = value
					return true
				},
			},
		)
	return new Proxy(
		{},
		{ get: (target, key) => (target[key] ??= store()), has: () => true },
	)
})

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	globalThis.madeStubs = globalThis.madeStubs || {}
	const made = globalThis.madeStubs
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
 * An icon module.
 *
 * @param {string} name The icon
 * @return {object} The module
 */
function mockIcon(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A tab that always shows its content.
 *
 * @return {object} The module
 */
function mockTab() {
	return {
		__esModule: true,
		default: {
			render() {
				return this.$slots.default?.()
			},
		},
	}
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
				Object.entries(this.$attrs).filter(
					([key, value]) =>
						!/^on[A-Z]/.test(key) && typeof value !== 'object',
				),
			)
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) =>
						render(`slot-${slot}`, this.$slots[slot]({ name: 'Group' })),
					),
			)
		},
	}
}

/**
 * The first group picker of a mounted modal.
 *
 * @param {object} wrapper The mounted modal
 * @return {string} The markup
 */
function picker(wrapper) {
	const html = wrapper
		.find('.groups-select-container')
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+/g, ' ')
		.replace(/> /g, '>')
		.replace(/ </g, '<')
		.replace(/></g, '>\n<')
	expect(html).not.toBe('')
	return html
}

describe.each([
	['EditApplication', EditApplication],
	['EditOrganisation', EditOrganisation],
	['EditView', EditView],
])('group picker of %s', (name, component) => {
	it('renders, shows loading and passes its events on', async () => {
		globalThis.searches = []
		const wrapper = mount(component, {
			props: name === 'EditView' ? { view: { id: 1, name: 'V' } } : {},
			global: {
				mocks: { t: (app, text) => text },
				config: { warnHandler: () => {} },
			},
		})
		await flushPromises()
		const vm = wrapper.vm.$.proxy
		vm.loadingGroups = false
		await flushPromises()
		const shown = picker(wrapper)
		vm.loadingGroups = true
		await flushPromises()
		const loading = picker(wrapper)
		vm.loadingGroups = false
		await flushPromises()
		const select = wrapper
			.find('.groups-select-container')
			.findComponent({ name: 'NcSelect' })
		select.vm.$attrs.onSearchChange('needle')
		const chosen = [{ id: 'g', name: 'Group' }]
		for (const handler of [select.vm.$attrs['onUpdate:modelValue']].flat()) {
			handler(chosen)
		}
		await flushPromises()
		expect({
			shown,
			loading,
			options: select.vm.$attrs.options === vm.availableGroups,
			searches: globalThis.searches,
			selected: vm.selectedGroups,
			stored: (vm.organisationItem ?? vm.applicationItem)?.groups,
		}).toMatchSnapshot()
	})
})
