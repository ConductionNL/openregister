/**
 * The import modal's repository and file paths: picking the default branch,
 * the parameters GitHub and GitLab requests carry, importing the chosen file
 * or a URL, and choosing, validating and clearing an uploaded file.
 *
 * @spec exclude Unit test of client-side import modal logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ImportConfiguration from './ImportConfiguration.vue'
import { configurationStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	configurationStore: {
		importFromGitHub: jest.fn(),
		importFromGitLab: jest.fn(),
		importFromUrl: jest.fn(),
		refreshConfigurationList: jest.fn(),
		getBranches: jest.fn(),
		getConfigurationFiles: jest.fn(),
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

const { methods } = ImportConfiguration

/**
 * A bare modal context with the modal's methods bound to it.
 *
 * @param {object} extra Extra state
 * @return {object} The context
 */
function modal(extra = {}) {
	const ctx = { ...ImportConfiguration.data.call({}), $refs: {}, ...extra }
	for (const [name, method] of Object.entries(methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * A file double of the given name and size.
 *
 * @param {string} name The file name
 * @param {number} size The size in bytes
 * @return {object} The file
 */
function file(name, size = 100) {
	return { name, size }
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('fetchBranches', () => {
	it('lists the GitHub branches and opens the default one', async () => {
		configurationStore.getBranches.mockResolvedValue([{ name: 'dev' }, { name: 'trunk', default: true }])
		configurationStore.getConfigurationFiles.mockResolvedValue([{ path: 'a.json' }])
		const ctx = modal({ repoSource: 'GitHub', repoOwner: 'acme', repoName: 'conf' })
		await ctx.fetchBranches()
		expect(configurationStore.getBranches).toHaveBeenCalledWith('github', { owner: 'acme', repo: 'conf' })
		expect(ctx.branches).toEqual([{ id: 'dev', label: 'dev' }, { id: 'trunk', label: 'trunk' }])
		expect(ctx.selectedBranch).toEqual({ id: 'trunk', label: 'trunk' })
		expect(configurationStore.getConfigurationFiles).toHaveBeenCalledWith('github', { owner: 'acme', repo: 'conf', branch: 'trunk' })
		expect(ctx.configFiles).toEqual([{ path: 'a.json' }])
		expect(ctx.loading).toBe(false)
	})

	it('takes main or master as the default on GitLab, asking by ref', async () => {
		configurationStore.getBranches.mockResolvedValue([{ name: 'x' }, { name: 'master' }])
		configurationStore.getConfigurationFiles.mockResolvedValue([])
		const ctx = modal({ repoSource: 'GitLab', repoNamespace: 'grp', repoName: 'conf' })
		await ctx.fetchBranches()
		expect(configurationStore.getBranches).toHaveBeenCalledWith('gitlab', { namespace: 'grp', project: 'conf' })
		expect(configurationStore.getConfigurationFiles).toHaveBeenCalledWith('gitlab', { namespace: 'grp', project: 'conf', ref: 'master' })
	})

	it('selects nothing without a default branch', async () => {
		configurationStore.getBranches.mockResolvedValue([{ name: 'feature' }])
		const ctx = modal({ repoSource: 'GitHub' })
		await ctx.fetchBranches()
		expect(ctx.selectedBranch).toBeNull()
		expect(configurationStore.getConfigurationFiles).not.toHaveBeenCalled()
	})

	it('reports a failure, or its own message', async () => {
		configurationStore.getBranches.mockRejectedValueOnce(new Error('Not found'))
		const ctx = modal({ repoSource: 'GitHub' })
		await ctx.fetchBranches()
		expect(ctx.error).toBe('Not found')
		configurationStore.getBranches.mockRejectedValueOnce({})
		await ctx.fetchBranches()
		expect(ctx.error).toBe('Failed to fetch branches')
		expect(ctx.loading).toBe(false)
	})
})

describe('fetchConfigurationFiles', () => {
	it('does nothing without a branch', async () => {
		const ctx = modal({ repoSource: 'GitHub' })
		await ctx.fetchConfigurationFiles()
		expect(configurationStore.getConfigurationFiles).not.toHaveBeenCalled()
	})

	it('forgets the chosen file and reports a failure', async () => {
		configurationStore.getConfigurationFiles.mockRejectedValueOnce(new Error('rate limited'))
		const ctx = modal({ repoSource: 'GitHub', selectedBranch: { id: 'main' }, selectedFile: { path: 'old.json' }, configFiles: [{}] })
		await ctx.fetchConfigurationFiles()
		expect(ctx.error).toBe('rate limited')
		expect(ctx.configFiles).toEqual([])
		expect(ctx.selectedFile).toBeNull()
		configurationStore.getConfigurationFiles.mockRejectedValueOnce({})
		await ctx.fetchConfigurationFiles()
		expect(ctx.error).toBe('Failed to fetch configuration files')
	})
})

describe('importFromTab', () => {
	it('imports the chosen file from GitHub with the sync settings', async () => {
		const ctx = modal({
			activeTab: 1,
			repoSource: 'GitHub',
			repoOwner: 'acme',
			repoName: 'conf',
			selectedBranch: { id: 'main' },
			selectedFile: { path: 'dir/c.json' },
			syncEnabled: true,
			syncInterval: '12',
		})
		await expect(ctx.importFromTab()).resolves.toBe('Configuration imported from GitHub!')
		expect(configurationStore.importFromGitHub).toHaveBeenCalledWith({
			owner: 'acme',
			repo: 'conf',
			branch: 'main',
			path: 'dir/c.json',
			syncEnabled: true,
			syncInterval: 12,
		})
		expect(configurationStore.importFromGitLab).not.toHaveBeenCalled()
	})

	it('imports the chosen file from GitLab by ref', async () => {
		const ctx = modal({
			activeTab: 1,
			repoSource: 'GitLab',
			repoNamespace: 'grp',
			repoName: 'conf',
			selectedBranch: { id: 'dev' },
			selectedFile: { path: 'c.json' },
			syncEnabled: false,
			syncInterval: '24',
		})
		await expect(ctx.importFromTab()).resolves.toBe('Configuration imported from GitLab!')
		expect(configurationStore.importFromGitLab).toHaveBeenCalledWith({
			namespace: 'grp',
			project: 'conf',
			ref: 'dev',
			path: 'c.json',
			syncEnabled: false,
			syncInterval: 24,
		})
	})

	it('refuses an invalid URL without importing', async () => {
		const ctx = modal({ activeTab: 2, importUrl: 'not a url', loading: true })
		await expect(ctx.importFromTab()).resolves.toBe(false)
		expect(ctx.urlError).toBe('Please enter a valid URL')
		expect(ctx.loading).toBe(false)
		expect(configurationStore.importFromUrl).not.toHaveBeenCalled()
	})

	it('imports a valid URL with the sync settings', async () => {
		const ctx = modal({ activeTab: 2, importUrl: 'https://example.org/c.json', syncEnabled: true, syncInterval: '6' })
		await expect(ctx.importFromTab()).resolves.toBe('Configuration imported from URL!')
		expect(configurationStore.importFromUrl).toHaveBeenCalledWith({
			url: 'https://example.org/c.json',
			syncEnabled: true,
			syncInterval: 6,
		})
	})

	it('uploads the chosen file as a forced import', async () => {
		axios.post.mockResolvedValue({ data: { ok: true } })
		const upload = new Blob(['{}'], { type: 'application/json' })
		const ctx = modal({ activeTab: 3, selectedUploadFile: upload })
		await expect(ctx.importFromTab()).resolves.toBe('Configuration imported from file!')
		const [url, form, options] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/configurations/import')
		expect(form.get('force')).toBe('true')
		expect(form.get('appId')).toBe('uploaded-config')
		expect(form.get('file')).toBeInstanceOf(Blob)
		expect(options).toEqual({ headers: { 'Content-Type': 'multipart/form-data' } })
	})

	it('throws the error an upload answers with', async () => {
		axios.post.mockResolvedValue({ data: { error: 'Invalid configuration' } })
		const ctx = modal({ activeTab: 3, selectedUploadFile: new Blob(['{}']) })
		await expect(ctx.importFromTab()).rejects.toThrow('Invalid configuration')
	})

	it('does nothing on the search tab', async () => {
		const ctx = modal({ activeTab: 0 })
		await expect(ctx.importFromTab()).resolves.toBeUndefined()
	})
})

describe('uploaded file', () => {
	it('accepts a JSON file chosen in the input', () => {
		const ctx = modal({ fileError: 'stale' })
		const chosen = file('config.json')
		ctx.handleFileSelect({ target: { files: [chosen] } })
		expect(ctx.selectedUploadFile).toBe(chosen)
		expect(ctx.fileError).toBeNull()
	})

	it('ignores an input without a file', () => {
		const ctx = modal()
		ctx.handleFileSelect({ target: { files: [] } })
		expect(ctx.selectedUploadFile).toBeNull()
	})

	it('accepts a dropped JSON file and ends the drag', () => {
		const ctx = modal({ isDragging: true })
		const dropped = file('dropped.json')
		ctx.handleFileDrop({ dataTransfer: { files: [dropped] } })
		expect(ctx.isDragging).toBe(false)
		expect(ctx.selectedUploadFile).toBe(dropped)
		const other = modal({ isDragging: true })
		other.handleFileDrop({ dataTransfer: { files: [] } })
		expect(other.isDragging).toBe(false)
		expect(other.selectedUploadFile).toBeNull()
	})

	it('refuses a file that is not JSON', () => {
		const ctx = modal()
		ctx.validateAndSetFile(file('config.yaml'))
		expect(ctx.fileError).toBe('Please upload a JSON file')
		expect(ctx.selectedUploadFile).toBeNull()
	})

	it('refuses a file over 10 MB and accepts one of exactly 10 MB', () => {
		const ctx = modal()
		ctx.validateAndSetFile(file('big.json', 10 * 1024 * 1024 + 1))
		expect(ctx.fileError).toBe('File is too large (max 10MB)')
		expect(ctx.selectedUploadFile).toBeNull()
		const limit = file('limit.json', 10 * 1024 * 1024)
		ctx.validateAndSetFile(limit)
		expect(ctx.fileError).toBeNull()
		expect(ctx.selectedUploadFile).toBe(limit)
	})

	it('clears the file, its error and the input', () => {
		const input = { value: 'C:/fakepath/config.json' }
		const ctx = modal({ selectedUploadFile: file('config.json'), fileError: 'x', $refs: { fileInput: input } })
		ctx.clearFileSelection()
		expect(ctx.selectedUploadFile).toBeNull()
		expect(ctx.fileError).toBeNull()
		expect(input.value).toBe('')
		const bare = modal({ selectedUploadFile: file('a.json') })
		bare.clearFileSelection()
		expect(bare.selectedUploadFile).toBeNull()
	})

	it('formats a file size in bytes, kilobytes or megabytes', () => {
		const ctx = modal()
		expect(ctx.formatFileSize(512)).toBe('512 B')
		expect(ctx.formatFileSize(1536)).toBe('1.5 KB')
		expect(ctx.formatFileSize(3 * 1024 * 1024)).toBe('3.0 MB')
	})
})
