/**
 * Golden record of the app's default data shapes: the initial state of the
 * settings, quality and search trail stores, the empty statistics, the new
 * property definition, and the page registry. Each shape is recorded as JSON
 * text, so the key order is pinned too.
 *
 * @spec exclude Golden record of client-side default shapes.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { emptyPropertyDefinition } from '../modals/schema/schemaPropertyForm.js'
import registry from '../registry.js'
import { useQualityStore } from './modules/quality.js'
import { useSearchTrailStore } from './modules/searchTrail.js'
import { useSettingsStore } from './settings.js'
import { emptyCacheStats, emptySystemStats } from './settingsDefaults.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn((app, text) => text),
	t: jest.fn((app, text) => text),
}))

describe('default shapes golden', () => {
	beforeEach(() => setActivePinia(createPinia()))

	it('keeps every default shape, in key order', () => {
		const shapes = {
			settingsState: useSettingsStore().$state,
			qualityState: useQualityStore().$state,
			searchTrailState: useSearchTrailStore().$state,
			systemStats: emptySystemStats(),
			cacheStats: emptyCacheStats(),
			propertyDefinition: emptyPropertyDefinition(),
		}
		expect(
			Object.fromEntries(
				Object.entries(shapes).map(([name, shape]) => [
					name,
					JSON.stringify(shape, null, 1),
				]),
			),
		).toMatchSnapshot()
	})

	it('hands out fresh copies', () => {
		const first = emptyPropertyDefinition()
		first.items.objectConfiguration.handling = 'changed'
		first.fileConfiguration.allowedMimeTypes.push('x')
		const second = emptyPropertyDefinition()
		const stats = emptyCacheStats()
		stats.services.object.hits = 9
		stats.names.hits = 9
		const system = emptySystemStats()
		system.totals.totalObjects = 9
		expect([
			JSON.stringify(second),
			JSON.stringify(emptyCacheStats()),
			JSON.stringify(emptySystemStats()),
		]).toMatchSnapshot()
	})

	it('registers every page as an async component', () => {
		expect(
			Object.entries(registry).map(([name, entry]) => [
				name,
				Object.keys(entry),
				entry.kind,
				typeof entry.component,
				Object.keys(entry.component).sort(),
			]),
		).toMatchSnapshot()
	})
})
