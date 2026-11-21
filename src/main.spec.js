/**
 * Boot test of the main entry: what it registers, which routes it builds,
 * where it mounts, and how it registers the generic integration descriptors
 * against whatever registry and connector app the page has.
 *
 * Every Nextcloud, library and router edge is faked; the entry itself runs for
 * real, once per scenario, in an isolated module registry.
 */

jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	buildManifest: jest.fn((base, fragments, layout) => ({
		pages: [
			{ id: 'dashboard', route: '/' },
			{ id: 'register-detail', route: '/registers/:id' },
		],
		base,
		fragments,
		layout,
	})),
	CnPageRenderer: { name: 'CnPageRenderer' },
	CnIntegrationTab: { name: 'CnIntegrationTab' },
	CnIntegrationCard: { name: 'CnIntegrationCard' },
	defaultPageTypes: { index: 'IndexType' },
	registerBuiltinDashboardWidgets: jest.fn(),
	registerIcons: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn((app, s) => s),
	translatePlural: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((p) => `/index.php${p}`),
}))
jest.mock('vue', () => {
	const mockApp = { mixin: jest.fn(), use: jest.fn(), mount: jest.fn() }
	return {
		__esModule: true,
		createApp: jest.fn(() => mockApp),
		h: jest.fn((component, props) => ({ component, props })),
	}
})
jest.mock('vue-router', () => ({
	__esModule: true,
	createRouter: jest.fn((opts) => ({ router: true, opts })),
	createWebHistory: jest.fn((base) => ({ base })),
}))
jest.mock('./App.vue', () => ({ __esModule: true, default: { name: 'App' } }))
jest.mock('./customComponents.js', () => ({ __esModule: true, default: { AddIntegration: 'x' } }))
jest.mock('./icons.js', () => ({ __esModule: true, default: { Database: 'icon' } }))
jest.mock('./integrations/bootstrap.js', () => ({ __esModule: true, ensureIntegrationRegistry: jest.fn() }))
jest.mock('./manifest.json', () => ({ pages: [], menu: [], version: 'base' }))
jest.mock('./menu-layout.json', () => ({ relocations: [] }))
jest.mock('./pinia.js', () => ({ __esModule: true, default: { pinia: true } }))
jest.mock('./registry.js', () => ({ __esModule: true, default: { Dashboard: { kind: 'page' } } }))
jest.mock('./services/libraryTranslations.js', () => ({ __esModule: true, registerLibraryTranslations: jest.fn() }))
jest.mock('./views/task/FlowTaskDetail.vue', () => ({ __esModule: true, default: { name: 'FlowTaskDetail' } }))
jest.mock('./views/flows/FlowRunDetail.vue', () => ({ __esModule: true, default: { name: 'FlowRunDetail' } }))
jest.mock('./views/flows/FlowOverview.vue', () => ({ __esModule: true, default: { name: 'FlowOverview' } }))

// `require.context` is a webpack build-time API; jest's per-module `require`
// has no own `context`, so the lookup falls through to the Function.prototype
// of the realm that built it (jest's outer realm, not the test sandbox).
const RUNTIME_FUNCTION_PROTO = Object.getPrototypeOf(require)
const FRAGMENTS = { './b.json': { pages: ['b'] }, './a.json': { pages: ['a'] } }
const fragmentCtx = Object.assign(jest.fn((key) => FRAGMENTS[key]), {
	keys: () => Object.keys(FRAGMENTS),
})
const requireContext = jest.fn(() => fragmentCtx)

/**
 * Run the entry once in a fresh module registry and hand back the fakes it used.
 *
 * @return {Promise<object>} The fakes.
 */
async function boot() {
	let mods
	jest.isolateModules(() => {
		require('./main.js')
		mods = {
			lib: require('@conduction/nextcloud-vue'),
			vue: require('vue'),
			router: require('vue-router'),
			bootstrap: require('./integrations/bootstrap.js'),
			l10n: require('./services/libraryTranslations.js'),
		}
	})
	// Let the lazily imported integration components resolve.
	for (let i = 0; i < 10; i++) await Promise.resolve()
	await new Promise((resolve) => setTimeout(resolve, 0))
	mods.app = mods.vue.createApp.mock.results[0].value
	mods.routerOpts = mods.router.createRouter.mock.calls[0][0]
	return mods
}

/**
 * A fake integration registry.
 *
 * @param {Array<string>} present Descriptor ids already registered.
 * @return {object}
 */
function fakeRegistry(present = []) {
	return {
		register: jest.fn(),
		has: jest.fn((id) => present.includes(id)),
	}
}

