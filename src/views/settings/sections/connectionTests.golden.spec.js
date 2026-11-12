/**
 * The connection and token tests of the file and API token sections: every
 * state write, message, request and log line, in the order they happen.
 *
 * @spec exclude Golden record of client-side settings actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ApiTokenConfiguration from './ApiTokenConfiguration.vue'
import FileConfiguration from './FileConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
	n: (app, one, many) => many,
}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Github.vue', () => ({}))
jest.mock('vue-material-design-icons/Gitlab.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TestTube.vue', () => ({}))
jest.mock('vue-material-design-icons/Web.vue', () => ({}))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))

let log

/**
 * A section instance whose every state write is logged.
 *
 * @param {object} component The section
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(component, state) {
	const target = { ...state }
	const vm = new Proxy(target, {
		set(obj, key, value) {
			log.push(['set', key, value])
			obj[key] = value
			return true
		},
	})
	for (const [name, method] of Object.entries(component.methods)) {
		target[name] = method.bind(vm)
	}
	target.t = (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text
	target.showSaveMessage = (text, type) => log.push(['message', text, type])
	return vm
}

/**
 * A settings store whose test calls are logged and answer as given.
 *
 * @param {object|Error} reply The answer, or the failure
 * @return {object} The store
 */
function store(reply) {
	const call =
		(name) =>
		(...args) => {
			log.push(['store', name, ...args])
			return reply instanceof Error
				? Promise.reject(reply)
				: Promise.resolve(reply)
		}
	return {
		testDolphinConnection: call('testDolphinConnection'),
		testPresidioConnection: call('testPresidioConnection'),
		testOpenAnonymiserConnection: call('testOpenAnonymiserConnection'),
	}
}

const FILLED = {
	dolphinApiEndpoint: 'http://d',
	dolphinApiKey: 'dk',
	presidioApiEndpoint: 'http://p',
	openAnonymiserApiEndpoint: 'http://o',
}

const REPLIES = {
	passes: { success: true },
	'is refused': { success: false, error: 'bad key' },
	crashes: new Error('offline'),
}

beforeEach(() => {
	jest.clearAllMocks()
	log = []
	jest
		.spyOn(console, 'error')
		.mockImplementation((...args) => log.push(['console', ...args]))
	showSuccess.mockImplementation((text) => log.push(['success', text]))
	showError.mockImplementation((text) => log.push(['error', text]))
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('FileConfiguration connection tests', () => {
	const TESTS = [
		'testDolphinConnection',
		'testPresidioConnection',
		'testOpenAnonymiserConnection',
	]

	for (const method of TESTS) {
		it(`${method} asks for missing input`, async () => {
			const vm = section(FileConfiguration, {
				fileSettings: { dolphinApiEndpoint: 'http://d' },
				settingsStore: store({ success: true }),
			})
			await vm[method]()
			expect(log).toMatchSnapshot()
		})

		for (const [outcome, reply] of Object.entries(REPLIES)) {
			it(`${method} ${outcome}`, async () => {
				const vm = section(FileConfiguration, {
					fileSettings: { ...FILLED },
					settingsStore: store(reply),
				})
				await vm[method]()
				expect(log).toMatchSnapshot()
			})
		}
	}

	it('testDolphinConnection asks for a missing endpoint too', async () => {
		const vm = section(FileConfiguration, {
			fileSettings: { dolphinApiKey: 'dk' },
			settingsStore: store({ success: true }),
		})
		await vm.testDolphinConnection()
		expect(log).toMatchSnapshot()
	})
})

describe('ApiTokenConfiguration token tests', () => {
	const STATE = {
		githubToken: 'ghp_x',
		gitlabToken: 'glpat_x',
		gitlabUrl: 'https://gitlab.example',
	}
	const ANSWER = {
		data: {
			message: 'ok',
			username: 'octo',
			scopes: ['repo'],
			instance: 'gitlab.example',
			extra: 1,
		},
	}
	const FAILURES = {
		'with a server message': {
			response: { data: { message: 'Bad credentials' } },
			message: 'Request failed',
		},
		'with its own message': { message: 'Network down' },
		'without a message': {},
	}

	for (const method of ['testGitHubToken', 'testGitLabToken']) {
		it(`${method} passes`, async () => {
			axios.post.mockImplementation((...args) => {
				log.push(['post', ...args])
				return Promise.resolve(ANSWER)
			})
			await section(ApiTokenConfiguration, STATE)[method]()
			expect(log).toMatchSnapshot()
		})

		for (const [name, failure] of Object.entries(FAILURES)) {
			it(`${method} fails ${name}`, async () => {
				axios.post.mockImplementation((...args) => {
					log.push(['post', ...args])
					return Promise.reject(failure)
				})
				await section(ApiTokenConfiguration, STATE)[method]()
				expect(log).toMatchSnapshot()
			})
		}
	}
})

describe('ApiTokenConfiguration token settings', () => {
	const STATE = {
		githubToken: 'ghp_x',
		gitlabToken: 'glpat_x',
		gitlabUrl: 'https://gitlab.example',
	}

	for (const method of ['saveGitHubToken', 'saveGitLabToken', 'saveGitLabUrl']) {
		for (const outcome of ['saves', 'fails']) {
			it(`${method} ${outcome}`, async () => {
				axios.post.mockImplementation((...args) => {
					log.push(['post', ...args])
					return outcome === 'saves'
						? Promise.resolve({ data: {} })
						: Promise.reject(new Error('down'))
				})
				await section(ApiTokenConfiguration, STATE)[method]()
				expect(log).toMatchSnapshot()
			})
		}
	}

	it.each([
		['all settings', { github_token: 'a', gitlab_token: 'b', gitlab_url: 'c' }],
		['no settings', {}],
	])('loadTokens reads %s', async (_name, data) => {
		axios.get.mockImplementation((...args) => {
			log.push(['get', ...args])
			return Promise.resolve({ data })
		})
		await section(ApiTokenConfiguration, {}).loadTokens()
		expect(log).toMatchSnapshot()
	})

	it('loadTokens logs a failure quietly', async () => {
		axios.get.mockImplementation(() => Promise.reject(new Error('down')))
		await section(ApiTokenConfiguration, {}).loadTokens()
		expect(log).toMatchSnapshot()
	})
})
