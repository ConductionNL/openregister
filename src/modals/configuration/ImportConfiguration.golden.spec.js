import ImportConfiguration from './ImportConfiguration.vue'
import { configurationStore, registerStore, schemaStore } from '../../store/store.js'

// The two import actions: from a discovered repository result, and from the chosen tab.

jest.mock('../../store/store.js', () => ({
	configurationStore: {
		importFromGitHub: jest.fn(),
		importFromGitLab: jest.fn(),
		importFromUrl: jest.fn(),
		refreshConfigurationList: jest.fn(),
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

const STORE_CALLS = [
	[configurationStore, 'importFromGitHub'],
	[configurationStore, 'importFromGitLab'],
	[configurationStore, 'importFromUrl'],
	[configurationStore, 'refreshConfigurationList'],
	[registerStore, 'refreshRegisterList'],
	[schemaStore, 'refreshSchemaList'],
]

/**
 * A bare modal context whose file import and close only record themselves.
 *
 * @param {object} state The modal state
 * @param {Array} log Where every store and modal call is recorded
 * @return {object} The context
 */
function context(state, log) {
	const ctx = { ...ImportConfiguration.data.call({}), ...state }
	for (const [name, method] of Object.entries(ImportConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.closeModal = () => log.push(['closeModal'])
	ctx.importFromFile = () => {
		log.push(['importFromFile'])
		return state.fileFails
			? Promise.reject(new Error('Bad file'))
			: Promise.resolve()
	}
	return ctx
}

/**
 * Make every store call record itself, failing the named one.
 *
 * @param {Array} log Where the calls are recorded
 * @param {object} failures Store method name to the error it rejects with
 */
function recordStores(log, failures) {
	for (const [store, name] of STORE_CALLS) {
		store[name].mockImplementation((...args) => {
			log.push([name, ...args])
			return failures[name]
				? Promise.reject(failures[name])
				: Promise.resolve()
		})
	}
}

/**
 * The modal's own state.
 *
 * @param {object} ctx The context
 * @return {string} The state
 */
function state(ctx) {
	return JSON.stringify(
		Object.fromEntries(
			['loading', 'error', 'success', 'successMessage', 'urlError'].map(
				(key) => [key, ctx[key]],
			),
		),
	)
}

/**
 * Run an action, then the close timer.
 *
 * @param {object} ctx The context
 * @param {Function} action The action
 * @param {Array} log The call log
 * @return {Promise<object>} The record
 */
async function run(ctx, action, log) {
	await action()
	const before = state(ctx)
	jest.runAllTimers()
	return { log, before, after: state(ctx) }
}

const RESULTS = {
	github: { repository: 'owner/repo', path: 'c.json', ref: 'dev' },
	githubNoRef: { repository: 'owner/repo/extra', path: 'c.json' },
	gitlab: { namespace: 'ns', project: 'p', path: 'g.json', ref: 'feature' },
	gitlabNoRef: { namespace: 'ns', project: 'p', path: 'g.json', ref: '' },
	both: { repository: 'o/r', namespace: 'ns', project: 'p', path: 'x.json' },
	neither: { path: 'y.json' },
}

const FAILURES = {
	none: {},
	exists: { message: 'Configuration already exists' },
	other: { message: 'Server error' },
	silent: {},
}

describe('ImportConfiguration.importDiscoveredConfiguration', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
	})
	afterEach(() => jest.useRealTimers())

	for (const [resultName, result] of Object.entries(RESULTS)) {
		for (const [failName, failure] of Object.entries(FAILURES)) {
			it(`imports ${resultName}, failing ${failName}`, async () => {
				const log = []
				const importer = result.repository
					? 'importFromGitHub'
					: 'importFromGitLab'
				recordStores(log, failName === 'none' ? {} : { [importer]: failure })
				const ctx = context({ error: 'old' }, log)
				expect(
					await run(
						ctx,
						() => ctx.importDiscoveredConfiguration(result),
						log,
					),
				).toMatchSnapshot()
			})
		}
	}
})

const TABS = {
	github: {
		activeTab: 1,
		repoSource: 'GitHub',
		repoOwner: 'o',
		repoName: 'r',
		repoNamespace: 'ns',
		selectedBranch: { id: 'main' },
		selectedFile: { path: 'a.json' },
		syncEnabled: true,
		syncInterval: '12',
	},
	gitlab: {
		activeTab: 1,
		repoSource: 'GitLab',
		repoOwner: 'o',
		repoName: 'r',
		repoNamespace: 'ns',
		selectedBranch: { id: 'dev' },
		selectedFile: { path: 'b.json' },
		syncEnabled: false,
		syncInterval: '7.9',
	},
	url: {
		activeTab: 2,
		importUrl: 'https://example.com/c.json',
		syncEnabled: true,
		syncInterval: '24',
	},
	badUrl: {
		activeTab: 2,
		importUrl: 'not a url',
		syncEnabled: true,
		syncInterval: '24',
	},
	file: { activeTab: 3 },
	fileFails: { activeTab: 3, fileFails: true },
	otherTab: { activeTab: 0 },
}

describe('ImportConfiguration.performImport', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
	})
	afterEach(() => jest.useRealTimers())

	for (const [tabName, tab] of Object.entries(TABS)) {
		for (const failName of ['none', 'other', 'silent']) {
			it(`imports ${tabName}, failing ${failName}`, async () => {
				const log = []
				const failure = FAILURES[failName]
				recordStores(
					log,
					failName === 'none'
						? {}
						: {
								importFromGitHub: failure,
								importFromGitLab: failure,
								importFromUrl: failure,
							},
				)
				const ctx = context({ ...tab, error: 'old' }, log)
				expect(
					await run(ctx, () => ctx.performImport(), log),
				).toMatchSnapshot()
			})
		}
	}

	it('reports a failing refresh', async () => {
		const log = []
		recordStores(log, { refreshRegisterList: new Error('Refresh failed') })
		const ctx = context({ ...TABS.url }, log)
		expect(await run(ctx, () => ctx.performImport(), log)).toMatchSnapshot()
	})
})
