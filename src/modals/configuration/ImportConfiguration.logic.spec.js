/**
 * The import modal's own logic: token presence and its warning, which
 * forms may submit, importing a discovered configuration from GitHub or
 * GitLab, and forgetting a repository choice.
 *
 * @spec exclude Unit test of client-side import modal logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ImportConfiguration from './ImportConfiguration.vue'
import { configurationStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	configurationStore: {
		importFromGitHub: jest.fn(),
		importFromGitLab: jest.fn(),
		importFromUrl: jest.fn(),
		refreshConfigurationList: jest.fn(),
		getBranches: jest.fn(),
	},
	navigationStore: { setModal: jest.fn() },
	registerStore: { refreshRegisterList: jest.fn() },
	schemaStore: { refreshSchemaList: jest.fn() },
}))
jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/FileUpload.vue', () => ({}))
jest.mock('vue-material-design-icons/Github.vue', () => ({}))
jest.mock('vue-material-design-icons/Gitlab.vue', () => ({}))
jest.mock('vue-material-design-icons/Import.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/SourceBranch.vue', () => ({}))
jest.mock('../../components/cards/ConfigurationCard.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))

const { methods, computed } = ImportConfiguration

/**
 * A bare modal context with the modal's methods bound to it.
 *
 * @param {object} extra Extra state
 * @return {object} The context
 */
function modal(extra = {}) {
	const ctx = { ...ImportConfiguration.data.call({}), ...extra }
	for (const [name, method] of Object.entries(methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
})

afterEach(() => {
	jest.useRealTimers()
})

describe('checkTokenAvailability', () => {
	it('reads which masked tokens are set', async () => {
		axios.get.mockResolvedValue({ data: { github_token: '****abcd', gitlab_token: '' } })
		const ctx = modal()

		await ctx.checkTokenAvailability()

		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/settings/api-tokens')
		expect(ctx.hasGithubToken).toBe(true)
		expect(ctx.hasGitlabToken).toBe(false)
	})

	it('assumes no tokens when the settings cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const ctx = modal({ hasGithubToken: true, hasGitlabToken: true })

		await ctx.checkTokenAvailability()

		expect(ctx.hasGithubToken).toBe(false)
		expect(ctx.hasGitlabToken).toBe(false)
	})
})

describe('token warning', () => {
	it('names the missing token, or both', () => {
		const cases = [
			[false, false, 'API Tokens Not Configured', 'Discovery requires API tokens'],
			[false, true, 'GitHub Token Not Configured', 'GitHub token is not configured'],
			[true, false, 'GitLab Token Not Configured', 'GitLab token is not configured'],
		]
		for (const [github, gitlab, title, message] of cases) {
			const ctx = modal({ hasGithubToken: github, hasGitlabToken: gitlab })
			expect(ctx.getTokenWarningTitle()).toBe(title)
			expect(ctx.getTokenWarningMessage()).toMatch(new RegExp('^' + message))
		}
	})
})

describe('form guards', () => {
	it('needs owner and name on GitHub, namespace and name on GitLab', () => {
		expect(computed.canFetchBranches.call({ repoSource: 'GitHub', repoOwner: 'o', repoName: 'r' })).toBeTruthy()
		expect(computed.canFetchBranches.call({ repoSource: 'GitHub', repoOwner: '', repoName: 'r', repoNamespace: 'ns' })).toBeFalsy()
		expect(computed.canFetchBranches.call({ repoSource: 'GitLab', repoNamespace: 'ns', repoName: 'r' })).toBeTruthy()
		expect(computed.canFetchBranches.call({ repoSource: 'GitLab', repoOwner: 'o', repoNamespace: '', repoName: 'r' })).toBeFalsy()
	})

	it('allows an import only with the active tab\'s input', () => {
		const empty = { selectedFile: null, importUrl: '', selectedUploadFile: null }
		expect(computed.canImport.call({ ...empty, activeTab: 0, selectedFile: {} })).toBe(false)
		expect(computed.canImport.call({ ...empty, activeTab: 1 })).toBe(false)
		expect(computed.canImport.call({ ...empty, activeTab: 1, selectedFile: {} })).toBe(true)
		expect(computed.canImport.call({ ...empty, activeTab: 2, importUrl: 'https://x' })).toBe(true)
		expect(computed.canImport.call({ ...empty, activeTab: 2 })).toBe(false)
		expect(computed.canImport.call({ ...empty, activeTab: 3, selectedUploadFile: {} })).toBe(true)
		expect(computed.canImport.call({ ...empty, activeTab: 3 })).toBe(false)
	})
})

describe('importDiscoveredConfiguration', () => {
	it('imports a GitHub result on its ref, refreshes the lists and closes', async () => {
		configurationStore.importFromGitHub.mockResolvedValue()
		const ctx = modal()
		ctx.closeModal = jest.fn()

		await ctx.importDiscoveredConfiguration({ repository: 'conduction/catalog', path: 'cfg.json', ref: 'dev' })

		expect(configurationStore.importFromGitHub).toHaveBeenCalledWith({
			owner: 'conduction', repo: 'catalog', path: 'cfg.json', branch: 'dev', syncEnabled: true, syncInterval: 24,
		})
		expect(configurationStore.importFromGitLab).not.toHaveBeenCalled()
		expect(ctx.success).toBe(true)
		expect(ctx.loading).toBe(false)
		expect(configurationStore.refreshConfigurationList).toHaveBeenCalledTimes(1)
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(ctx.closeModal).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1500)
		expect(ctx.closeModal).toHaveBeenCalledTimes(1)
	})

	it('imports a GitLab result on main when it names no ref', async () => {
		configurationStore.importFromGitLab.mockResolvedValue()

		await modal({ closeModal: jest.fn() }).importDiscoveredConfiguration({ namespace: 'ns', project: 'p', path: 'c.json' })

		expect(configurationStore.importFromGitLab).toHaveBeenCalledWith({
			namespace: 'ns', project: 'p', path: 'c.json', ref: 'main', syncEnabled: true, syncInterval: 24,
		})
	})

	it('shows a failure but not an already-existing configuration, and refreshes either way', async () => {
		configurationStore.importFromGitLab.mockRejectedValueOnce(new Error('Rate limited'))
		const failed = modal()
		await failed.importDiscoveredConfiguration({ namespace: 'ns', project: 'p', path: 'c.json' })
		expect(failed.error).toBe('Rate limited')
		expect(failed.success).toBe(false)
		expect(failed.loading).toBe(false)

		configurationStore.importFromGitLab.mockRejectedValueOnce(new Error('Configuration already exists'))
		const existing = modal()
		await existing.importDiscoveredConfiguration({ namespace: 'ns', project: 'p', path: 'c.json' })
		expect(existing.error).toBeNull()

		expect(configurationStore.refreshConfigurationList).toHaveBeenCalledTimes(2)
	})
})

describe('clearRepositoryChoice', () => {
	it('forgets branches and files', () => {
		const ctx = modal({ branches: ['a'], selectedBranch: 'a', configFiles: ['f'], selectedFile: 'f' })

		ctx.clearRepositoryChoice()

		expect([ctx.branches, ctx.selectedBranch, ctx.configFiles, ctx.selectedFile]).toEqual([[], null, [], null])
	})
})
