import PublishToGithub from './PublishToGithub.vue'

// Loading the repositories: the requests, the list and the published target it restores.

jest.mock('../../store/store.js', () => ({ navigationStore: {} }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({}))

/**
 * A bare form context with every method bound to it.
 *
 * @param {object} props The props
 * @return {object} The context
 */
function context(props) {
	const ctx = {
		...PublishToGithub.data.call({}),
		modalName: 'publish',
		entityPath: 'registers',
		copy: {},
		error: 'old error',
		loadingRepositories: false,
		...props,
	}
	for (const [name, method] of Object.entries(PublishToGithub.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const REPOSITORIES = [
	{ full_name: 'acme/one', owner: 'acme', name: 'one' },
	{ full_name: 'acme/two', owner: 'acme', name: 'two' },
]

const REPOSITORY_ANSWERS = {
	listed: () => ({ ok: true, json: async () => ({ repositories: REPOSITORIES }) }),
	unlisted: () => ({ ok: true, json: async () => ({}) }),
	refusedNamed: () => ({
		ok: false,
		json: async () => ({ error: 'Token expired' }),
	}),
	refusedSilent: () => ({ ok: false, json: async () => ({}) }),
	networkDown: () => {
		throw new Error('Network')
	},
	silentThrow: () => {
		throw new Error('')
	},
}

const BRANCH_ANSWERS = {
	listed: () => ({
		ok: true,
		json: async () => ({
			branches: [{ name: 'dev' }, { name: 'main', default: true }],
		}),
	}),
	refused: () => ({ ok: false, json: async () => ({ error: 'No access' }) }),
}

const ENTITIES = {
	none: null,
	unpublished: { id: 1 },
	repoOnly: { id: 1, githubRepo: 'acme/two' },
	full: {
		id: 1,
		githubRepo: 'acme/two',
		githubBranch: 'dev',
		githubPath: 'x/y.json',
	},
	gone: { id: 1, githubRepo: 'acme/gone', githubBranch: 'dev', githubPath: 'p' },
}

describe('PublishToGithub.loadRepositories', () => {
	let log

	beforeEach(() => {
		log = []
		jest.spyOn(console, 'error').mockImplementation((...args) =>
			log.push(['console.error', args[0], String(args[1])]),
		)
	})

	afterEach(() => jest.restoreAllMocks())

	for (const [repoName, repoAnswer] of Object.entries(REPOSITORY_ANSWERS)) {
		for (const [branchName, branchAnswer] of Object.entries(BRANCH_ANSWERS)) {
			for (const [entityName, entity] of Object.entries(ENTITIES)) {
				for (const preselectPublished of [false, true]) {
					it(`loads ${repoName}, branches ${branchName}, entity ${entityName}, preselect ${preselectPublished}`, async () => {
						const ctx = context({ entity, preselectPublished })
						global.fetch = async (url, init) => {
							log.push([
								'fetch',
								url,
								init,
								ctx.loadingRepositories,
								ctx.error,
							])
							return url.includes('/branches?')
								? branchAnswer()
								: repoAnswer()
						}
						await ctx.loadRepositories()
						const state = {}
						for (const key of [
							'loadingRepositories',
							'loadingBranches',
							'error',
							'repositories',
							'branches',
							'selectedRepository',
							'selectedBranch',
							'filePath',
						]) {
							state[key] = ctx[key]
						}
						expect({ log, state }).toMatchSnapshot()
					})
				}
			}
		}
	}
})
