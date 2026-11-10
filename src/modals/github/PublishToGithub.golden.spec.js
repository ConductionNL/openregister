/**
 * Golden record of PublishToGithub's loaders and publish action: the state,
 * requests, console errors, refreshes and close timer they leave behind for a
 * range of selections and API answers.
 */
import PublishToGithub from './PublishToGithub.vue'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn() },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({}))

import { navigationStore } from '../../store/store.js'

const { methods, computed } = PublishToGithub

const REPOS = [
	{ full_name: 'acme/site', owner: 'acme', name: 'site', private: false },
	{ full_name: 'acme/deep/x', owner: 'acme', name: 'deep', private: true },
]

const COPY = {
	commitPrefix: 'Publish ',
	publishedMessage: 'Published.',
	failureMessage: 'Publish failed',
}

/**
 * A fetch answer.
 *
 * @param {boolean} ok Whether the answer is a success.
 * @param {*} body The JSON body, or an Error to throw when it is read.
 * @return {object} The response double.
 */
function answer(ok, body) {
	return {
		ok,
		json: async () => {
			if (body instanceof Error) {
				throw body
			}
			return body
		},
	}
}

/**
 * A fresh component context.
 *
 * @param {object} overrides State and props to set.
 * @return {object} The context.
 */
function context(overrides = {}) {
	const ctx = {
		...PublishToGithub.data(),
		entity: { id: 7, title: 'Thing' },
		entityPath: 'registers',
		copy: COPY,
		preselectPublished: false,
		refreshed: 0,
		...overrides,
	}
	ctx.refresh = async () => {
		ctx.refreshed++
	}
	for (const [name, method] of Object.entries(methods)) {
		ctx[name] = method.bind(ctx)
	}
	Object.defineProperty(ctx, 'canPublish', {
		get: () => computed.canPublish.call(ctx),
	})
	return ctx
}

/**
 * Run one method with scripted fetch answers and describe what it left behind.
 *
 * @param {string} name The method.
 * @param {object} ctx The context.
 * @param {Array} answers The fetch answers, in order; an Error rejects.
 * @param {Array} args The method's arguments.
 * @return {Promise<string>} The trace as JSON text.
 */
async function trace(name, ctx, answers, args = []) {
	const queue = [...answers]
	const calls = []
	global.fetch = jest.fn(async (url, options) => {
		calls.push([url, options])
		const next = queue.shift()
		if (next instanceof Error) {
			throw next
		}
		return next
	})
	const timers = []
	const timeout = jest
		.spyOn(global, 'setTimeout')
		.mockImplementation((callback, delay) => {
			timers.push(delay)
			callback()
			return 0
		})
	navigationStore.setModal.mockClear()
	const errors = jest.spyOn(console, 'error').mockImplementation(() => {})
	let thrown = null
	try {
		await methods[name].apply(ctx, args)
	} catch (error) {
		thrown = String(error)
	}
	const logged = errors.mock.calls.map((call) =>
		call.map((arg) => (arg instanceof Error ? `Error:${arg.message}` : arg)),
	)
	errors.mockRestore()
	timeout.mockRestore()
	const state = Object.fromEntries(
		Object.entries(ctx).filter(([, value]) => typeof value !== 'function'),
	)
	return JSON.stringify({
		state,
		thrown,
		logged,
		calls,
		timers,
		modal: navigationStore.setModal.mock.calls,
	})
}

const PUBLISH_FORMS = [
	{},
	{ selectedRepository: 'acme/site', selectedBranch: 'main', filePath: '  ' },
	{
		selectedRepository: 'acme/site',
		selectedBranch: 'main',
		filePath: ' a.json ',
		entity: null,
	},
	{
		selectedRepository: 'acme/site',
		selectedBranch: 'main',
		filePath: ' a.json ',
	},
	{
		selectedRepository: { value: 'acme/site', full_name: 'no/such' },
		selectedBranch: { value: 'dev', name: 'other' },
		filePath: 'b.json',
		commitMessage: 'custom',
	},
	{
		selectedRepository: { value: '', full_name: 'acme/deep/x' },
		selectedBranch: { value: '', name: 'feature' },
		filePath: 'c.json',
	},
	{
		selectedRepository: { full_name: '' },
		selectedBranch: 'main',
		filePath: 'c.json',
	},
	{ selectedRepository: 'other/repo', selectedBranch: 'main', filePath: 'c.json' },
	{
		selectedRepository: 'acme/site',
		selectedBranch: { name: '' },
		filePath: 'c.json',
	},
	{
		selectedRepository: 'acme/site',
		selectedBranch: { value: 0, name: 0 },
		filePath: 'c.json',
	},
]

