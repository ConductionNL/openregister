import ImportConfiguration from './ImportConfiguration.vue'
import { configurationStore } from '../../store/store.js'

// Loading a repository's branches, and pre-selecting its default branch.

jest.mock('../../store/store.js', () => ({
	configurationStore: {
		importFromGitHub: jest.fn(),
		importFromGitLab: jest.fn(),
		importFromUrl: jest.fn(),
		refreshConfigurationList: jest.fn(),
		getBranches: jest.fn(),
	},
	navigationStore: {},
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

const ANSWERS = {
	defaultFlag: [{ name: 'dev' }, { name: 'release', default: true }],
	main: [{ name: 'feature' }, { name: 'main' }],
	master: [{ name: 'master' }, { name: 'x' }],
	noDefault: [{ name: 'a' }, { name: 'b' }],
	empty: [],
	failed: new Error('Repository not found'),
	failedSilently: new Error(''),
}

const SOURCES = {
	github: { repoSource: 'GitHub', repoOwner: 'owner', repoName: 'repo' },
	gitlab: { repoSource: 'GitLab', repoNamespace: 'ns', repoName: 'proj' },
}

describe('ImportConfiguration.fetchBranches', () => {
	for (const [sourceName, source] of Object.entries(SOURCES)) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			it(`fetches ${sourceName} branches, answering ${answerName}`, async () => {
				const log = []
				configurationStore.getBranches.mockImplementation((...args) => {
					log.push(['getBranches', ...args])
					return answer instanceof Error
						? Promise.reject(answer)
						: Promise.resolve(answer)
				})
				const ctx = {
					...ImportConfiguration.data.call({}),
					...source,
					error: 'stale',
					branches: ['stale'],
					selectedBranch: 'stale',
					configFiles: ['stale'],
					selectedFile: 'stale',
				}
				for (const [name, method] of Object.entries(
					ImportConfiguration.methods,
				)) {
					ctx[name] = method.bind(ctx)
				}
				ctx.fetchConfigurationFiles = () => {
					log.push([
						'fetchConfigurationFiles',
						ctx.selectedBranch,
						ctx.loading,
					])
					return Promise.resolve()
				}
				await ctx.fetchBranches()
				expect({
					log,
					loading: ctx.loading,
					error: ctx.error,
					branches: ctx.branches,
					selectedBranch: ctx.selectedBranch,
					configFiles: ctx.configFiles,
					selectedFile: ctx.selectedFile,
				}).toMatchSnapshot()
			})
		}
	}
})
