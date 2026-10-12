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
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import KeyValueRows from '../../components/migrationPack/KeyValueRows.vue'
import PackPreviewPanel from '../../components/migrationPack/PackPreviewPanel.vue'
import DeleteMigrationPackDialog from '../../dialogs/migrationPack/DeleteMigrationPackDialog.vue'
import TransformDialog from '../../dialogs/migrationPack/TransformDialog.vue'
import UnsavedChangesDialog from '../../dialogs/migrationPack/UnsavedChangesDialog.vue'
import MainMenu from '../../navigation/MainMenu.vue'
import MigrationPackDetail from './MigrationPackDetail.vue'
import MigrationPacksIndex from './MigrationPacksIndex.vue'
import {
	canEditPacks,
	explainError,
	exportUrl,
	updatePack,
} from '../../services/migrationPacks.js'
import { draftFromStored, newTransform, transformPayload } from './packDraft.js'

let mockCurrentUser = { uid: 'admin', isAdmin: true }
// The route guards the editor registers, as vue-router would hold them.
const mockRouteGuards = { leave: [], update: [] }

jest.mock('vue-router', () => ({
	__esModule: true,
	onBeforeRouteLeave: (guard) => mockRouteGuards.leave.push(guard),
	onBeforeRouteUpdate: (guard) => mockRouteGuards.update.push(guard),
}))

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
	const vm = {
		$nextTick: (fn) => Promise.resolve().then(fn),
		$refs: {},
		...extra,
	}
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

/**
 * Let queued promise callbacks ($nextTick, resolved requests) run.
 *
 * @return {Promise<void>}
 */
