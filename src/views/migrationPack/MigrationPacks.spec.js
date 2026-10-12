/**
 * The migration-pack screens: where they live in the manifest, who sees the
 * menu entry, and what the list, the editor and the sample-row test send.
 *
 * Exercises the options objects against a synthetic `this`, as the other view
 * specs here do (the installed test-utils is the Vue 3 build).
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import { passesContextPredicates } from '@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import KeyValueRows from '../../components/migrationPack/KeyValueRows.vue'
import PackPreviewPanel from '../../components/migrationPack/PackPreviewPanel.vue'
import TransformDialog from '../../components/migrationPack/TransformDialog.vue'
import MainMenu from '../../navigation/MainMenu.vue'
import MigrationPackDetail from './MigrationPackDetail.vue'
import MigrationPacksIndex from './MigrationPacksIndex.vue'
import {
	canEditPacks,
	explainError,
	exportUrl,
	updatePack,
} from '../../services/migrationPacks.js'
import { draftFromStored, newTransform } from './packDraft.js'

let mockCurrentUser = { uid: 'admin', isAdmin: true }

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((p) => p),
}))
jest.mock('@nextcloud/auth', () => ({
	__esModule: true,
	getCurrentUser: () => mockCurrentUser,
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnAppNav: { name: 'CnAppNav', render: () => null },
}))
jest.mock('vue-codemirror6', () => ({
	__esModule: true,
	default: { name: 'CodeMirror', render: () => null },
}))
jest.mock('@codemirror/lang-json', () => ({
	__esModule: true,
	json: () => ({}),
	jsonParseLinter: () => () => [],
}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	organisationStore: {},
}))
jest.mock('../../services/getTheme.js', () => ({
	__esModule: true,
	getTheme: () => 'light',
}))
// vue-material-design-icons ships ESM .vue files this repo's jest transform
// chain does not compile; stub each icon the screens import.
jest.mock(
	'vue-material-design-icons/ArrowDown.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ArrowLeft.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ArrowUp.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ContentSave.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Download.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Eye.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Finance.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Pencil.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Plus.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Upload.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)

const ROOT = path.resolve(__dirname, '..', '..', '..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}
const manifest = readJson('src', 'manifest.json')
const menuLayout = readJson('src', 'menu-layout.json')
const MODULE_PACK = readJson(
	'tests',
	'fixtures',
	'migration-packs',
	'stackiq',
	'topdesk-module.json',
)

/**
 * A synthetic component instance: data, methods bound to it, computed as getters.
 *
 * @param {object} component The component options.
 * @param {object} [extra] Props and globals ($route, $emit, ...).
 * @return {object} The instance.
 */
function instance(component, extra = {}) {
	const vm = { ...extra }
	Object.assign(vm, component.data ? component.data.call(vm) : {})
	for (const [name, method] of Object.entries(component.methods || {})) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(vm, name, { get: getter.bind(vm), configurable: true })
	}
	return vm
}

/**
 * A stored pack as the API returns it.
 *
 * @param {object} [overrides] Fields to change.
 * @return {object} The pack.
 */
function storedPack(overrides = {}) {
	return {
		id: 12,
		packSlug: MODULE_PACK.id,
		name: MODULE_PACK.name,
		sourceFormat: 'excel',
		version: MODULE_PACK.version,
		definition: JSON.parse(JSON.stringify(MODULE_PACK)),
		builtin: true,
		owner: null,
		createdAt: '2026-10-12T10:00:00+00:00',
		updatedAt: '2026-10-12T10:00:00+00:00',
		...overrides,
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	mockCurrentUser = { uid: 'admin', isAdmin: true }
})

