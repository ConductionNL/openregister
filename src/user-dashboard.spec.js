/**
 * The user-dashboard entry: it registers translations and integrations, then
 * mounts the integration grid into the Nextcloud dashboard tile once, polling
 * for a tile that renders late.
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
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		CnIntegrationWidgetGrid: {
			name: 'CnIntegrationWidgetGrid',
			props: { surface: String },
			render() {
				return h('div', { class: 'grid', 'data-surface': this.surface }, this.t('openregister', 'Integrations'))
			},
		},
	}
})

/**
 * Load the entry afresh.
 *
 * @return {object} The mocked bootstrap modules.
 */
function load() {
	let mods
	jest.isolateModules(() => {
		require('./user-dashboard.js')
		mods = {
			boot: require('./integrations/bootstrap.js'),
			l10n: require('./services/libraryTranslations.js'),
		}
	})
	return mods
}

describe('user-dashboard entry', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		jest.useFakeTimers()
	})
	afterEach(() => jest.useRealTimers())

	it('registers translations and integrations and mounts the grid into the tile', () => {
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		const { boot, l10n } = load()
		expect(l10n.registerLibraryTranslations).toHaveBeenCalledTimes(1)
		expect(boot.ensureIntegrationRegistry).toHaveBeenCalledTimes(1)
		const tile = document.getElementById('openregister-integrations')
		expect(tile.dataset.openregisterMounted).toBe('1')
		const grid = tile.querySelector('.grid')
		expect(grid.dataset.surface).toBe('user-dashboard')
		expect(grid.textContent).toBe('t:Integrations')
		jest.advanceTimersByTime(5000)
		expect(tile.querySelectorAll('.grid')).toHaveLength(1)
	})

	it('does not mount twice into an already mounted tile', () => {
		document.body.innerHTML = '<div id="openregister-integrations" data-openregister-mounted="1"></div>'
		load()
		expect(document.querySelectorAll('.grid')).toHaveLength(0)
	})

	it('polls for a tile that appears late and mounts it once', () => {
		load()
		jest.advanceTimersByTime(300)
		expect(document.querySelectorAll('.grid')).toHaveLength(0)
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		jest.advanceTimersByTime(100)
		expect(document.querySelectorAll('.grid')).toHaveLength(1)
		jest.advanceTimersByTime(3000)
		expect(document.querySelectorAll('.grid')).toHaveLength(1)
	})

	it('stops polling after thirty attempts', () => {
		const clearSpy = jest.spyOn(window, 'clearInterval')
		load()
		jest.advanceTimersByTime(2900)
		expect(clearSpy).not.toHaveBeenCalled()
		jest.advanceTimersByTime(100)
		expect(clearSpy).toHaveBeenCalledTimes(1)
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		jest.advanceTimersByTime(1000)
		expect(document.querySelectorAll('.grid')).toHaveLength(0)
		clearSpy.mockRestore()
	})

	it('also tries once the document has loaded', () => {
		const state = jest.spyOn(document, 'readyState', 'get').mockReturnValue('loading')
		load()
		document.body.innerHTML = '<div id="openregister-integrations"></div>'
		document.dispatchEvent(new Event('DOMContentLoaded'))
		expect(document.querySelectorAll('.grid')).toHaveLength(1)
		state.mockRestore()
	})
})