function flush() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
	jest.clearAllMocks()
	mockCurrentUser = { uid: 'admin', isAdmin: true }
	mockRouteGuards.leave = []
	mockRouteGuards.update = []
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
		expect(list).toContain(':pack="deleting"')
		expect(list).toContain('@confirm="confirmDelete"')
		const dialog = fs.readFileSync(
			path.join(
				ROOT,
				'src',
				'dialogs',
				'migrationPack',
				'DeleteMigrationPackDialog.vue',
			),
			'utf8',
		)
		expect(dialog).toContain('<p v-if="pack.builtin">')
		expect(dialog).toContain('That app installs it again, unedited')
	})

	it('closes the delete dialog on its close button or Escape', () => {
		const $emit = jest.fn()
		const vm = instance(DeleteMigrationPackDialog, {
			pack: storedPack(),
			$emit,
		})
		vm.onOpenChange(true)
		expect($emit).not.toHaveBeenCalled()
		vm.onOpenChange(false)
		expect($emit).toHaveBeenCalledWith('close')
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
					message: 'De packdefinitie is ongeldig',
					error: 'invalid-pack',
					details: {
						errors: [
							'"name" is required',
							'"version" must be a semver string',
						],
					},
				},
			},
		})
		await vm.onImportFile({ target: { files: [file], value: '' } })
		expect(vm.notice).toMatchObject({
			type: 'error',
			message: 'The pack is not valid.',
			details: ['"name" is required', '"version" must be a semver string'],
		})

		axios.post.mockRejectedValueOnce({
			response: {
				status: 400,
				data: { message: 'x', error: 'invalid-request' },
			},
		})
		await vm.onImportFile({ target: { files: [file], value: '' } })
		expect(vm.notice.message).toBe(
			'The file is not a migration pack. Choose a JSON file that holds one pack definition.',
		)
	})

	it('announces its notices and focuses the heading after a delete', async () => {
		axios.delete.mockResolvedValue({ status: 204 })
		const heading = document.createElement('h2')
		heading.tabIndex = -1
		document.body.appendChild(heading)
		const vm = instance(MigrationPacksIndex, { $refs: { heading } })
		vm.packs = [storedPack({ id: 3 })]
		vm.deleting = vm.packs[0]
		await vm.confirmDelete()
		await flush()
		expect(document.activeElement).toBe(heading)
		heading.remove()
		const list = fs.readFileSync(
			path.join(__dirname, 'MigrationPacksIndex.vue'),
			'utf8',
		)
		expect(list).toMatch(/<div aria-live="polite">\s*<NcNoteCard v-if="notice"/)
		expect(list).toContain('<h2 ref="heading" tabindex="-1">')
	})

	it("shows dates in the user's Nextcloud locale, not the browser's", () => {
		const vm = instance(MigrationPacksIndex)
		const previous = globalThis._nc_l10n_locale
		try {
			globalThis._nc_l10n_locale = 'nl_NL'
			const value = '2026-10-12T10:00:00+00:00'
			expect(vm.formatDate(value)).toBe(
				new Date(value).toLocaleString('nl-NL'),
			)
			globalThis._nc_l10n_locale = 'en_US'
			expect(vm.formatDate(value)).toBe(
				new Date(value).toLocaleString('en-US'),
			)
		} finally {
			globalThis._nc_l10n_locale = previous
		}
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
			message:
				'The slug of a built-in pack cannot change: the app that installed it finds the pack by this slug.',
			code: 'builtin-slug-locked',
		})
	})

	it('shows each finding of a refused save with its table row number', async () => {
		const vm = await loadedEditor(storedPack({ builtin: false }))
		vm.draft.name = 'Changed'
		axios.put.mockRejectedValue({
			response: {
				status: 422,
				data: {
					message: 'The pack definition is invalid',
					error: 'invalid-pack',
					details: {
						errors: ['Row 4: transform.map is required for lookup'],
					},
				},
			},
		})
		await vm.save()
		expect(vm.saveProblem).toMatchObject({
			message: 'The pack is not valid.',
			details: ['Row 4: transform.map is required for lookup'],
		})
		expect(vm.dirty).toBe(true)
	})

	it('refuses to save when someone else saved within the same second', async () => {
		const vm = await loadedEditor()
		vm.draft.name = 'Changed'
		const theirs = storedPack()
		theirs.definition.name = 'Their change'
		axios.get.mockResolvedValue({ data: theirs })
		await vm.save()
		expect(axios.put).not.toHaveBeenCalled()
		expect(vm.saveProblem.conflict).toBe(true)
	})

	it('shows a conflict the server reports as one', async () => {
		const vm = await loadedEditor()
		vm.draft.name = 'Changed'
		axios.put.mockRejectedValue({
			response: { status: 409, data: { error: 'pack-conflict' } },
		})
		await vm.save()
		expect(vm.saveProblem.conflict).toBe(true)
	})

	it('keeps the edits of a pack someone deleted, and saves them as a new pack', async () => {
		const vm = await loadedEditor(storedPack({ builtin: false }))
		vm.$router = { push: jest.fn().mockResolvedValue(undefined) }
		vm.draft.name = 'Changed'
		axios.get.mockRejectedValue({
			response: { status: 404, data: { error: 'pack-not-found' } },
		})
		await vm.save()
		expect(axios.put).not.toHaveBeenCalled()
		expect(vm.packDeleted).toBe(true)
		expect(vm.saveProblem).toBeNull()
		expect(vm.dirty).toBe(true)
		expect(vm.draft.name).toBe('Changed')

		axios.post.mockImplementation((url, body) =>
			Promise.resolve({
				data: storedPack({
					id: 40,
					builtin: false,
					name: body.definition.name,
					version: body.definition.version,
					definition: body.definition,
				}),
			}),
		)
		await vm.saveAsNew()
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/migration-packs')
		expect(body.definition.name).toBe('Changed')
		expect(vm.packDeleted).toBe(false)
		expect(vm.dirty).toBe(false)
		expect(vm.$router.push).toHaveBeenCalledWith({
			name: 'migration-pack-detail',
			params: { id: '40' },
		})

		const source = fs.readFileSync(
			path.join(__dirname, 'MigrationPackDetail.vue'),
			'utf8',
		)
		expect(source).toContain(':disabled="saving || !dirty || packDeleted"')
		expect(source).toContain('@click="saveAsNew"')
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

	it('raises the patch version of a JSON view edit that left the version', async () => {
		const vm = await loadedEditor()
		vm.showJson()
		// Only the layout changed: nothing to save.
		vm.jsonText = JSON.stringify(MODULE_PACK)
		expect(vm.dirty).toBe(false)
		expect(vm.versionWillBump).toBe(false)

		vm.jsonText = JSON.stringify({ ...MODULE_PACK, name: 'From JSON' })
		expect(vm.versionWillBump).toBe(true)
		axios.put.mockResolvedValue({ data: storedPack() })
		await vm.save()
		expect(axios.put.mock.calls[0][1].definition).toEqual({
			...MODULE_PACK,
			name: 'From JSON',
			version: '2.0.1',
		})
	})

	it('shows a JSON parse error as the browser wrote it, quotes included', async () => {
		const vm = await loadedEditor()
		vm.showJson()
		vm.jsonText = "{'a': 1}"
		expect(vm.applyJson()).toBe(false)
		expect(vm.jsonError).toMatch(/^This is not valid JSON: /)
		expect(vm.jsonError).not.toMatch(/&#39;|&quot;|&amp;/)
	})

	it('keeps focus on a moved row and announces where it went', async () => {
		const vm = await loadedEditor()
		const container = document.createElement('div')
		for (const row of vm.draft.rows) {
			for (const control of ['up', 'down', 'remove']) {
				const button = document.createElement('button')
				button.dataset.rowControl = `${row.uid}-${control}`
				container.appendChild(button)
			}
		}
		const add = document.createElement('button')
		add.dataset.rowControl = 'add-row'
		container.appendChild(add)
		document.body.appendChild(container)
		vm.$el = container
		const focused = () => document.activeElement.dataset.rowControl
		try {
			const second = vm.draft.rows[1]
			vm.move(1, -1)
			await flush()
			// Now first: its Move up is disabled, so Move down holds focus.
			expect(vm.draft.rows[0]).toBe(second)
			expect(focused()).toBe(`${second.uid}-down`)
			expect(vm.announcement).toBe(
				`Row moved to position 1 of ${vm.draft.rows.length}.`,
			)

			vm.move(0, 1)
			await flush()
			expect(focused()).toBe(`${second.uid}-down`)

			const last = vm.draft.rows.length - 1
			const lastRow = vm.draft.rows[last - 1]
			vm.move(last - 1, 1)
			await flush()
			expect(focused()).toBe(`${lastRow.uid}-up`)

			vm.move(0, -1)
			await flush()
			expect(focused()).toBe(`${lastRow.uid}-up`)

			const third = vm.draft.rows[3]
			vm.removeRow(2)
			await flush()
			expect(focused()).toBe(`${third.uid}-remove`)
			expect(vm.announcement).toBe('Row 3 removed.')

			vm.draft.rows = vm.draft.rows.slice(0, 1)
			vm.removeRow(0)
			await flush()
			expect(focused()).toBe('add-row')
		} finally {
			container.remove()
		}
	})

	it('names each transform by its visible summary and links the shared-target hint', () => {
		const source = fs.readFileSync(
			path.join(__dirname, 'MigrationPackDetail.vue'),
			'utf8',
		)
		const button = source.slice(
			source.indexOf('@click="editingRow = index"'),
			source.indexOf('{{ summary(row) }}') + 20,
		)
		// No aria-label: the accessible name is the hidden prefix plus the summary.
		expect(source).not.toContain("'Transform of row {number}',")
		expect(button).toContain('<span class="hidden-visually">')
		expect(button).toContain("'Transform of row {number}:'")
		expect(source).toMatch(
			/:aria-describedby="\s*targetHint\(row\)\s*\?\s*hintId\(row\)\s*:\s*undefined\s*"/,
		)
		expect(source).toContain(':id="hintId(row)"')
		expect(source).toMatch(
			/<div class="hidden-visually" aria-live="polite">\s*\{\{ announcement \}\}/,
		)
		expect(source).toMatch(
			/<div aria-live="polite">\s*<NcNoteCard v-if="packDeleted"[\s\S]*?<NcNoteCard v-if="view === 'json' && jsonError"/,
		)
	})

	it('tests only a draft without problems, over its source columns', async () => {
		const vm = await loadedEditor()
		expect(vm.previewDefinition).toEqual(MODULE_PACK)
		expect(vm.previewColumns).toContain('Applicatiesoort')
		vm.draft.rows[0].source = ''
		expect(vm.previewDefinition).toBeNull()
	})
})

describe('leaving the editor with unsaved changes', () => {
	/**
	 * The editor mounted for real, so its setup registers the route guards.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountedEditor() {
		axios.get.mockResolvedValue({ data: storedPack() })
		const wrapper = mount(MigrationPackDetail, {
			global: {
				mocks: { $route: { params: { id: '12' } } },
				stubs: { 'router-link': true },
			},
		})
		await flush()
		return wrapper
	}

	it('asks before the router leaves, or moves to another pack, and stays on No', async () => {
		const wrapper = await mountedEditor()
		const vm = wrapper.vm
		expect(mockRouteGuards.leave).toHaveLength(1)
		expect(mockRouteGuards.update).toHaveLength(1)
		const [leave] = mockRouteGuards.leave
		const [update] = mockRouteGuards.update

		expect(leave()).toBe(true)

		vm.draft.name = 'Changed'
		const asked = leave()
		expect(asked).toBeInstanceOf(Promise)
		expect(vm.leavePrompt).not.toBeNull()
		vm.resolveLeave(false)
		await expect(asked).resolves.toBe(false)
		expect(vm.leavePrompt).toBeNull()

		const moving = update()
		vm.resolveLeave(true)
		await expect(moving).resolves.toBe(true)
		wrapper.unmount()
	})

	it('does not ask a reader, who cannot change anything', async () => {
		mockCurrentUser = { uid: 'bob', isAdmin: false }
		const wrapper = await mountedEditor()
		wrapper.vm.draft.name = 'Changed'
		expect(mockRouteGuards.leave[0]()).toBe(true)
		wrapper.unmount()
	})

	it('lets the browser ask before the tab closes, until the editor is gone', async () => {
		const wrapper = await mountedEditor()
		const unload = () => {
			const event = new Event('beforeunload', { cancelable: true })
			window.dispatchEvent(event)
			return event.defaultPrevented
		}
		expect(unload()).toBe(false)
		wrapper.vm.draft.name = 'Changed'
		expect(unload()).toBe(true)
		wrapper.unmount()
		expect(unload()).toBe(false)
	})

	it('confirms in a dialog of its own', () => {
		const source = fs.readFileSync(
			path.join(__dirname, 'MigrationPackDetail.vue'),
			'utf8',
		)
		expect(source).toMatch(
			/<UnsavedChangesDialog\s+v-if="leavePrompt"\s+@leave="resolveLeave\(true\)"\s+@stay="resolveLeave\(false\)" \/>/,
		)
		const $emit = jest.fn()
		const dialog = instance(UnsavedChangesDialog, { $emit })
		dialog.onOpenChange(true)
		expect($emit).not.toHaveBeenCalled()
		dialog.onOpenChange(false)
		expect($emit).toHaveBeenCalledWith('stay')
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

	it('sends JSON Pointer columns as nested keys', async () => {
		axios.post.mockResolvedValueOnce({ data: { rows: [] } })
		const vm = instance(PackPreviewPanel, {
			definition: MODULE_PACK,
			columns: ['/identificatie', '/omschrijving'],
		})
		vm.sample = { '/identificatie': 'Z-1', '/omschrijving': 'Test' }
		await vm.run()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs/preview',
			{
				definition: MODULE_PACK,
				rows: [{ identificatie: 'Z-1', omschrijving: 'Test' }],
			},
		)
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
			message: 'The pack is not valid.',
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

	it('drops the last result, and an answer on its way, once the pack has problems', async () => {
		let resolveRun
		axios.post.mockImplementationOnce(
			() =>
				new Promise((resolve) => {
					resolveRun = resolve
				}),
		)
		const vm = instance(PackPreviewPanel, {
			definition: MODULE_PACK,
			columns: ['A'],
		})
		vm.result = { data: { name: 'old' }, errors: [] }
		const running = vm.run()
		PackPreviewPanel.watch.definition.call(vm, null)
		expect(vm.result).toBeNull()
		expect(vm.running).toBe(false)
		resolveRun({
			data: { rows: [{ rowNumber: 1, data: { name: 'late' }, errors: [] }] },
		})
		await running
		expect(vm.result).toBeNull()

		const source = fs.readFileSync(
			path.join(
				ROOT,
				'src',
				'components',
				'migrationPack',
				'PackPreviewPanel.vue',
			),
			'utf8',
		)
		expect(source).toMatch(/blockedText\s*\|\|\s*t\(/)
		const editor = fs.readFileSync(
			path.join(__dirname, 'MigrationPackDetail.vue'),
			'utf8',
		)
		expect(editor).toContain("'Switch to the form to test the pack.'")
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

	it('says that a yes/no table stores false for a value not in its table', () => {
		const vm = instance(TransformDialog, { transform: null, rowNumber: 1 })
		vm.setType('lookup')
		expect(vm.defaultModes[1].label).toBe('Leave the field empty')
		vm.setType('bool-map')
		expect(vm.defaultModes[1]).toEqual({ value: 'empty', label: 'Store false' })
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

	it('offers only yes or no for a yes/no table, its default included', () => {
		const vm = instance(TransformDialog, { transform: null, rowNumber: 1 })
		vm.setType('bool-map')
		vm.patch({ defaultMode: 'value' })
		expect(vm.defaultChoice).toBe('')
		vm.setDefaultChoice('false')
		expect(vm.defaultChoice).toBe('false')
		expect(transformPayload(vm.draft).default).toBe(false)

		const source = fs.readFileSync(
			path.join(
				ROOT,
				'src',
				'dialogs',
				'migrationPack',
				'TransformDialog.vue',
			),
			'utf8',
		)
		expect(source).toContain(':booleanValues="draft.type === \'bool-map\'"')
		expect(source).toMatch(
			/<div aria-live="polite">\s*<NcNoteCard v-if="errors.length > 0"/,
		)
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

	it('labels each input with a translated sentence, not a joined string', () => {
		const vm = instance(KeyValueRows, { rows: [], keyLabel: 'Target field' })
		expect(vm.rowLabel('Target field', 2)).toBe('Target field of row 3')
		const source = fs.readFileSync(
			path.join(
				ROOT,
				'src',
				'components',
				'migrationPack',
				'KeyValueRows.vue',
			),
			'utf8',
		)
		expect(source).not.toMatch(/:aria-label="`/)
	})

	it('reads a yes/no value as a choice, and anything else as none', () => {
		const vm = instance(KeyValueRows, { rows: [] })
		expect(vm.booleanChoice({ text: 'true', json: true })).toBe('true')
		expect(vm.booleanChoice({ text: 'false', json: true })).toBe('false')
		expect(vm.booleanChoice({ text: 'false', json: false })).toBe('')
		expect(vm.booleanChoice({ text: '1', json: true })).toBe('')
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
			message: 'The pack is not valid.',
			details: ['a', 'b'],
			code: 'invalid-pack',
			status: 422,
		})
	})

	it('read the slug, never the wording, of an error', () => {
		const explain = (status, data, texts) =>
			explainError({ response: { status, data } }, 'Fallback', texts)
		expect(explain(404, { message: 'x', error: 'pack-not-found' }).message).toBe(
			'This migration pack does not exist.',
		)
		expect(
			explain(401, { message: 'x', error: 'not-authenticated' }).message,
		).toBe('You are not signed in. Sign in and try again.')
		expect(explain(400, { message: 'x', error: 'too-many-rows' }).message).toBe(
			'There are too many sample rows to test at once.',
		)
		// An unexpected failure: the caller says what could not be done.
		expect(
			explain(500, {
				message: 'Interner Fehler',
				error: 'migration-pack-error',
			}).message,
		).toBe('Fallback')
		// A slug this page does not know: the server's localised message.
		expect(
			explain(400, { message: 'Ongeldig verzoek', error: 'invalid-request' })
				.message,
		).toBe('Ongeldig verzoek')
		expect(
			explain(400, { error: 'invalid-request' }, { 'invalid-request': 'Own' })
				.message,
		).toBe('Own')
		// An English sentence in `error` is not a slug, and is never parsed.
		expect(
			explain(422, {
				error: 'Invalid migration pack definition: "name" is required; "x"',
			}),
		).toEqual({ message: 'Fallback', details: [], code: '', status: 422 })
	})
})