describe('the migration pack pages in the manifest', () => {
	it('declares a list and an editor page, each a registered custom component', () => {
		const list = manifest.pages.find((p) => p.id === 'migration-packs')
		const detail = manifest.pages.find((p) => p.id === 'migration-pack-detail')
		expect(list).toMatchObject({
			route: '/migration-packs',
			type: 'custom',
			component: 'MigrationPacksIndex',
		})
		expect(detail).toMatchObject({
			route: '/migration-packs/:id',
			type: 'custom',
			component: 'MigrationPackDetail',
		})
		// gate-69: an added custom page says why no typed archetype fits.
		expect(list._note.length).toBeGreaterThan(40)
		expect(detail._note.length).toBeGreaterThan(40)
		const registry = fs.readFileSync(
			path.join(ROOT, 'src', 'registry.js'),
			'utf8',
		)
		expect(registry).toContain('MigrationPacksIndex: page(')
		expect(registry).toContain(
			"import('./views/migrationPack/MigrationPacksIndex.vue')",
		)
		expect(registry).toContain(
			"import('./views/migrationPack/MigrationPackDetail.vue')",
		)
	})

	it('puts the menu entry next to Connections and gates it on the admin flag, not on permission', () => {
		const group = manifest.menu.find((m) => m.id === 'IntegrationGroup')
		const ids = group.children.map((c) => c.id)
		expect(ids.indexOf('MigrationPacks')).toBe(ids.indexOf('Connections') + 1)
		const entry = group.children.find((c) => c.id === 'MigrationPacks')
		expect(entry.route).toBe('migration-packs')
		expect(entry.icon).toBe('SwapHorizontal')
		expect(entry.visibleIf).toEqual({ 'user.isAdmin': true })
		expect(menuLayout.settingsSection.indexOf('MigrationPacks')).toBe(
			menuLayout.settingsSection.indexOf('Connections') + 1,
		)
		const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
		expect(icons).toMatch(/\n\tSwapHorizontal,\n/)
	})

	it('hands CnAppNav the admin flag in runtime.user, so the entry hides for anyone else', () => {
		for (const isAdmin of [true, false]) {
			const vm = instance(MainMenu)
			Object.defineProperty(vm, 'isAdmin', { value: isAdmin })
			const nav = MainMenu.computed.navManifest.call(vm)
			expect(nav.runtime.user.isAdmin).toBe(isAdmin)
			expect(nav.menu).toEqual(manifest.menu)
			// The predicate CnAppNav evaluates for visibleIf, on that runtime.
			const entry = nav.menu
				.find((m) => m.id === 'IntegrationGroup')
				.children.find((c) => c.id === 'MigrationPacks')
			expect(passesContextPredicates(entry.visibleIf, nav.runtime)).toBe(
				isAdmin,
			)
		}
	})

	it('hides the entry when no runtime block reaches CnAppNav', () => {
		expect(passesContextPredicates({ 'user.isAdmin': true }, null)).toBe(false)
	})
})

describe('who may change packs', () => {
	it('is a Nextcloud admin, and nobody when no user is known', () => {
		expect(canEditPacks()).toBe(true)
		mockCurrentUser = { uid: 'bob', isAdmin: false }
		expect(canEditPacks()).toBe(false)
		mockCurrentUser = null
		expect(canEditPacks()).toBe(false)
	})

	it('shows a non-admin the editor read-only, without save', () => {
		mockCurrentUser = { uid: 'bob', isAdmin: false }
		const vm = instance(MigrationPackDetail, {
			$route: { params: { id: '12' } },
		})
		expect(vm.isAdmin).toBe(false)
		const source = fs.readFileSync(
			path.join(__dirname, 'MigrationPackDetail.vue'),
			'utf8',
		)
		expect(source).toContain(
			'<div v-if="isAdmin" class="migration-pack__footer">',
		)
		expect(source).toContain(':readOnly="!isAdmin"')
		const list = fs.readFileSync(
			path.join(__dirname, 'MigrationPacksIndex.vue'),
			'utf8',
		)
		expect(list).toContain(
			'<div v-if="isAdmin" class="migration-packs__toolbar">',
		)
		expect(list).toMatch(
			/v-if="isAdmin"\s+variant="tertiary"\s+:aria-label="\s*t\('openregister', 'Delete/,
		)
	})
})

