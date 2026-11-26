/**
 * The user-dashboard entry: it polls for the Nextcloud dashboard tile and,
 * only once the tile exists, loads the umbrella app, which registers
 * translations and integrations and mounts the integration grid once.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('./integrations/bootstrap.js', () => ({ ensureIntegrationRegistry: jest.fn() }))
jest.mock('./services/libraryTranslations.js', () => ({ registerLibraryTranslations: jest.fn() }))
jest.mock('@conduction/nextcloud-vue/dist/esm/library-style.js', () => ({}), { virtual: true })
jest.mock('@conduction/nextcloud-vue/dist/esm/components/CnIntegrationWidgetGrid/CnIntegrationWidgetGrid.vue.js', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		default: {
			name: 'CnIntegrationWidgetGrid',
			props: { surface: String },
			render() {
				return h('div', { class: 'grid', 'data-surface': this.surface }, this.t('openregister', 'Integrations'))
			},
		},
	}
}, { virtual: true })

/**
 * Let the dynamic import of the umbrella module settle.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve()
	}
}

/**
 * Load the entry afresh.
 *
 * @return {object} The mocked bootstrap modules.
 */
function load() {
	jest.resetModules()
	require('./user-dashboard.js')
	return {
		boot: require('./integrations/bootstrap.js'),
		l10n: require('./services/libraryTranslations.js'),
	}
}

describe('user-dashboard entry', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		jest.useFakeTimers()
	})
	afterEach(() => {
		jest.useRealTimers()
		delete window.OCA
	})

	it('registers a callback with the Nextcloud dashboard and mounts into the panel it hands over', async () => {
		const register = jest.fn()
		window.OCA = { Dashboard: { register } }
		const { boot, l10n } = load()
		expect(register).toHaveBeenCalledTimes(1)
		expect(register.mock.calls[0][0]).toBe('openregister-integrations')
		jest.advanceTimersByTime(5000)
		await flush()
		// Not in the user's layout: the dashboard never calls back, nothing loads.
		expect(l10n.registerLibraryTranslations).not.toHaveBeenCalled()
		expect(boot.ensureIntegrationRegistry).not.toHaveBeenCalled()

		const panel = document.createElement('div')
		document.body.appendChild(panel)
		register.mock.calls[0][1](panel, { widget: {} })
		await flush()
		expect(panel.dataset.openregisterMounted).toBe('1')
		expect(panel.querySelector('.grid').dataset.surface).toBe('user-dashboard')
		expect(l10n.registerLibraryTranslations).toHaveBeenCalledTimes(1)
	})

	it('registers with a dashboard API that appears after the script ran', async () => {
		const register = jest.fn()
		load()
		jest.advanceTimersByTime(200)
		window.OCA = { Dashboard: { register } }
		jest.advanceTimersByTime(100)
		expect(register).toHaveBeenCalledTimes(1)
		jest.advanceTimersByTime(3000)
		expect(register).toHaveBeenCalledTimes(1)
	})

	it('registers translations and integrations and mounts the grid into the tile', async () => {
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		const { boot, l10n } = load()
		await flush()
		expect(l10n.registerLibraryTranslations).toHaveBeenCalledTimes(1)
		expect(boot.ensureIntegrationRegistry).toHaveBeenCalledTimes(1)
		const tile = document.getElementById('openregister-integrations')
		expect(tile.dataset.openregisterMounted).toBe('1')
		const grid = tile.querySelector('.grid')
		expect(grid.dataset.surface).toBe('user-dashboard')
		expect(grid.textContent).toBe('t:Integrations')
		jest.advanceTimersByTime(5000)
		await flush()
		expect(tile.querySelectorAll('.grid')).toHaveLength(1)
	})

	it('loads nothing of the umbrella app while there is no tile', async () => {
		const { boot, l10n } = load()
		jest.advanceTimersByTime(5000)
		await flush()
		expect(l10n.registerLibraryTranslations).not.toHaveBeenCalled()
		expect(boot.ensureIntegrationRegistry).not.toHaveBeenCalled()
		expect(document.querySelectorAll('.grid')).toHaveLength(0)
	})

	it('does not mount twice into an already mounted tile', async () => {
		document.body.innerHTML = '<div id="openregister-integrations" data-openregister-mounted="1"></div>'
		load()
		await flush()
		expect(document.querySelectorAll('.grid')).toHaveLength(0)
	})

	it('polls for a tile that appears late and mounts it once', async () => {
		load()
		jest.advanceTimersByTime(300)
		await flush()
		expect(document.querySelectorAll('.grid')).toHaveLength(0)
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		jest.advanceTimersByTime(100)
		await flush()
		expect(document.querySelectorAll('.grid')).toHaveLength(1)
		jest.advanceTimersByTime(3000)
		await flush()
		expect(document.querySelectorAll('.grid')).toHaveLength(1)
	})

	it('stops polling after thirty attempts', async () => {
		const clearSpy = jest.spyOn(window, 'clearInterval')
		load()
		jest.advanceTimersByTime(2900)
		expect(clearSpy).not.toHaveBeenCalled()
		jest.advanceTimersByTime(100)
		expect(clearSpy).toHaveBeenCalledTimes(1)
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		jest.advanceTimersByTime(1000)
		await flush()
		expect(document.querySelectorAll('.grid')).toHaveLength(0)
		clearSpy.mockRestore()
	})

	it('also tries once the document has loaded', async () => {
		const state = jest.spyOn(document, 'readyState', 'get').mockReturnValue('loading')
		load()
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		document.dispatchEvent(new Event('DOMContentLoaded'))
		await flush()
		expect(document.querySelectorAll('.grid')).toHaveLength(1)
		state.mockRestore()
	})

	it('clears the mount mark when the umbrella chunk fails, so a later try can mount', async () => {
		jest.doMock('./user-dashboard/umbrella.js', () => {
			throw new Error('chunk load failed')
		})
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		load()
		await flush()
		expect(document.getElementById('openregister-integrations').dataset.openregisterMounted).toBe('')
		expect(error).toHaveBeenCalledTimes(1)
		error.mockRestore()
		jest.dontMock('./user-dashboard/umbrella.js')
	})

	it('has no static imports, so the entry never joins the shared vendor chunk', () => {
		const fs = require('fs')
		const path = require('path')
		const source = fs.readFileSync(path.join(__dirname, 'user-dashboard.js'), 'utf8')
		expect(source).not.toMatch(/^\s*import\s[^(]/m)
		expect(source).not.toMatch(/\brequire\(/)
		expect(source).toMatch(/import\(\s*\/\* webpackChunkName: "user-dashboard-umbrella" \*\/ '\.\/user-dashboard\/umbrella\.js'\)/)
	})
})
