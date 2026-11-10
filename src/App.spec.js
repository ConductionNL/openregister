/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app shell: start-up, the object sidebar, the user settings hash and the
 * walkthrough entries it hands to the user settings.
 *
 * @spec exclude Unit test of the client-side app shell.
 */
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import App from './App.vue'
import { initializeAppData } from './services/AppInitializationService.js'
import { setupDashboardStoreWatchers } from './store/modules/dashboard.js'

jest.mock('./services/AppInitializationService.js', () => ({ initializeAppData: jest.fn() }))
jest.mock('./store/modules/dashboard.js', () => ({ setupDashboardStoreWatchers: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))
const mockContinue = jest.fn()
const mockRestart = jest.fn()
jest.mock('@conduction/nextcloud-vue', () => ({
	CnAppRoot: {
		name: 'CnAppRoot',
		props: ['manifest', 'registry', 'customComponents', 'pageTypes', 'translate', 'appId'],
		data: () => ({
			userSettingsOpen: false,
			walkthroughEnabled: false,
			walkthroughProgressValue: 0,
			restartWalkthroughSectionName: 'Tour',
			restartWalkthroughHint: 'Take the tour',
			continueWalkthroughLabel: 'Continue',
			startOverWalkthroughLabel: 'Start over',
			restartWalkthroughLabel: 'Restart',
		}),
		methods: {
			continueWalkthroughFromSettings: () => mockContinue(),
			restartWalkthroughFromSettings: () => mockRestart(),
		},
		template: `<div>
			<slot name="menu" />
			<slot name="sidebar" :pageSidebarComponent="pageSidebar" />
			<slot name="user-settings" />
			<slot name="footer" />
		</div>`,
		computed: { pageSidebar: () => null },
	},
	CnObjectSidebar: {
		name: 'CnObjectSidebar',
		props: ['title', 'objectId', 'open', 'useRegistry'],
		emits: ['update:open'],
		render: () => null,
	},
}))
jest.mock('./navigation/MainMenu.vue', () => ({ name: 'MainMenu', render: () => null }))
jest.mock('./modals/Modals.vue', () => ({ name: 'Modals', render: () => null }))
jest.mock('./dialogs/Dialogs.vue', () => ({ name: 'Dialogs', render: () => null }))
jest.mock('./sidebars/SideBars.vue', () => {
	const { inject: injectState } = require('vue')
	return {
		name: 'SideBars',
		setup() {
			return { state: injectState('objectSidebarState') }
		},
		render: () => null,
	}
})
jest.mock('./views/settings/UserSettings.vue', () => ({
	name: 'UserSettings',
	props: ['manifest', 'walkthrough'],
	emits: ['continueWalkthrough', 'restartWalkthrough'],
	render: () => null,
}))

const MANIFEST = { pages: [] }

/**
 * Mount the app.
 *
 * @return {object} The wrapper
 */
function mountApp() {
	return mount(App, { props: { manifest: MANIFEST } })
}

beforeEach(() => {
	jest.clearAllMocks()
	window.location.hash = ''
})

describe('App', () => {
	it('starts the app data and dashboard watchers once mounted', () => {
		const wrapper = mountApp()
		expect(initializeAppData).toHaveBeenCalledTimes(1)
		expect(setupDashboardStoreWatchers).toHaveBeenCalledTimes(1)
		const root = wrapper.findComponent({ name: 'CnAppRoot' })
		expect(root.props('manifest')).toEqual(MANIFEST)
		expect(root.props('translate')('Objects')).toBe('openregister:Objects')
	})

	it('shows the object sidebar once a page activates the shared state', async () => {
		const wrapper = mountApp()
		expect(wrapper.findComponent({ name: 'CnObjectSidebar' }).exists()).toBe(false)
		const state = wrapper.findComponent({ name: 'SideBars' }).vm.state
		state.active = true
		state.title = 'Case 1'
		state.objectId = 'o-1'
		await nextTick()
		const sidebar = wrapper.findComponent({ name: 'CnObjectSidebar' })
		expect(sidebar.props()).toEqual(
			expect.objectContaining({ title: 'Case 1', objectId: 'o-1', open: true, useRegistry: true }),
		)
		sidebar.vm.$emit('update:open', false)
		expect(state.open).toBe(false)
	})

	it('opens the user settings for #user-settings, on load and on a hash change', async () => {
		window.location.hash = '#user-settings'
		const wrapper = mountApp()
		const root = wrapper.findComponent({ name: 'CnAppRoot' }).vm
		expect(root.userSettingsOpen).toBe(true)
		root.userSettingsOpen = false
		window.location.hash = '#other'
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		expect(root.userSettingsOpen).toBe(false)
		window.location.hash = '#user-settings'
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		expect(root.userSettingsOpen).toBe(true)
	})

	it('stops following the hash once unmounted', () => {
		const wrapper = mountApp()
		const root = wrapper.findComponent({ name: 'CnAppRoot' }).vm
		wrapper.unmount()
		window.location.hash = '#user-settings'
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		expect(root.userSettingsOpen).toBe(false)
	})

	it('hands no walkthrough to the user settings while it is off', () => {
		const wrapper = mountApp()
		expect(wrapper.vm.walkthroughSettings()).toBeNull()
	})

	it('offers restart, or start over once a walkthrough is in progress', async () => {
		const wrapper = mountApp()
		const root = wrapper.findComponent({ name: 'CnAppRoot' }).vm
		root.walkthroughEnabled = true
		expect(wrapper.vm.walkthroughSettings()).toEqual({
			sectionName: 'Tour',
			hint: 'Take the tour',
			inProgress: false,
			continueLabel: 'Continue',
			restartLabel: 'Restart',
		})
		root.walkthroughProgressValue = 3
		expect(wrapper.vm.walkthroughSettings()).toEqual(
			expect.objectContaining({ inProgress: true, restartLabel: 'Start over' }),
		)
	})

	it('passes continue and restart from the user settings to the app root', () => {
		const wrapper = mountApp()
		const settings = wrapper.findComponent({ name: 'UserSettings' })
		expect(settings.props('manifest')).toEqual(MANIFEST)
		settings.vm.$emit('continueWalkthrough')
		settings.vm.$emit('restartWalkthrough')
		expect(mockContinue).toHaveBeenCalledTimes(1)
		expect(mockRestart).toHaveBeenCalledTimes(1)
	})
})