describe('the list', () => {
	it('loads the packs sorted by name', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					storedPack({ id: 2, name: 'Zeta' }),
					storedPack({ id: 1, name: 'Alpha' }),
				],
				total: 2,
			},
		})
		const vm = instance(MigrationPacksIndex)
		await vm.load()
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs',
		)
		expect(vm.packs.map((p) => p.name)).toEqual(['Alpha', 'Zeta'])
		expect(vm.editRoute(vm.packs[0])).toEqual({
			name: 'migration-pack-detail',
			params: { id: '1' },
		})
		expect(vm.exportHref(vm.packs[0])).toBe(
			'/apps/openregister/api/migration-packs/1/export',
		)
		expect(vm.formatDate(null)).toBe('-')
	})

	it('says so when the packs cannot be loaded', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const vm = instance(MigrationPacksIndex)
		await vm.load()
		expect(vm.error).toBe('Could not load the migration packs.')
	})

	it('deletes the pack the dialog asks about', async () => {
		axios.delete.mockResolvedValue({ status: 204 })
		const vm = instance(MigrationPacksIndex)
		vm.packs = [storedPack({ id: 3 }), storedPack({ id: 4 })]
		vm.deleting = vm.packs[0]
		await vm.confirmDelete()
		expect(axios.delete).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs/3',
		)
		expect(vm.packs.map((p) => p.id)).toEqual([4])
		expect(vm.deleting).toBeNull()
		expect(vm.notice.type).toBe('success')
	})

	it('warns that a built-in pack comes back with the app that installed it', () => {
		const list = fs.readFileSync(
			path.join(__dirname, 'MigrationPacksIndex.vue'),
			'utf8',
		)
		expect(list).toContain('<p v-if="deleting.builtin">')
		expect(list).toContain('That app installs it again, unedited')
	})

	it('imports a JSON file and shows a refusal readably', async () => {
		axios.post.mockResolvedValueOnce({ data: storedPack({ name: 'Imported' }) })
		axios.get.mockResolvedValue({ data: { results: [] } })
		const vm = instance(MigrationPacksIndex)
		const file = new File(['{}'], 'pack.json', { type: 'application/json' })
		const event = { target: { files: [file], value: 'C:\\fakepath\\pack.json' } }
		await vm.onImportFile(event)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/migration-packs/import')
		expect(body.get('file')).toBeInstanceOf(File)
		expect(event.target.value).toBe('')
		expect(vm.notice).toMatchObject({
			type: 'success',
			message: 'Imported "Imported".',
		})

		axios.post.mockRejectedValueOnce({
			response: {
				status: 422,
				data: {
					error: 'Invalid migration pack definition: "name" is required; "version" must be a semver string',
				},
			},
		})
		await vm.onImportFile({ target: { files: [file], value: '' } })
		expect(vm.notice.type).toBe('error')
		expect(vm.notice.details).toEqual([
			'"name" is required',
			'"version" must be a semver string',
		])
	})
})

