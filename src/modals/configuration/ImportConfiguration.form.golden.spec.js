/**
 * Golden record of the import modal's form plumbing: the token check, the
 * form reset, the discovery search and the file upload request.
 *
 * @spec exclude Golden record of client-side import form plumbing.
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
		discoverConfigurations: jest.fn(),
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

/**
 * A modal context with the modal's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state = {}) {
	const ctx = { ...ImportConfiguration.data.call({}), ...state }
	for (const [name, method] of Object.entries(ImportConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => jest.restoreAllMocks())

describe('ImportConfiguration form (golden)', () => {
	it.each([
		['both tokens', () => Promise.resolve({ data: { github_token: '***', gitlab_token: 'x' } })],
		['empty tokens', () => Promise.resolve({ data: { github_token: '', gitlab_token: null } })],
		['no tokens', () => Promise.resolve({ data: {} })],
		['a failed check', () => Promise.reject(new Error('down'))],
	])('checks the tokens with %s', async (name, answer) => {
		axios.get.mockImplementation(answer)
		const ctx = context({ hasGithubToken: 'old', hasGitlabToken: 'old' })
		await ctx.checkTokenAvailability()
		expect({
			request: axios.get.mock.calls,
			github: ctx.hasGithubToken,
			gitlab: ctx.hasGitlabToken,
		}).toMatchSnapshot()
	})

	it('resets every field of the form', () => {
		const ctx = context()
		for (const key of Object.keys(ctx)) {
			if (typeof ctx[key] !== 'function') ctx[key] = 'dirty'
		}
		ctx.resetForm()
		const state = {}
		for (const [key, value] of Object.entries(ctx)) {
			if (typeof value !== 'function') state[key] = value
		}
		expect(JSON.stringify(state)).toMatchSnapshot()
	})

	it.each([
		['finds configurations', () => Promise.resolve([{ id: 1 }])],
		['reports a failed search', () => Promise.reject(new Error('rate limited'))],
		['reports a failure without a message', () => Promise.reject({})],
	])('searches and %s', async (name, answer) => {
		configurationStore.discoverConfigurations.mockImplementation(answer)
		const ctx = context({ searchSource: 'github', searchQuery: 'q', searchResults: ['old'], error: 'old', searchError: 'old' })
		const pending = ctx.searchConfigurations()
		const during = { loading: ctx.searchLoading, results: ctx.searchResults, hasSearched: ctx.hasSearched }
		await pending
		expect({
			calls: configurationStore.discoverConfigurations.mock.calls,
			during,
			results: ctx.searchResults,
			searchError: ctx.searchError,
			error: ctx.error,
			loading: ctx.searchLoading,
		}).toMatchSnapshot()
	})

	it.each([
		['imports the file', () => Promise.resolve({ data: { ok: true } })],
		['refuses an error answer', () => Promise.resolve({ data: { error: 'Invalid file' } })],
	])('uploads a file and %s', async (name, answer) => {
		axios.post.mockImplementation(answer)
		const file = new File(['{}'], 'c.json', { type: 'application/json' })
		const ctx = context({ selectedUploadFile: file })
		let outcome
		try {
			outcome = { returned: await ctx.importFromFile() }
		} catch (error) {
			outcome = { threw: error.message }
		}
		const [url, form, options] = axios.post.mock.calls[0]
		expect({
			url,
			fields: [...form.entries()].map(([key, value]) => [key, typeof value === 'string' ? value : value.name]),
			options,
			outcome,
		}).toMatchSnapshot()
	})
})
