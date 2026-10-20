/**
 * The migration-pack screens: where they live in the manifest, who sees the
 * menu entry, and what the list, the editor and the sample-row test send.
 *
 * Most cases drive the options objects against a synthetic `this`, as the
 * other view specs here do. What a template binds (who sees which control,
 * which dialog opens, which region is live) is checked on the mounted
 * component, with each @nextcloud/vue component stubbed as a div that renders
 * its slots, never by reading the template's source text.
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
// Each @nextcloud/vue component is a <div data-nc="Name"> that renders its
// slots and carries its bindings as attributes, so a mounted screen shows
// what its own template puts inside NcAppContent, NcDialog or NcNoteCard.
jest.mock('@nextcloud/vue', () => {
	const { h } = jest.requireActual('vue')
	const stubs = {}
	const stub = (name) => {
		stubs[name] ??= {
			name,
			render() {
				return h(
					'div',
					{ 'data-nc': name },
					Object.values(this.$slots).map((slot) => slot()),
				)
			},
		}
		return stubs[name]
	}
	return new Proxy(
		{ __esModule: true },
		{
			get: (target, prop) =>
				prop in target ? target[prop] : stub(String(prop)),
		},
	)
})

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

/**
 * The editor mounted for real on a stored pack.
 *
 * @param {object} [pack] The pack the server answers.
 * @return {Promise<object>} The wrapper.
 */
async function mountEditor(pack = storedPack()) {
	axios.get.mockResolvedValue({ data: pack })
	const wrapper = mount(MigrationPackDetail, {
		global: {
			mocks: { $route: { params: { id: '12' } } },
			stubs: { 'router-link': true },
		},
	})
	await flush()
	return wrapper
}

/**
 * The list mounted for real.
 *
 * @param {Array<object>} packs The packs the server answers.
 * @param {object} [options] Extra mount options.
 * @return {Promise<object>} The wrapper.
 */
async function mountList(packs, options = {}) {
	axios.get.mockResolvedValue({ data: { results: packs } })
	const wrapper = mount(MigrationPacksIndex, {
		global: { stubs: { 'router-link': true } },
		...options,
	})
	await flush()
	return wrapper
}

/**
 * The rendered NcButton whose text contains the given text.
 *
 * @param {object} wrapper The mounted screen.
 * @param {string} text Part of the button text.
 * @return {object|undefined} The button's component wrapper.
 */
