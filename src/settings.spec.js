import { loadState } from '@nextcloud/initial-state'
import { translate, translatePlural } from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import Settings from './views/settings/Settings.vue'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'
import { registerLibraryTranslations } from './services/libraryTranslations.js'

// Every edge the admin-settings entry touches is faked; the mount target is
// recorded instead of a real DOM node.
jest.mock('vue', () => {
	const mockApp = { mixin: jest.fn(), use: jest.fn(), mount: jest.fn() }
	mockApp.use.mockReturnValue(mockApp)
	return {
		__esModule: true,
		createApp: jest.fn(() => mockApp),
		h: jest.fn((component, props) => ({ component, props })),
	}
})
jest.mock('pinia', () => ({ __esModule: true, createPinia: jest.fn(() => ({ pinia: true })) }))
jest.mock('@nextcloud/initial-state', () => ({ __esModule: true, loadState: jest.fn(() => 'installed') }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: jest.fn(), translatePlural: jest.fn() }))
jest.mock('./views/settings/Settings.vue', () => ({ __esModule: true, default: { name: 'Settings' } }))
jest.mock('./integrations/bootstrap.js', () => ({ __esModule: true, ensureIntegrationRegistry: jest.fn() }))
jest.mock('./services/libraryTranslations.js', () => ({ __esModule: true, registerLibraryTranslations: jest.fn() }))

describe('admin settings entry', () => {
	let app

	beforeAll(async () => {
		await import('./settings.js')
		app = createApp.mock.results[0].value
	})

	it('registers library translations and the integration registry before mounting', () => {
		expect(registerLibraryTranslations).toHaveBeenCalledTimes(1)
		expect(ensureIntegrationRegistry).toHaveBeenCalledTimes(1)
		expect(registerLibraryTranslations.mock.invocationCallOrder[0]).toBeLessThan(app.mount.mock.invocationCallOrder[0])
		expect(ensureIntegrationRegistry.mock.invocationCallOrder[0]).toBeLessThan(app.mount.mock.invocationCallOrder[0])
	})

	it('reads the push status from initial state with not_installed as the default', () => {
		expect(loadState).toHaveBeenCalledWith('openregister', 'push_status', 'not_installed')
	})

	it('renders Settings with the push status', () => {
		const { render } = createApp.mock.calls[0][0]
		expect(render()).toEqual({ component: Settings, props: { pushStatus: 'installed' } })
		expect(h).toHaveBeenCalledWith(Settings, { pushStatus: 'installed' })
	})

	it('exposes t and n as a global mixin, installs pinia and mounts on #settings', () => {
		expect(app.mixin).toHaveBeenCalledWith({ methods: { t: translate, n: translatePlural } })
		expect(app.use).toHaveBeenCalledWith({ pinia: true })
		expect(app.mount).toHaveBeenCalledWith('#settings')
	})
})
