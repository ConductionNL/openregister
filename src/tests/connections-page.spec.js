/**
 * The Connections page, its declaration, and the formatters it renders with.
 *
 * Everything asserted here fails SILENTLY in the browser. A menu entry without
 * its `query` lists every app's rows as though they were OpenRegister's; a
 * header action naming a handler nobody passes to CnAppRoot does nothing when
 * clicked; a formatter name nobody registers renders the raw enum; a page
 * without `requiresApp` renders an empty table where it should say integriq is
 * missing. The page exists to stop that class of quiet untruth.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import { BUILT_IN_FORMATTERS } from '@conduction/nextcloud-vue/src/utils/builtInFormatters.js'

// The built-ins reach @nextcloud/auth through the library's filter tokens, and
// the browser-storage stub this suite uses cannot build its guest store.
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: () => null }))
import fs from 'fs'
import path from 'path'
import customComponents, {
	INTEGRIQ_CONNECTIONS_PATH,
	openIntegriqConnections,
} from '../customComponents.js'

const ROOT = path.resolve(__dirname, '..', '..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const menuLayout = readJson('src', 'menu-layout.json')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations
const appVue = read('src', 'App.vue')
const mainJs = read('src', 'main.js')

const page = manifest.pages.find((p) => p.id === 'connections')
const integrationGroup = manifest.menu.find((m) => m.id === 'IntegrationGroup')
const menuEntry = (integrationGroup.children || []).find(
	(m) => m.id === 'Connections',
)

describe('the Connections page', () => {
	it("is an admin index page over integriq's app_connection schema", () => {
		expect(page).toBeDefined()
		expect(page.type).toBe('index')
		expect(page.route).toBe('/settings/connections')
		expect(page.title).toBe('Connections')
		expect(page.permission).toBe('admin')
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
	})

	it('names Integriq as the app it needs', () => {
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
	})

	it('leaves the ADR-019 Integrations page as it was', () => {
		const integrations = manifest.pages.find((p) => p.id === 'integrationsView')
		expect(integrations.component).toBe('IntegrationsView')
		expect(integrations.route).toBe('/integrations/:register/:schema/:objectId')
	})

	it('renders the status and settings columns through the contract formatters', () => {
		const byKey = Object.fromEntries(page.config.columns.map((c) => [c.key, c]))
		expect(Object.keys(byKey)).toEqual([
			'title',
			'status',
			'statusMessage',
			'checkedAt',
			'settingsUrl',
		])
		expect(byKey.checkedAt.label).toBe('Last checked')
		expect(byKey.status.formatter).toBe('connectionStatus')
		expect(byKey.settingsUrl.formatter).toBe('connectionSettingsLabel')
		expect(byKey.settingsUrl.widget).toBe('link')
		expect(byKey.settingsUrl.widgetProps.href).toBe('{settingsUrl}')
	})

	it('sorts on the declared order and groups on status', () => {
		expect(page.config.defaultSort).toEqual({ field: 'order', direction: 'asc' })
		expect(page.config.folderSidebar.source).toBe('field')
		expect(page.config.folderSidebar.field).toBe('status')
	})

	it('offers no generic Add button, and sends Add integration to integriq', () => {
		expect(page.config.showAdd).toBe(false)
		const add = page.config.headerActions.find((a) => a.id === 'add-integration')
		expect(add.label).toBe('Add integration')
		expect(add.handler).toBe('openIntegriqConnections')
		expect(customComponents.openIntegriqConnections).toBe(
			openIntegriqConnections,
		)
		expect(INTEGRIQ_CONNECTIONS_PATH).toBe(
			'/apps/integriq/connections?app=openregister&link=1',
		)
	})

	it('reaches CnAppRoot with its handler', () => {
		expect(appVue).toMatch(/:customComponents="customComponents"/)
		expect(mainJs).toMatch(/customComponents: customComponentsProp/)
	})
})

describe('the Connections menu entry', () => {
	it("presets the list to OpenRegister's own rows", () => {
		expect(menuEntry.route).toBe('connections')
		expect(menuEntry.query).toEqual({ app: 'openregister' })
	})

	it('only renders when integriq is installed', () => {
		expect(menuEntry.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('sits in the settings foldout, so the main menu does not grow (ADR-097)', () => {
		expect(menuLayout.settingsSection).toContain('Connections')
		const mainTopLevel = manifest.menu.filter(
			(m) => (m.section ?? 'main') === 'main',
		)
		expect(mainTopLevel.map((m) => m.id)).not.toContain('Connections')
	})
})

/**
 * The formatter registry the page renders with, built the way CnAppRoot builds
 * it: `{ ...BUILT_IN_FORMATTERS, ...formatters }`. A local map that main.js
 * passes under a built-in's name wins, so a copy that predates a status shows
 * that status as its raw word.
 *
 * @return {Object<string, Function>} Formatter name to formatter.
 */
function pageFormatters() {
	const local = /\bformatters: \w+/.test(mainJs)
		? require(path.join(ROOT, 'src', 'services', 'connectionFormatters.js'))
				.default
		: {}
	return { ...BUILT_IN_FORMATTERS, ...local }
}

describe('the connection formatters', () => {
	it('label a switched-off connection through the nextcloud-vue built-in', () => {
		expect(pageFormatters().connectionStatus('disabled')).toBe('Switched off')
		// CnAppRoot lets an app formatter win over a built-in, so a local copy
		// passed to the shell would shadow the library's labels.
		expect(appVue).not.toMatch(/:formatters=/)
	})

	it('resolve every formatter the page names', () => {
		const formatters = pageFormatters()
		for (const column of page.config.columns.filter((c) => c.formatter)) {
			expect(typeof formatters[column.formatter]).toBe('function')
		}
	})

	it('leave the page its own labels in Dutch', () => {
		for (const source of ['Add integration', 'Connections', 'Last checked']) {
			expect(en[source]).toBe(source)
			expect(nl[source]).toBeTruthy()
			expect(nl[source]).not.toBe(source)
		}
	})
})
