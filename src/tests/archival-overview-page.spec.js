/**
 * The Archival page (board OrArchivering): declared, in the Administration
 * group, every widget reading the one overview endpoint, and every string it
 * shows translated.
 *
 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import fs from 'fs'
import path from 'path'

const ROOT = path.resolve(__dirname, '..', '..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const OVERVIEW = '/apps/openregister/api/archival/overview'

const fragment = readJson('src', 'manifest.d', 'archival-overview-page.json')
const menuLayout = readJson('src', 'menu-layout.json')
const nl = readJson('l10n', 'nl.json').translations
const nlJs = read('l10n', 'nl.js')
const page = fragment.pages.find((p) => p.id === 'archival')
const widgets = Object.fromEntries(page.config.widgets.map((w) => [w.id, w]))

describe('the Archival page', () => {
	it('is a dashboard at /archival with the draw-up action', () => {
		expect(page.type).toBe('dashboard')
		expect(page.route).toBe('/archival')
		const action = page.config.headerActions.find((a) => a.id === 'draw-up-destruction-list')
		expect(action).toMatchObject({ type: 'api-call', method: 'POST', variant: 'primary', url: '/apps/openregister/api/archival/destruction-lists/draw-up' })
	})

	it('sits in the Administration group with a registered icon', () => {
		const entry = fragment.menu.find((m) => m.id === 'Archival')
		expect(entry.route).toBe('archival')
		expect(menuLayout.relocations.Archival).toBe('AdministrationGroup')
		expect(read('src', 'icons.js')).toMatch(new RegExp(`import ${entry.icon} from 'vue-material-design-icons/${entry.icon}.vue'`))
	})

	it('holds the four tabs the board draws, each naming a declared widget', () => {
		const tabs = widgets['archival-tabs'].content.tabs
		expect(tabs.map((t) => t.label)).toEqual(['Destruction lists', 'Legal holds', 'Transfer to e-Depot', 'Selection list'])
		for (const tab of tabs) {
			for (const id of (tab.widgetIds || [tab.widgetId])) {
				expect(widgets[id]).toBeDefined()
			}
		}
	})

	it('reads every list and fact card from the overview endpoint', () => {
		const readers = page.config.widgets.filter((w) => w.content.endpointSource)
		expect(readers.map((w) => w.id).sort()).toEqual(['destruction-lists', 'edepot-facts', 'legal-holds', 'selection-list-facts', 'selection-list-versions', 'transfers'])
		for (const w of readers) {
			expect(w.content.endpointSource.url).toBe(OVERVIEW)
		}
		expect(widgets['held-off-lists'].content.visibleWhen.endpoint).toBe(OVERVIEW)
	})

	it('approves and rejects a list in review through the existing endpoints', () => {
		const actions = widgets['destruction-lists'].content.rowActions
		expect(actions.map((a) => a.endpoint)).toEqual([
			'/apps/openregister/api/archival/destruction-lists/{uuid}/approve',
			'/apps/openregister/api/archival/destruction-lists/{uuid}/reject',
		])
	})

	it('translates every label it shows into Dutch, in both catalogues', () => {
		const strings = [page.title, page.config.description, ...page.config.headerActions.flatMap((a) => [a.label, a.successMessage])]
		for (const w of page.config.widgets) {
			const c = w.content
			strings.push(w.title, c.emptyText, c.errorText, c.text, c.rowActionsLabel)
			for (const col of (c.columns || [])) strings.push(col.label)
			for (const a of (c.rowActions || [])) strings.push(a.label)
			for (const t of (c.tabs || [])) strings.push(t.label)
			for (const f of (c.fields || [])) strings.push(f.label)
		}
		for (const s of strings.filter((x) => typeof x === 'string')) {
			expect([s, nl[s]]).toEqual([s, expect.any(String)])
			expect(nlJs).toContain(JSON.stringify(s) + ':')
		}
		expect(nl.Archiving).toBe('Archivering')
	})
})
