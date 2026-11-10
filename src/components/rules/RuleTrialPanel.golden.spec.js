import RuleTrialPanel from './RuleTrialPanel.vue'
import { evaluateRule } from '../../services/rules.js'

// Trying a rule: the payload it sends and the result or error it shows.

jest.mock('@nextcloud/axios', () => ({}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('../../services/rules.js', () => ({
	...jest.requireActual('../../services/rules.js'),
	evaluateRule: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('./RuleTraceView.vue', () => ({}))

/**
 * A bare panel context with every method bound to it.
 *
 * @param {object} state Props and data to start from
 * @return {object} The context
 */
function context(state) {
	const ctx = {
		...RuleTrialPanel.data.call({}),
		schema: 'sch',
		ruleId: 'r1',
		draft: null,
		result: { stale: true },
		error: 'old error',
		sampleError: 'old sample error',
		...state,
	}
	for (const [name, method] of Object.entries(RuleTrialPanel.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * The engine's own JSON.parse message for a text, so the snapshot does not
 * depend on the Node version.
 *
 * @param {string} text The text
 * @return {string} The message, or a marker when it parses
 */
function parseMessage(text) {
	try {
		JSON.parse(text)
		return '<parses>'
	} catch (e) {
		return e.message
	}
}

const TARGETS = {
	empty: { target: 'sample', sample: '' },
	blank: { target: 'sample', sample: '   ' },
	json: { target: 'sample', sample: ' {"a":1} ' },
	array: { target: 'sample', sample: '[1]' },
	broken: { target: 'sample', sample: '{a' },
	object: { target: 'object', register: 'reg', objectId: 'o1', sample: '{a' },
}

const ANSWERS = {
	passes: () => Promise.resolve({ ok: true, verdict: 'pass' }),
	noOk: () => Promise.resolve({ verdict: 'pass' }),
	failsNamed: () =>
		Promise.resolve({ ok: false, error: { message: 'No such rule' } }),
	failsSilent: () => Promise.resolve({ ok: false, error: {} }),
	throwsError: () => Promise.reject(new Error('Network')),
	throwsResponse: () =>
		Promise.reject({ response: { data: { message: 'Denied' } } }),
	throwsNothing: () => Promise.reject(undefined),
}

describe('RuleTrialPanel.run', () => {
	beforeEach(() => jest.clearAllMocks())

	for (const [targetName, target] of Object.entries(TARGETS)) {
		for (const draft of [null, { '==': [1, 1] }]) {
			for (const [answerName, answer] of Object.entries(ANSWERS)) {
				it(`tries ${targetName}, draft ${draft !== null}, answering ${answerName}`, async () => {
					const ctx = context({ ...target, draft })
					const running = []
					evaluateRule.mockImplementation(() => {
						running.push(ctx.running)
						return answer()
					})
					await ctx.run()
					expect({
						calls: evaluateRule.mock.calls,
						running,
						state: {
							running: ctx.running,
							result: ctx.result,
							error: ctx.error,
							sampleError: ctx.sampleError.replace(
								parseMessage(target.sample),
								'<parse error>',
							),
						},
					}).toMatchSnapshot()
				})
			}
		}
	}
})