describe('the editor', () => {
	/**
	 * An editor with the module pack loaded.
	 *
	 * @param {object} [pack] The stored pack.
	 * @return {Promise<object>} The instance.
	 */
	async function loadedEditor(pack = storedPack()) {
		axios.get.mockResolvedValue({ data: pack })
		const vm = instance(MigrationPackDetail, {
			$route: { params: { id: '12' } },
		})
		await vm.load()
		return vm
	}

	it('loads the pack into a draft and starts clean', async () => {
		const vm = await loadedEditor()
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs/12',
		)
		expect(vm.draft.rows).toHaveLength(MODULE_PACK.fieldMappings.length)
		expect(vm.dirty).toBe(false)
		expect(vm.slugLocked).toBe(true)
		expect(vm.targetHint(vm.draft.rows[3])).toContain('4, 5')
		expect(vm.targetHint(vm.draft.rows[0])).toBe('')
	})

	it('says a missing pack does not exist', async () => {
		axios.get.mockRejectedValue({ response: { status: 404 } })
		const vm = instance(MigrationPackDetail, {
			$route: { params: { id: '99' } },
		})
		await vm.load()
		expect(vm.loadError).toBe('This migration pack does not exist.')
	})

	it('saves with PUT {definition} on the route id and raises the patch version', async () => {
		const vm = await loadedEditor()
		vm.draft.rows[0].target = 'title'
		expect(vm.dirty).toBe(true)
		expect(vm.bumpedVersion).toBe('2.0.1')
		axios.put.mockImplementation((url, body) =>
			Promise.resolve({
				data: storedPack({
					version: body.definition.version,
					definition: body.definition,
					updatedAt: 'later',
				}),
			}),
		)
		await vm.save()
		const [url, body] = axios.put.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/migration-packs/12')
		expect(Object.keys(body)).toEqual(['definition'])
		expect(body.definition.id).toBe('stackiq-topdesk-module')
		expect(body.definition.version).toBe('2.0.1')
		expect(body.definition.fieldMappings[0].target).toBe('title')
		expect(body.definition.fieldMappings[7].transform.default).toBeNull()
		expect(vm.saved).toBe(true)
		expect(vm.dirty).toBe(false)
	})

	it('keeps a version the user set', async () => {
		const vm = await loadedEditor()
		vm.draft.version = '3.0.0'
		axios.put.mockResolvedValue({ data: storedPack() })
		await vm.save()
		expect(axios.put.mock.calls[0][1].definition.version).toBe('3.0.0')
	})

	it('refuses to save over a pack someone else saved since it was opened', async () => {
		const vm = await loadedEditor()
		vm.draft.name = 'Changed'
		axios.get.mockResolvedValue({
			data: storedPack({ updatedAt: '2026-10-12T11:00:00+00:00' }),
		})
		await vm.save()
		expect(axios.put).not.toHaveBeenCalled()
		expect(vm.saveProblem.conflict).toBe(true)
	})

	it('shows the problems instead of saving an invalid draft', async () => {
		const vm = await loadedEditor()
		vm.draft.rows[2].target = ''
		await vm.save()
		expect(axios.put).not.toHaveBeenCalled()
		expect(vm.saveProblem.details).toContain('Row 3 has no target field.')
	})

	it("shows the server's refusal of a built-in slug change", async () => {
		const vm = await loadedEditor(storedPack({ builtin: false }))
		vm.draft.slug = 'renamed'
		axios.put.mockRejectedValue({
			response: {
				status: 422,
				data: {
					message: 'The id of a built-in migration pack cannot be changed',
					error: 'builtin-slug-locked',
				},
			},
		})
		await vm.save()
		expect(vm.saveProblem).toMatchObject({
			message: 'The id of a built-in migration pack cannot be changed',
			code: 'builtin-slug-locked',
		})
	})

	it('moves, adds and removes rows and applies a transform', async () => {
		const vm = await loadedEditor()
		vm.move(4, -1)
		expect(vm.draft.rows[3].source).toBe('Roepnaam')
		vm.addRow()
		expect(vm.draft.rows).toHaveLength(MODULE_PACK.fieldMappings.length + 1)
		vm.removeRow(vm.draft.rows.length - 1)
		vm.editingRow = 0
		vm.applyTransform(newTransform('const'))
		expect(vm.draft.rows[0].transform.type).toBe('const')
		expect(vm.editingRow).toBeNull()
	})

	it('round-trips through the JSON view and refuses a new slug on a built-in pack', async () => {
		const vm = await loadedEditor()
		vm.showJson()
		expect(vm.view).toBe('json')
		expect(JSON.parse(vm.jsonText)).toEqual(MODULE_PACK)
		expect(vm.dirty).toBe(false)

		vm.jsonText = JSON.stringify({ ...MODULE_PACK, id: 'other-slug' })
		vm.showForm()
		expect(vm.view).toBe('json')
		expect(vm.jsonError).toContain('stackiq-topdesk-module')

		vm.jsonText = '{ not json'
		expect(vm.applyJson()).toBe(false)

		vm.jsonText = JSON.stringify({
			...MODULE_PACK,
			name: 'From JSON',
			'x-extra': 1,
		})
		vm.showForm()
		expect(vm.view).toBe('form')
		expect(vm.draft.name).toBe('From JSON')
		expect(vm.currentDefinition['x-extra']).toBe(1)
	})

	it('saves the JSON view as written', async () => {
		const vm = await loadedEditor(storedPack({ builtin: false }))
		vm.showJson()
		vm.jsonText = JSON.stringify({
			...MODULE_PACK,
			version: '9.9.9',
			skipRows: [2],
		})
		axios.put.mockResolvedValue({ data: storedPack() })
		await vm.save()
		expect(axios.put.mock.calls[0][1].definition).toEqual({
			...MODULE_PACK,
			version: '9.9.9',
			skipRows: [2],
		})
	})

	it('tests only a draft without problems, over its source columns', async () => {
		const vm = await loadedEditor()
		expect(vm.previewDefinition).toEqual(MODULE_PACK)
		expect(vm.previewColumns).toContain('Applicatiesoort')
		vm.draft.rows[0].source = ''
		expect(vm.previewDefinition).toBeNull()
	})
})

