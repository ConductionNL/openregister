import { getCurrentUser } from '@nextcloud/auth'
import { mount } from '@vue/test-utils'
import MainMenu from './MainMenu.vue'
import { organisationStore } from '../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, s) => `[${app}] ${s}` }))
jest.mock('@nextcloud/auth', () => ({ __esModule: true, getCurrentUser: jest.fn() }))
jest.mock('../store/store.js', () => {
	const { reactive } = require('vue')
	return { __esModule: true, organisationStore: reactive({ activeOrganisation: null }) }
})
jest.mock('../manifest.json', () => ({ menu: [{ id: 'dashboard' }], runtime: { user: { locale: 'nl' }, flag: true } }))
jest.mock('vue-material-design-icons/Finance.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
// The library nav is a stand-in that exposes the props it gets and renders
// the primary-action slot this menu fills.
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnAppNav: {
		name: 'CnAppNav',
		props: ['manifest', 'translate', 'isAdmin'],
		template: '<nav><slot name="primary-action" /></nav>',
	},
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppNavigationNew: {
		name: 'NcAppNavigationNew',
		props: ['text'],
		emits: ['click'],
		template: '<button class="org" @click="$emit(\'click\')">{{ text }}<slot name="icon" /></button>',
	},
}))

/**
 * Mount the menu with a recording router.
 *
 * @return {object} { wrapper, push }
 */
function mountMenu() {
	const push = jest.fn()
	const wrapper = mount(MainMenu, { global: { mocks: { $router: { push } } } })
	return { wrapper, push }
}

const nav = (wrapper) => wrapper.findComponent({ name: 'CnAppNav' })

describe('MainMenu', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		organisationStore.activeOrganisation = null
		getCurrentUser.mockReturnValue({ isAdmin: false })
	})

	it('shows the active organisation name on the primary action', () => {
		organisationStore.activeOrganisation = { name: 'Gemeente Tilburg' }
		expect(mountMenu().wrapper.find('button.org').text()).toBe('Gemeente Tilburg')
	})

	it.each([null, { uuid: 'x' }])('falls back to No Organisation for %j', (org) => {
		organisationStore.activeOrganisation = org
		expect(mountMenu().wrapper.find('button.org').text()).toBe('[openregister] No Organisation')
	})

	it('navigates to the dashboard from the primary action', async () => {
		const { wrapper, push } = mountMenu()
		await wrapper.find('button.org').trigger('click')
		expect(push).toHaveBeenCalledWith('/')
	})

	it('passes the manifest with the admin flag merged into the runtime user', () => {
		getCurrentUser.mockReturnValue({ isAdmin: true })
		const props = nav(mountMenu().wrapper).props()
		expect(props.isAdmin).toBe(true)
		expect(props.manifest).toEqual({
			menu: [{ id: 'dashboard' }],
			runtime: { user: { locale: 'nl', isAdmin: true }, flag: true },
		})
	})

	it.each([
		['a non-admin', () => ({ isAdmin: false })],
		['no user', () => null],
		['a truthy non-boolean flag', () => ({ isAdmin: 'yes' })],
		['a throwing auth lookup', () => { throw new Error('no session') }],
	])('is not admin for %s', (_, user) => {
		getCurrentUser.mockImplementation(user)
		const props = nav(mountMenu().wrapper).props()
		expect(props.isAdmin).toBe(false)
		expect(props.manifest.runtime.user.isAdmin).toBe(false)
	})

	it('builds a runtime user when the manifest has no runtime', () => {
		const { wrapper } = mountMenu()
		wrapper.vm.manifest = { menu: [] }
		expect(wrapper.vm.navManifest).toEqual({ menu: [], runtime: { user: { isAdmin: false } } })
	})

	it('translates menu keys in the openregister domain', () => {
		const translate = nav(mountMenu().wrapper).props('translate')
		expect(translate('Registers')).toBe('[openregister] Registers')
	})
})
