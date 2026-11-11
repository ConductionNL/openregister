/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The configuration card: imported, local, external and discovered
 * configurations, the actions each offers and where those actions go.
 *
 * @spec exclude Unit test of the client-side configuration card.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ConfigurationCard from './ConfigurationCard.vue'
import { configurationStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [], setConfigurationItem: jest.fn() },
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => ({
	NcActions: { name: 'NcActions', render: () => null },
	NcActionButton: { name: 'NcActionButton', render: () => null },
}))
jest.mock('../shared/ActionMenu.vue', () => ({
	name: 'ActionMenu',
	props: ['actions', 'primary'],
	render: () => null,
}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ name: 'Eye', render: () => null }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ name: 'Pencil', render: () => null }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ name: 'Refresh', render: () => null }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ name: 'TrashCanOutline', render: () => null }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({ name: 'AlertCircle', render: () => null }))
jest.mock('vue-material-design-icons/ApplicationCog.vue', () => ({ name: 'ApplicationCog', render: () => null }))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({ name: 'CheckCircle', render: () => null }))
jest.mock('vue-material-design-icons/ClockOutline.vue', () => ({ name: 'ClockOutline', render: () => null }))
jest.mock('vue-material-design-icons/Cloud.vue', () => ({ name: 'Cloud', render: () => null }))
jest.mock('vue-material-design-icons/CloudUpload.vue', () => ({ name: 'CloudUpload', render: () => null }))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({ name: 'CloudUploadOutline', render: () => null }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ name: 'CogOutline', render: () => null }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ name: 'Download', render: () => null }))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({ name: 'EyeOutline', render: () => null }))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({ name: 'Magnify', render: () => null }))
jest.mock('vue-material-design-icons/OfficeBuilding.vue', () => ({ name: 'OfficeBuilding', render: () => null }))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({ name: 'OpenInNew', render: () => null }))
jest.mock('vue-material-design-icons/SourceBranch.vue', () => ({ name: 'SourceBranch', render: () => null }))
jest.mock('vue-material-design-icons/Star.vue', () => ({ name: 'Star', render: () => null }))
jest.mock('vue-material-design-icons/Sync.vue', () => ({ name: 'Sync', render: () => null }))
jest.mock('vue-material-design-icons/Tag.vue', () => ({ name: 'Tag', render: () => null }))
jest.mock('vue-material-design-icons/Update.vue', () => ({ name: 'Update', render: () => null }))
/**
 * Mount a card.
 *
 * @param {object} configuration The configuration
 * @return {object} The wrapper
 */
function mountCard(configuration) {
	return mount(ConfigurationCard, {
		props: { configuration },
		global: { mocks: { t: (app, text) => text } },
	})
}

/**
 * The visible actions of the card, by label.
 *
 * @param {object} wrapper The wrapper
 * @return {object} label -> action
 */
function actions(wrapper) {
	const list = wrapper.findComponent({ name: 'ActionMenu' }).props('actions')
	return Object.fromEntries(list.filter((a) => !a.hidden).map((a) => [a.label, a]))
}

const badges = (wrapper) => wrapper.findAll('.configBadge').map((b) => b.text())
const meta = (wrapper) => wrapper.findAll('.metaItem').map((m) => m.text())

const DISCOVERED = {
	config: { app: 'pipelinq', title: 'Pipelinq', description: 'Leads', version: '1.0.0' },
	repository: 'conduction/pipelinq',
	path: 'lib/config.json',
	organization: { name: 'Conduction', url: 'https://conduction.nl' },
	stars: 12,
	url: 'https://github.com/conduction/pipelinq/blob/main/lib/config.json',
}

beforeEach(() => {
	jest.clearAllMocks()
	configurationStore.configurationList = []
	global.fetch = jest.fn()
})