describe('the sample-row test', () => {
	it('posts the unsaved definition with the sample row and shows the mapped fields', async () => {
		axios.post.mockResolvedValue({
			data: {
				rows: [
					{
						rowNumber: 1,
						data: {
							name: 'Zaaksysteem',
							cloudDienstverleningsmodel: null,
						},
						errors: [],
					},
				],
			},
		})
		const vm = instance(PackPreviewPanel, {
			definition: MODULE_PACK,
			columns: ['Applicatie Naam', 'APPID'],
		})
		vm.sample = { 'Applicatie Naam': ' Zaaksysteem ' }
		await vm.run()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs/preview',
			{
				definition: MODULE_PACK,
				rows: [{ 'Applicatie Naam': ' Zaaksysteem ', APPID: '' }],
			},
		)
		expect(vm.resultFields).toEqual([
			{ name: 'name', value: '"Zaaksysteem"' },
			{ name: 'cloudDienstverleningsmodel', value: 'null' },
		])
		expect(vm.running).toBe(false)
	})

	it("shows the row's errors and an invalid pack's details", async () => {
		axios.post.mockResolvedValueOnce({
			data: {
				rows: [
					{
						rowNumber: 1,
						data: {},
						errors: [
							{
								source: 'APPID',
								target: 'externalNumber',
								message:
									'Required source field "APPID" is missing or empty',
							},
						],
					},
				],
			},
		})
		const vm = instance(PackPreviewPanel, {
			definition: MODULE_PACK,
			columns: ['APPID'],
		})
		await vm.run()
		expect(vm.result.errors[0].target).toBe('externalNumber')

		axios.post.mockRejectedValueOnce({
			response: {
				status: 422,
				data: {
					message: 'The pack definition is invalid',
					error: 'invalid-pack',
					details: { errors: ['"name" is required'] },
				},
			},
		})
		await vm.run()
		expect(vm.result).toBeNull()
		expect(vm.runError).toMatchObject({
			message: 'The pack definition is invalid',
			details: ['"name" is required'],
		})
	})

	it('drops the answer to an older request', async () => {
		let resolveFirst
		axios.post
			.mockImplementationOnce(
				() =>
					new Promise((resolve) => {
						resolveFirst = resolve
					}),
			)
			.mockResolvedValueOnce({
				data: {
					rows: [{ rowNumber: 1, data: { name: 'new' }, errors: [] }],
				},
			})
		const vm = instance(PackPreviewPanel, {
			definition: MODULE_PACK,
			columns: ['A'],
		})
		const first = vm.run()
		await vm.run()
		resolveFirst({
			data: { rows: [{ rowNumber: 1, data: { name: 'old' }, errors: [] }] },
		})
		await first
		expect(vm.result.data).toEqual({ name: 'new' })
	})

	it('runs 400 ms after the last change, and not while the pack has problems', () => {
		jest.useFakeTimers()
		try {
			axios.post.mockResolvedValue({ data: { rows: [] } })
			const vm = instance(PackPreviewPanel, {
				definition: MODULE_PACK,
				columns: ['A'],
			})
			vm.setValue('A', 'x')
			jest.advanceTimersByTime(399)
			expect(axios.post).not.toHaveBeenCalled()
			jest.advanceTimersByTime(1)
			expect(axios.post).toHaveBeenCalledTimes(1)

			const blocked = instance(PackPreviewPanel, {
				definition: null,
				columns: ['A'],
			})
			blocked.setValue('A', 'x')
			jest.advanceTimersByTime(1000)
			expect(axios.post).toHaveBeenCalledTimes(1)
		} finally {
			jest.useRealTimers()
		}
	})
})