const PUBLISH_ANSWERS = [
	[answer(true, { commit_sha: 'abcdef1234567' })],
	[answer(true, { commit_sha: 'abc', indexing_note: 'Indexing soon' })],
	[answer(true, {})],
	[answer(true, { commit_sha: '', indexing_note: '' })],
	[answer(false, { error: 'Bad token' })],
	[answer(false, {})],
	[answer(false, new Error('not json'))],
	[new Error('network down')],
	[new Error('')],
]

describe('PublishToGithub (golden)', () => {
	it('publish matches the recorded traces', async () => {
		const traces = []
		for (const form of PUBLISH_FORMS) {
			traces.push(
				await trace('publish', context({ repositories: REPOS, ...form }), [
					PUBLISH_ANSWERS[0][0],
				]),
			)
		}
		for (const answers of PUBLISH_ANSWERS) {
			traces.push(
				await trace(
					'publish',
					context({
						repositories: REPOS,
						selectedRepository: 'acme/site',
						selectedBranch: 'main',
						filePath: 'x.json',
					}),
					answers,
				),
			)
		}
		traces.push(
			await trace(
				'publish',
				context({
					repositories: REPOS,
					selectedRepository: 'acme/site',
					selectedBranch: 'main',
					filePath: 'x.json',
					copy: { ...COPY, failureMessage: '' },
				}),
				[answer(false, {})],
			),
		)
		expect(traces).toMatchSnapshot()
	})

	it('loadRepositories matches the recorded traces', async () => {
		const branches = answer(true, {
			branches: [{ name: 'dev' }, { name: 'main' }],
		})
		const published = {
			id: 7,
			title: 'Thing',
			githubRepo: 'acme/deep/x',
			githubBranch: 'release',
			githubPath: 'p/q.json',
		}
		const cases = [
			[{}, [answer(true, { repositories: REPOS })]],
			[{}, [answer(true, {})]],
			[{}, [answer(false, { error: 'No token' })]],
			[{}, [answer(false, {})]],
			[{}, [new Error('offline')]],
			[{}, [new Error('')]],
			[{ entity: published }, [answer(true, { repositories: REPOS })]],
			[
				{ preselectPublished: true, entity: published },
				[answer(true, { repositories: REPOS }), branches],
			],
			[
				{
					preselectPublished: true,
					entity: { ...published, githubBranch: '', githubPath: '' },
					filePath: 'kept',
				},
				[answer(true, { repositories: REPOS }), branches],
			],
			[
				{
					preselectPublished: true,
					entity: { ...published, githubRepo: 'no/such' },
				},
				[answer(true, { repositories: REPOS })],
			],
			[
				{ preselectPublished: true, entity: { id: 1, title: 'T' } },
				[answer(true, { repositories: REPOS })],
			],
			[
				{ preselectPublished: true, entity: null },
				[answer(true, { repositories: REPOS })],
			],
		]
		const traces = []
		for (const [overrides, answers] of cases) {
			traces.push(
				await trace(
					'loadRepositories',
					context({ error: 'old', ...overrides }),
					answers,
				),
			)
		}
		expect(traces).toMatchSnapshot()
	})

	it('loadBranches matches the recorded traces', async () => {
		const list = (...names) =>
			answer(true, { branches: names.map((name) => ({ name })) })
		const cases = [
			[{}, ['', 'site'], []],
			[{}, ['acme', ''], []],
			[{}, ['a c&me', 'si/te'], [list('dev', 'master', 'main')]],
			[{}, ['acme', 'site'], [list('dev', 'master')]],
			[{}, ['acme', 'site'], [list('dev', 'feature')]],
			[{}, ['acme', 'site'], [list()]],
			[{}, ['acme', 'site'], [answer(true, {})]],
			[{ selectedBranch: 'kept' }, ['acme', 'site'], [list('main')]],
			[{}, ['acme', 'site'], [answer(false, { error: 'Gone' })]],
			[{}, ['acme', 'site'], [answer(false, {})]],
			[{}, ['acme', 'site'], [new Error('offline')]],
			[{}, ['acme', 'site'], [answer(true, { branches: [{}] })]],
			[{}, ['acme', 'site'], [answer(true, { branches: [0] })]],
		]
		const traces = []
		for (const [overrides, args, answers] of cases) {
			traces.push(
				await trace(
					'loadBranches',
					context({ error: 'old', ...overrides }),
					answers,
					args,
				),
			)
		}
		expect(traces).toMatchSnapshot()
	})
})