describe('ConfigurationCard imported', () => {
	it('shows a local, published configuration with an update', () => {
		const wrapper = mountCard({
			id: 1,
			title: 'Local',
			description: 'Mine',
			isLocal: 'true',
			app: 'myapp',
			githubRepo: 'me/repo',
			version: '1.0',
			remoteVersion: '1.1',
		})
		expect(wrapper.classes()).toContain('configurationCard--local')
		expect(badges(wrapper)).toEqual(['Local', 'myapp', 'Update Available', 'Published'])
		expect(meta(wrapper)).toEqual(['me/repo', 'v1.0', 'v1.1 available'])
		expect(wrapper.find('.metaLink').attributes('href')).toBe('https://github.com/me/repo')
		expect(Object.keys(actions(wrapper))).toEqual([
			'View',
			'Preview Update',
			'Edit',
			'Export',
			'Update Published',
			'Delete',
		])
	})

	it('emits each imported action with the configuration', () => {
		const config = { id: 2, title: 'Ext', sourceType: 'github', syncEnabled: true, syncStatus: 'failed', localVersion: '1', remoteVersion: '2' }
		const wrapper = mountCard(config)
		const a = actions(wrapper)
		for (const label of ['View', 'Edit', 'Export', 'Delete', 'Check Version', 'Preview Update']) {
			a[label].run()
		}
		expect(Object.keys(wrapper.emitted())).toEqual(
			expect.arrayContaining(['view', 'edit', 'export', 'delete', 'check-version', 'preview-update']),
		)
		expect(wrapper.emitted('view')[0][0]).toEqual(expect.objectContaining({ id: 2 }))
		expect(a.Publish).toBeUndefined()
	})

	it('publishes a local configuration through the store', () => {
		const wrapper = mountCard({ id: 3, title: 'L', sourceType: 'manual' })
		actions(wrapper).Publish.run()
		expect(configurationStore.setConfigurationItem).toHaveBeenCalledWith(
			expect.objectContaining({ id: 3 }),
		)
		expect(navigationStore.setModal).toHaveBeenCalledWith('publishConfiguration')
	})

	it('marks an external configuration and its sync state', () => {
		const wrapper = mountCard({
			id: 4,
			title: 'Ext',
			sourceType: 'gitlab',
			syncEnabled: true,
			syncStatus: 'success',
			githubRepo: 'grp/proj',
		})
		expect(wrapper.classes()).toContain('configurationCard--external')
		expect(badges(wrapper)[0]).toBe('External')
		expect(wrapper.find('.configBadge--sync-success').exists()).toBe(true)
		expect(wrapper.find('.metaLink').attributes('href')).toBe('https://gitlab.com/grp/proj')
	})

	it('reads the repository from a GitHub source URL', () => {
		const wrapper = mountCard({
			id: 5,
			title: 'Ext',
			sourceType: 'url',
			sourceUrl: 'https://github.com/owner/repo/blob/main/x.json',
		})
		expect(wrapper.find('.metaLink').text()).toBe('owner/repo')
	})

	it('shows the app and source type when there is no repository', () => {
		const wrapper = mountCard({ id: 6, title: 'U', app: 'a1', sourceType: 'url', sourceUrl: 'https://example.org/c.json' })
		expect(meta(wrapper)).toEqual(['a1', expect.any(String)])
		expect(wrapper.find('.metaLink').exists()).toBe(false)
	})

	it('drops an organisation link that is not http(s)', () => {
		const wrapper = mountCard({
			id: 7,
			title: 'X',
			sourceType: 'local',
			organization: { name: 'Bad', url: 'javascript:alert(1)' },
		})
		expect(wrapper.text()).not.toContain('Bad')
	})
})