function ncButton(wrapper, text) {
	return wrapper
		.findAllComponents({ name: 'NcButton' })
		.find((button) => button.text().includes(text))
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

	it('shows a non-admin the editor and the list read-only, without save, import or delete', async () => {
		for (const isAdmin of [true, false]) {
			mockCurrentUser = { uid: isAdmin ? 'admin' : 'bob', isAdmin }
			const note = 'Only administrators can change migration packs.'

			const editor = await mountEditor()
			expect(editor.find('.migration-pack__footer').exists()).toBe(isAdmin)
			expect(ncButton(editor, 'Save') !== undefined).toBe(isAdmin)
			expect(editor.findComponent(PackPreviewPanel).exists()).toBe(isAdmin)
			expect(editor.text().includes(note)).toBe(!isAdmin)
			const source = editor.find('input[aria-label="Source column of row 1"]')
			expect(source.element.disabled).toBe(!isAdmin)
			editor.vm.editingRow = 0
			await editor.vm.$nextTick()
			expect(editor.findComponent(TransformDialog).props('readOnly')).toBe(
				!isAdmin,
			)
			editor.unmount()

			const list = await mountList([storedPack({ id: 3 })])
			expect(list.find('.migration-packs__toolbar').exists()).toBe(isAdmin)
			expect(ncButton(list, 'Import JSON') !== undefined).toBe(isAdmin)
			expect(
				list
					.findAllComponents({ name: 'NcButton' })
					.some((button) =>
						String(button.vm.$attrs['aria-label']).startsWith('Delete '),
					),
			).toBe(isAdmin)
			expect(list.text().includes(note)).toBe(!isAdmin)
			list.unmount()
		}
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

	it('warns that a built-in pack comes back with the app that installed it', async () => {
		axios.delete.mockResolvedValue({ status: 204 })
		const list = await mountList([
			storedPack({ id: 3, name: 'Built in', builtin: true }),
			storedPack({ id: 4, name: 'Own pack', builtin: false }),
		])
		const warning = 'That app installs it again, unedited'
		const askToDelete = async (name) => {
			await list
				.findAllComponents({ name: 'NcButton' })
				.find(
					(button) => button.vm.$attrs['aria-label'] === `Delete ${name}`,
				)
				.trigger('click')
			return list.findComponent(DeleteMigrationPackDialog)
		}

		let dialog = await askToDelete('Own pack')
		expect(dialog.props('pack').id).toBe(4)
		expect(dialog.text()).not.toContain(warning)
		dialog.vm.$emit('close')
		await list.vm.$nextTick()
		expect(list.findComponent(DeleteMigrationPackDialog).exists()).toBe(false)

		dialog = await askToDelete('Built in')
		expect(dialog.props('pack').id).toBe(3)
		expect(dialog.text()).toContain(warning)
		dialog.vm.$emit('confirm')
		await flush()
		expect(axios.delete).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs/3',
		)
		list.unmount()
	})

	it('says a pack was replaced when the import overwrote a stored one', async () => {
		// The server answers 200 for a pack it replaced and 201 for one it created.
		axios.post.mockResolvedValueOnce({
			status: 200,
			data: storedPack({ name: 'Edited here' }),
		})
		axios.get.mockResolvedValue({ data: { results: [] } })
		const vm = instance(MigrationPacksIndex)
		const file = new File(['{}'], 'pack.json', { type: 'application/json' })
		await vm.onImportFile({ target: { files: [file], value: '' } })
		expect(vm.notice).toMatchObject({
			type: 'success',
			message: 'Replaced "Edited here".',
		})
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
		axios.post.mockResolvedValueOnce({
			status: 201,
			data: storedPack({ name: 'Imported' }),
		})
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
		const list = await mountList([storedPack({ id: 3 })], {
			attachTo: document.body,
		})
		const heading = list.find('h2')
		expect(heading.attributes('tabindex')).toBe('-1')
		list.vm.deleting = list.vm.packs[0]
		await list.vm.confirmDelete()
		await flush()
		expect(document.activeElement).toBe(heading.element)
		const live = list.find('[aria-live="polite"]')
		expect(live.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)
		expect(live.text()).toContain('Deleted')
		list.unmount()
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

	it('saves with PUT {definition, expectedUpdatedAt} on the route id and raises the patch version', async () => {
		const vm = await loadedEditor()
		vm.draft.rows[0].target = 'title'
		expect(vm.dirty).toBe(true)
		expect(vm.bumpedVersion).toBe('1.0.1')
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
		expect(Object.keys(body)).toEqual(['definition', 'expectedUpdatedAt'])
		expect(body.definition.id).toBe('stackiq-topdesk-module')
		expect(body.definition.version).toBe('1.0.1')
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

	it('names the version it loaded, and shows the refusal of a save that came after another', async () => {
		const vm = await loadedEditor()
		vm.draft.name = 'Changed'
		axios.put.mockRejectedValue({
			response: {
				status: 409,
				data: {
					message:
						'The migration pack was saved by someone else after it was read',
					error: 'pack-changed',
				},
			},
		})
		await vm.save()
		expect(axios.put.mock.calls[0][1].expectedUpdatedAt).toBe(
			'2026-10-12T10:00:00+00:00',
		)
		expect(vm.saveProblem.conflict).toBe(true)
		expect(vm.dirty).toBe(true)
	})

	it('names the version it saved on the next save', async () => {
		const vm = await loadedEditor(storedPack({ builtin: false }))
		vm.draft.name = 'Changed'
		axios.put.mockResolvedValue({
			data: storedPack({
				builtin: false,
				updatedAt: '2026-10-12T10:05:00+00:00',
			}),
		})
		await vm.save()
		axios.get.mockResolvedValue({
			data: storedPack({
				builtin: false,
				updatedAt: '2026-10-12T10:05:00+00:00',
			}),
		})
		vm.draft.name = 'Changed again'
		await vm.save()
		expect(axios.put.mock.calls[1][1].expectedUpdatedAt).toBe(
			'2026-10-12T10:05:00+00:00',
		)
	})

	it('does not take a failed request-token check (412) for someone else saving', async () => {
		const vm = await loadedEditor()
		vm.draft.name = 'Changed'
		axios.put.mockRejectedValue({
			response: {
				status: 412,
				data: { message: 'CSRF check failed' },
			},
		})
		await vm.save()
		expect(vm.saveProblem).toEqual({
			message: 'Could not save the migration pack.',
			details: [],
			code: '',
			status: 412,
		})
		expect(vm.dirty).toBe(true)
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
	})

	it('offers a deleted pack only Save as a new pack, not Save', async () => {
		const wrapper = await mountEditor(storedPack({ builtin: false }))
		wrapper.vm.draft.name = 'Changed'
		await wrapper.vm.$nextTick()
		expect(ncButton(wrapper, 'Save').vm.$attrs.disabled).toBe(false)
		expect(ncButton(wrapper, 'Save as a new pack')).toBeUndefined()

		wrapper.vm.packDeleted = true
		await wrapper.vm.$nextTick()
		const save = wrapper
			.findAllComponents({ name: 'NcButton' })
			.find((button) => button.text() === 'Save')
		expect(save.vm.$attrs.disabled).toBe(true)
		axios.post.mockResolvedValue({
			data: storedPack({ id: 40, builtin: false }),
		})
		wrapper.vm.$router = { push: jest.fn().mockResolvedValue(undefined) }
		await ncButton(wrapper, 'Save as a new pack').trigger('click')
		await flush()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/migration-packs',
			expect.objectContaining({
				definition: expect.objectContaining({ name: 'Changed' }),
			}),
		)
		wrapper.unmount()
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
			version: '1.0.1',
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

	it('names each transform by its visible summary and links the shared-target hint', async () => {
		const wrapper = await mountEditor()
		const transform = wrapper
			.findAllComponents({ name: 'NcButton' })
			.find((button) => button.text().startsWith('Transform of row 1:'))
		// No aria-label: the accessible name is the hidden prefix plus the summary.
		expect(transform.vm.$attrs['aria-label']).toBeUndefined()
		expect(transform.find('.hidden-visually').text()).toBe('Transform of row 1:')
		expect(transform.text()).toBe(
			`Transform of row 1: ${wrapper.vm.summary(wrapper.vm.draft.rows[0])}`,
		)

		// Rows 4 and 5 both write shortDescription; row 1 shares nothing.
		const target = (n) =>
			wrapper.find(`input[aria-label="Target field of row ${n}"]`)
		expect(target(1).attributes('aria-describedby')).toBeUndefined()
		for (const n of [4, 5]) {
			const hint = wrapper.find(`#${target(n).attributes('aria-describedby')}`)
			expect(hint.text()).toBe(
				wrapper.vm.targetHint(wrapper.vm.draft.rows[n - 1]),
			)
		}

		const [announcer, problems] = wrapper.findAll('[aria-live="polite"]')
		expect(announcer.classes()).toContain('hidden-visually')
		wrapper.vm.announcement = 'Row 2 removed.'
		wrapper.vm.packDeleted = true
		await wrapper.vm.$nextTick()
		expect(announcer.text()).toBe('Row 2 removed.')
		expect(problems.text()).toContain('Someone deleted this pack')
		wrapper.unmount()
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

	it('confirms in a dialog of its own, whose answer decides', async () => {
		const wrapper = await mountedEditor()
		const [leave] = mockRouteGuards.leave
		const prompt = () => wrapper.findComponent(UnsavedChangesDialog)
		expect(prompt().exists()).toBe(false)
		wrapper.vm.draft.name = 'Changed'

		const staying = leave()
		await wrapper.vm.$nextTick()
		prompt().vm.$emit('stay')
		await expect(staying).resolves.toBe(false)
		await wrapper.vm.$nextTick()
		expect(prompt().exists()).toBe(false)

		const leaving = leave()
		await wrapper.vm.$nextTick()
		prompt().vm.$emit('leave')
		await expect(leaving).resolves.toBe(true)
		wrapper.unmount()
	})

	it('closes its dialog as No', () => {
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
	})

	it('says why it cannot test: problems in the pack, or the JSON view', async () => {
		const panel = (props) =>
			mount(PackPreviewPanel, {
				props: { definition: null, columns: ['A'], ...props },
			})
		const problems = panel({})
		expect(problems.text()).toContain(
			'Fix the problems in the pack first; then it can be tested.',
		)
		problems.unmount()
		const blocked = panel({
			blockedText: 'Switch to the form to test the pack.',
		})
		expect(blocked.text()).toContain('Switch to the form to test the pack.')
		expect(blocked.text()).not.toContain('Fix the problems')
		blocked.unmount()

		const editor = await mountEditor()
		expect(editor.findComponent(PackPreviewPanel).props('blockedText')).toBe('')
		editor.vm.view = 'json'
		await editor.vm.$nextTick()
		expect(editor.findComponent(PackPreviewPanel).props('blockedText')).toBe(
			'Switch to the form to test the pack.',
		)
		editor.unmount()
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
	})

	it('shows a yes/no table as yes/no choices, and announces what keeps it from applying', async () => {
		const dialog = (transform) =>
			mount(TransformDialog, { props: { transform, rowNumber: 1 } })
		const lookup = dialog(newTransform('lookup'))
		expect(lookup.findComponent(KeyValueRows).props('booleanValues')).toBe(false)
		lookup.unmount()

		// The text "false" for other values: the import would store true.
		const yesNo = dialog({
			...newTransform('bool-map'),
			defaultMode: 'value',
			defaultCell: { text: 'false', json: false },
		})
		expect(yesNo.findComponent(KeyValueRows).props('booleanValues')).toBe(true)
		const live = yesNo.find('[aria-live="polite"]')
		expect(live.text()).toBe('')
		await ncButton(yesNo, 'Apply').trigger('click')
		expect(yesNo.emitted('apply')).toBeUndefined()
		expect(live.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)
		expect(live.text()).toContain(
			'Choose yes or no for values that are not in the table.',
		)
		yesNo.unmount()
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
		const rows = (booleanValues) =>
			mount(KeyValueRows, {
				props: {
					rows: [{ uid: 'a', key: 'Ja', text: 'true', json: true }],
					keyLabel: 'Source value',
					valueLabel: 'Stored value',
					addLabel: 'Add',
					booleanValues,
				},
			})
		const text = rows(false)
		expect(text.find('input').attributes('aria-label')).toBe(
			'Source value of row 1',
		)
		expect(text.findAll('input')[1].attributes('aria-label')).toBe(
			'Stored value of row 1',
		)
		text.unmount()
		const yesNo = rows(true)
		expect(yesNo.find('select').attributes('aria-label')).toBe(
			'Stored value of row 1',
		)
		expect(yesNo.find('select').element.value).toBe('true')
		yesNo.unmount()
	})

	it('reads a yes/no value as a choice, and anything else as none', () => {
		const vm = instance(KeyValueRows, { rows: [] })
		expect(vm.booleanChoice({ text: 'true', json: true })).toBe('true')
		expect(vm.booleanChoice({ text: 'false', json: true })).toBe('false')
		expect(vm.booleanChoice({ text: 'false', json: false })).toBe('')
		// 1 and 0 cast to true and false, as a pack from the API may hold them.
		expect(vm.booleanChoice({ text: '1', json: true })).toBe('true')
		expect(vm.booleanChoice({ text: '0', json: true })).toBe('false')
		expect(vm.booleanChoice({ text: '2', json: true })).toBe('')
		expect(vm.booleanChoice({ text: '1', json: false })).toBe('')
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
		await updatePack(5, { id: 'slug' }, '2026-10-12T10:00:00+00:00')
		expect(axios.put).toHaveBeenLastCalledWith(
			'/apps/openregister/api/migration-packs/5',
			{
				definition: { id: 'slug' },
				expectedUpdatedAt: '2026-10-12T10:00:00+00:00',
			},
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
		expect(
			explain(422, {
				message: 'A migration pack with this id already exists',
				error: 'pack-slug-exists',
			}),
		).toEqual({
			message: 'Another pack already has this slug. Choose a different slug.',
			details: [],
			code: 'pack-slug-exists',
			status: 422,
		})
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
