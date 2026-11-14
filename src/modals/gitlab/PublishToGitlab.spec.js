/**
 * The GitLab publish dialog: it posts the selected configuration to the
 * GitLab publish endpoint, says what the server answered, refreshes the list,
 * and keeps the Publish button off until the repository names a project.
 *
 * @spec openspec/changes/capability-rows-verified-b/tasks.md#task-2.1
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import PublishToGitlab from './PublishToGitlab.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', template: '<button />' },
	NcDialog: { name: 'NcDialog', template: '<div />' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div />' },
	NcTextField: { name: 'NcTextField', template: '<input />' },
}))
jest.mock('../../services/requestToken.js', () => ({ withRequestToken: (init) => init }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'publishConfigurationGitLab', setModal: jest.fn() },
	configurationStore: {
		configurationItem: { id: 9, title: 'Zaken' },
		refreshConfigurationList: jest.fn().mockResolvedValue(undefined),
	},
}))

const { navigationStore, configurationStore } = jest.requireMock('../../store/store.js')

const stubs = {
	NcDialog: { name: 'NcDialog', props: ['name'], template: '<div><slot /><slot name="actions" /></div>' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], template: '<input />' },
	NcButton: { name: 'NcButton', props: ['disabled', 'variant'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: true,
}

function mountDialog() {
	return mount(PublishToGitlab, { global: { stubs } })
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	global.fetch = jest.fn()
	navigationStore.setModal.mockClear()
	configurationStore.refreshConfigurationList.mockClear()
})

describe('PublishToGitlab', () => {
	it('names itself after GitLab and keeps Publish off until the repository names a project', async () => {
		const wrapper = mountDialog()
		expect(wrapper.findComponent({ name: 'NcDialog' }).props('name')).toBe('t:Publish Configuration to GitLab')
		expect(wrapper.vm.canPublish).toBe(false)

		await wrapper.setData({ repository: 'conduction' })
		expect(wrapper.vm.canPublish).toBe(false)

		await wrapper.setData({ repository: 'conduction/config' })
		expect(wrapper.vm.canPublish).toBe(true)
	})

	it('posts the configuration to GitLab, shows the file and refreshes the list', async () => {
		global.fetch.mockResolvedValue({
			ok: true,
			json: async () => ({ success: true, message: 'Configuration published successfully to GitLab', file_url: 'https://gitlab.example/conduction/config/-/blob/main/zaken.json' }),
		})
		const wrapper = mountDialog()
		await wrapper.setData({ repository: 'conduction/config', filePath: 'zaken.json' })

		await wrapper.vm.publish()
		await flush()

		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/configurations/9/publish/gitlab',
			expect.objectContaining({ method: 'POST' }),
		)
		expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({ namespace: 'conduction', project: 'config', branch: 'main', path: 'zaken.json' })
		expect(wrapper.vm.successMessage).toContain('/-/blob/main/zaken.json')
		expect(wrapper.vm.error).toBeNull()
		expect(configurationStore.refreshConfigurationList).toHaveBeenCalled()
	})

	it('shows the server\'s refusal and refreshes nothing', async () => {
		global.fetch.mockResolvedValue({ ok: false, json: async () => ({ error: 'Only local configurations can be published' }) })
		const wrapper = mountDialog()
		await wrapper.setData({ repository: 'conduction/config' })

		await wrapper.vm.publish()

		expect(wrapper.vm.error).toBe('Only local configurations can be published')
		expect(wrapper.vm.successMessage).toBe('')
		expect(configurationStore.refreshConfigurationList).not.toHaveBeenCalled()
	})

	it('closes through the navigation store, but not while publishing', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ loading: true })
		wrapper.vm.closeModal()
		expect(navigationStore.setModal).not.toHaveBeenCalled()

		await wrapper.setData({ loading: false })
		wrapper.vm.closeModal()
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
	})
})