describe('ConfigurationCard discovered', () => {
	it('offers import and the source when nothing is imported yet', async () => {
		global.fetch.mockResolvedValue({ ok: true, json: async () => ({ results: [] }) })
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mountCard(DISCOVERED)
		await flushPromises()
		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/configurations?app=pipelinq',
			expect.objectContaining({ method: 'GET' }),
		)
		expect(badges(wrapper)).toEqual(['pipelinq', 'Discovered'])
		expect(meta(wrapper)).toEqual(['conduction/pipelinq', 'Conduction', '12'])
		const a = actions(wrapper)
		a.Import.run()
		a['View Source'].run()
		expect(wrapper.emitted('import')[0][0]).toBe(wrapper.props('configuration'))
		expect(open).toHaveBeenCalledWith(DISCOVERED.url, '_blank')
		open.mockRestore()
	})

	it('becomes the imported configuration once the server has it', async () => {
		const imported = { id: 9, app: 'pipelinq', title: 'Pipelinq', sourceType: 'github', isLocal: false, syncEnabled: false }
		global.fetch.mockResolvedValue({ ok: true, json: async () => ({ results: [imported] }) })
		const wrapper = mountCard(DISCOVERED)
		await flushPromises()
		expect(configurationStore.configurationList).toEqual([imported])
		expect(wrapper.classes()).toContain('configurationCard--imported')
		const a = actions(wrapper)
		const routes = [
			['View', 'setModal', 'viewConfiguration'],
			['Edit', 'setModal', 'editConfiguration'],
			['Export', 'setModal', 'exportConfiguration'],
			['Delete', 'setDialog', 'deleteConfiguration'],
		]
		for (const [label, method, target] of routes) {
			a[label].run()
			expect(navigationStore[method]).toHaveBeenLastCalledWith(target)
		}
		expect(configurationStore.setConfigurationItem).toHaveBeenCalledWith(imported)
		expect(wrapper.emitted('view')).toBeUndefined()
	})

	it('previews an update of a discovered import through the store', async () => {
		configurationStore.configurationList = [
			{ id: 9, app: 'pipelinq', sourceType: 'github', localVersion: '1', remoteVersion: '2' },
		]
		global.fetch.mockResolvedValue({ ok: true, json: async () => ({ results: [{ id: 9 }] }) })
		const wrapper = mountCard(DISCOVERED)
		await flushPromises()
		expect(configurationStore.configurationList).toHaveLength(1)
		actions(wrapper)['Preview Update'].run()
		expect(navigationStore.setModal).toHaveBeenCalledWith('previewConfiguration')
	})

	it('builds the import from the discovery when the store lacks it', async () => {
		global.fetch.mockResolvedValue({ ok: true, json: async () => ({ results: [] }) })
		const wrapper = mountCard(DISCOVERED)
		await flushPromises()
		wrapper.vm.importedConfigId = 11
		configurationStore.configurationList = []
		expect(wrapper.vm.existingConfiguration).toEqual({
			id: 11,
			app: 'pipelinq',
			title: 'Pipelinq',
			description: 'Leads',
			sourceType: 'github',
			isLocal: false,
			localVersion: '1.0.0',
			remoteVersion: null,
			githubRepo: 'conduction/pipelinq',
			githubBranch: 'main',
			githubPath: 'lib/config.json',
			syncEnabled: true,
		})
	})

	it('treats a failed or refused lookup as not imported', async () => {
		global.fetch.mockRejectedValueOnce(new Error('offline'))
		const failed = mountCard(DISCOVERED)
		await flushPromises()
		expect(failed.vm.importedConfigId).toBeNull()
		global.fetch.mockResolvedValueOnce({ ok: false })
		const refused = mountCard(DISCOVERED)
		await flushPromises()
		expect(refused.vm.importedConfigId).toBeNull()
		expect(refused.vm.checkingImportStatus).toBe(false)
	})

	it('looks again when the discovered app changes, but not while looking', async () => {
		global.fetch.mockResolvedValue({ ok: true, json: async () => ({ results: [] }) })
		const wrapper = mountCard(DISCOVERED)
		await flushPromises()
		await wrapper.setProps({ configuration: { ...DISCOVERED, config: { app: 'shillinq' } } })
		await flushPromises()
		expect(global.fetch).toHaveBeenLastCalledWith(
			'/index.php/apps/openregister/api/configurations?app=shillinq',
			expect.anything(),
		)
		wrapper.vm.checkingImportStatus = true
		await wrapper.vm.checkIfImported()
		expect(global.fetch).toHaveBeenCalledTimes(2)
	})
})