describe('main entry', () => {
	let warnSpy
	let errorSpy

	beforeAll(() => {
		Object.defineProperty(RUNTIME_FUNCTION_PROTO, 'context', { value: requireContext, configurable: true, writable: true })
	})

	afterAll(() => {
		delete RUNTIME_FUNCTION_PROTO.context
	})

	beforeEach(() => {
		jest.clearAllMocks()
		delete window.OCA
		delete global.OC
		window.history.pushState({}, '', '/')
		warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {})
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		warnSpy.mockRestore()
		errorSpy.mockRestore()
	})

	describe('bootstrap', () => {
		it('registers translations, the integration registry, icons and dashboard widgets', async () => {
			const { lib, bootstrap, l10n, app } = await boot()
			expect(l10n.registerLibraryTranslations).toHaveBeenCalledTimes(1)
			expect(bootstrap.ensureIntegrationRegistry).toHaveBeenCalledTimes(1)
			expect(lib.registerIcons).toHaveBeenCalledWith({ Database: 'icon' })
			expect(lib.registerBuiltinDashboardWidgets).toHaveBeenCalledTimes(1)
			expect(l10n.registerLibraryTranslations.mock.invocationCallOrder[0])
				.toBeLessThan(bootstrap.ensureIntegrationRegistry.mock.invocationCallOrder[0])
			expect(app.mount.mock.invocationCallOrder[0])
				.toBeGreaterThan(lib.registerBuiltinDashboardWidgets.mock.invocationCallOrder[0])
		})

		it('merges the manifest fragments in sorted key order with the menu layout', async () => {
			const { lib } = await boot()
			expect(requireContext).toHaveBeenCalledWith('./manifest.d/', false, /\.json$/)
			expect(lib.buildManifest).toHaveBeenCalledWith(
				{ pages: [], menu: [], version: 'base' },
				[{ pages: ['a'] }, { pages: ['b'] }],
				{ relocations: [] },
			)
		})

		it('installs t/n, pinia and the router, and mounts on #openregister', async () => {
			const { app, router } = await boot()
			expect(app.mixin).toHaveBeenCalledWith({
				methods: { t: expect.any(Function), n: expect.any(Function) },
			})
			expect(app.use).toHaveBeenNthCalledWith(1, { pinia: true })
			expect(app.use).toHaveBeenNthCalledWith(2, router.createRouter.mock.results[0].value)
			expect(app.mount).toHaveBeenCalledWith('#openregister')
		})

		it('renders App with the merged manifest and extensible copies of the registries', async () => {
			const { vue, lib } = await boot()
			const rendered = vue.createApp.mock.calls[0][0].render()
			expect(rendered.component).toEqual({ name: 'App' })
			expect(rendered.props.manifest).toBe(lib.buildManifest.mock.results[0].value)
			expect(rendered.props.registry).toEqual({ Dashboard: { kind: 'page' } })
			expect(rendered.props.customComponents).toEqual({ AddIntegration: 'x' })
			expect(rendered.props.pageTypes).toEqual({ index: 'IndexType' })
			expect(Object.isExtensible(rendered.props.pageTypes)).toBe(true)
		})
	})

	describe('routes', () => {
		it('maps every manifest page to the page renderer, with props only on parameterised routes', async () => {
			const { routerOpts } = await boot()
			const [dashboard, detail] = routerOpts.routes
			expect(dashboard).toEqual({ name: 'dashboard', path: '/', component: { name: 'CnPageRenderer' }, props: false })
			expect(detail).toEqual({ name: 'register-detail', path: '/registers/:id', component: { name: 'CnPageRenderer' }, props: true })
		})

		it('adds the catch-all redirect and the three native-entity deep links', async () => {
			const { routerOpts } = await boot()
			const byPath = Object.fromEntries(routerOpts.routes.map((r) => [r.path, r]))
			expect(byPath['/:pathMatch(.*)*']).toEqual({ path: '/:pathMatch(.*)*', redirect: '/' })
			expect(byPath['/flow-tasks/:uuid']).toMatchObject({ name: 'flow-task-detail', props: true })
			expect(byPath['/flow-runs/:uuid']).toMatchObject({ name: 'flow-run-detail', props: true })
			expect(byPath['/flows/:id/overview']).toMatchObject({ name: 'flow-overview', props: true })
			expect(byPath['/flows/:id/progress']).toMatchObject({ name: 'flow-progress', props: true })
			expect(routerOpts.routes).toHaveLength(7)
		})

		it('lazily loads each deep-link view', async () => {
			const { routerOpts } = await boot()
			const load = (name) => routerOpts.routes.find((r) => r.name === name).component()
			await expect(load('flow-task-detail')).resolves.toMatchObject({ default: { name: 'FlowTaskDetail' } })
			await expect(load('flow-run-detail')).resolves.toMatchObject({ default: { name: 'FlowRunDetail' } })
			await expect(load('flow-overview')).resolves.toMatchObject({ default: { name: 'FlowOverview' } })
		})

		it('bases history on the app path found in the page URL', async () => {
			window.history.pushState({}, '', '/index.php/apps/openregister/registers/5')
			const { router } = await boot()
			expect(router.createWebHistory).toHaveBeenCalledWith('/index.php/apps/openregister')
		})

		it('falls back to generateUrl when the page URL has no app path', async () => {
			window.history.pushState({}, '', '/somewhere/else')
			const { router } = await boot()
			expect(router.createWebHistory).toHaveBeenCalledWith('/index.php/apps/openregister')
			window.history.pushState({}, '', '/custom/apps/openregister')
			const again = await boot()
			expect(again.router.createWebHistory).toHaveBeenCalledWith('/custom/apps/openregister')
		})
	})

	describe('generic integration descriptors', () => {
		it('registers nothing when the page has no integration registry', async () => {
			const { lib } = await boot()
			expect(lib.registerIcons).toHaveBeenCalled()
			expect(errorSpy).not.toHaveBeenCalled()
		})

		it('registers nothing when the registry has no register function', async () => {
			window.OCA = { OpenRegister: { integrations: { has: jest.fn() } } }
			await boot()
			expect(window.OCA.OpenRegister.integrations.has).not.toHaveBeenCalled()
		})

		it('registers only the missing descriptors, as external integrations of the connector app', async () => {
			const registry = fakeRegistry(['kvk'])
			window.OCA = { OpenRegister: { integrations: registry } }
			global.OC = { appswebroots: { openconnector: '/apps/openconnector' } }
			await boot()
			expect(registry.register.mock.calls.map(([d]) => d.id)).toEqual(['xwiki', 'opencorporates'])
			expect(registry.register).toHaveBeenCalledWith({
				id: 'xwiki',
				label: 'Articles',
				icon: 'FileDocumentMultiple',
				order: 31,
				referenceType: 'xwiki',
				requiredApp: 'openconnector',
				group: 'external',
				tab: { name: 'CnIntegrationTab' },
				widget: { name: 'CnIntegrationCard' },
				defaultSize: { w: 4, h: 3 },
			})
		})

		it('prefers the integriq spelling when both connector apps are installed', async () => {
			const registry = fakeRegistry()
			window.OCA = { OpenRegister: { integrations: registry } }
			global.OC = { appswebroots: { openconnector: '/a', integriq: '/b' } }
			await boot()
			expect(registry.register).toHaveBeenCalledTimes(3)
			registry.register.mock.calls.forEach(([d]) => expect(d.requiredApp).toBe('integriq'))
		})

		it('defaults to integriq when no connector app is installed or OC is absent', async () => {
			const registry = fakeRegistry()
			window.OCA = { OpenRegister: { integrations: registry } }
			global.OC = { appswebroots: { files: '/f' } }
			await boot()
			expect(registry.register.mock.calls[0][0].requiredApp).toBe('integriq')

			delete global.OC
			const second = fakeRegistry()
			window.OCA = { OpenRegister: { integrations: second } }
			await boot()
			expect(second.register.mock.calls[0][0].requiredApp).toBe('integriq')
		})

		it('warns and defaults to integriq when reading the webroots throws', async () => {
			const registry = fakeRegistry()
			window.OCA = { OpenRegister: { integrations: registry } }
			const broken = new Error('no webroots')
			global.OC = {}
			Object.defineProperty(global.OC, 'appswebroots', { get: () => { throw broken } })
			await boot()
			expect(warnSpy).toHaveBeenCalledWith('[main] could not resolve the connector app id', broken)
			expect(registry.register.mock.calls[0][0].requiredApp).toBe('integriq')
		})

		it('registers nothing when every descriptor is already present', async () => {
			const registry = fakeRegistry(['xwiki', 'kvk', 'opencorporates'])
			window.OCA = { OpenRegister: { integrations: registry } }
			await boot()
			expect(registry.has).toHaveBeenCalledTimes(3)
			expect(registry.register).not.toHaveBeenCalled()
		})

		it('logs, without breaking the boot, when registering a descriptor fails', async () => {
			const registry = fakeRegistry()
			const failure = new Error('duplicate id')
			registry.register.mockImplementation(() => { throw failure })
			window.OCA = { OpenRegister: { integrations: registry } }
			const { app } = await boot()
			expect(errorSpy).toHaveBeenCalledWith('[main] failed to register generic integration descriptors', failure)
			expect(app.mount).toHaveBeenCalledWith('#openregister')
		})

		it('logs, without breaking the boot, when reading the registry throws', async () => {
			const failure = new Error('hostile OCA')
			window.OCA = {}
			Object.defineProperty(window.OCA, 'OpenRegister', { get: () => { throw failure }, configurable: true })
			const { app } = await boot()
			expect(errorSpy).toHaveBeenCalledWith('[main] integration registry guard failed', failure)
			expect(app.mount).toHaveBeenCalledWith('#openregister')
		})
	})
})