describe('the transform dialog', () => {
	it('offers every transform type the validator accepts, and none', () => {
		const vm = instance(TransformDialog, { transform: null, rowNumber: 1 })
		expect(vm.typeOptions.map((o) => o.value)).toEqual([
			'',
			'trim',
			'date',
			'lookup',
			'bool-map',
			'concat',
			'const',
		])
	})

	it('offers three ways to treat a value that is not in the table', () => {
		const vm = instance(TransformDialog, { transform: null, rowNumber: 1 })
		expect(vm.defaultModes.map((m) => m.value)).toEqual([
			'none',
			'empty',
			'value',
		])
	})

	it('hands back a valid transform, refuses an invalid one, and none as null', () => {
		const $emit = jest.fn()
		const vm = instance(TransformDialog, {
			transform: null,
			rowNumber: 1,
			$emit,
		})
		vm.setType('lookup')
		vm.apply()
		expect($emit).not.toHaveBeenCalled()
		expect(vm.errors).toHaveLength(1)
		vm.patch({
			mapRows: [{ uid: 'a', key: 'J', text: 'Ja', json: false }],
			defaultMode: 'empty',
		})
		vm.apply()
		expect($emit).toHaveBeenCalledWith(
			'apply',
			expect.objectContaining({ type: 'lookup', defaultMode: 'empty' }),
		)
		vm.setType('')
		vm.apply()
		expect($emit).toHaveBeenLastCalledWith('apply', null)
	})

	it('edits a join and works on a copy of the transform', () => {
		const transform = draftFromStored(
			readJson(
				'tests',
				'fixtures',
				'migration-packs',
				'stackiq',
				'topdesk-usage.json',
			),
		).rows[3].transform
		const vm = instance(TransformDialog, { transform, rowNumber: 4 })
		vm.addField()
		vm.setField(2, 'Extra')
		vm.removeField(0)
		expect(vm.draft.fields).toEqual(['Applicatie Eigenaar (Afdeling)', 'Extra'])
		expect(transform.fields).toEqual([
			'Cluster',
			'Applicatie Eigenaar (Afdeling)',
		])
	})

	it('names settings it does not show and a type it does not know', () => {
		const vm = instance(TransformDialog, {
			transform: {
				...newTransform('trim'),
				type: 'reverse',
				base: { type: 'reverse', depth: 2 },
			},
			rowNumber: 1,
		})
		expect(vm.unknownType).toBe(true)
		expect(vm.unknownKeys).toEqual(['depth'])
		expect(vm.typeOptions.at(-1).value).toBe('reverse')
	})
})

describe('the key/value rows', () => {
	it('adds, changes and removes rows through update:rows', () => {
		const $emit = jest.fn()
		const rows = [{ uid: 'a', key: 'type', text: 'Supplier', json: false }]
		const vm = instance(KeyValueRows, { rows, $emit })
		vm.update(0, { text: 'Municipality' })
		expect($emit).toHaveBeenLastCalledWith('update:rows', [
			{ uid: 'a', key: 'type', text: 'Municipality', json: false },
		])
		vm.add()
		expect($emit.mock.calls.at(-1)[1]).toHaveLength(2)
		vm.remove(0)
		expect($emit).toHaveBeenLastCalledWith('update:rows', [])
	})
})

describe('the API helpers', () => {
	it('send the definition in an envelope, never as a bare body', async () => {
		axios.put.mockResolvedValue({ data: { id: 5 } })
		await updatePack(5, { id: 'slug' })
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs/5',
			{ definition: { id: 'slug' } },
		)
		expect(exportUrl(5)).toBe('/apps/openregister/api/migration-packs/5/export')
	})

	it('explain a refusal in one message with a list of details', () => {
		expect(
			explainError(
				{
					response: {
						status: 403,
						data: { error: 'Administrator privileges required' },
					},
				},
				'x',
			).message,
		).toBe('Only administrators can change migration packs.')
		expect(explainError({}, 'Fallback')).toMatchObject({
			message: 'Fallback',
			details: [],
			status: 0,
		})
		expect(
			explainError(
				{
					response: {
						status: 422,
						data: {
							message: 'Bad',
							error: 'invalid-pack',
							details: { errors: ['a', 'b'] },
						},
					},
				},
				'x',
			),
		).toEqual({
			message: 'Bad',
			details: ['a', 'b'],
			code: 'invalid-pack',
			status: 422,
		})
	})
})
