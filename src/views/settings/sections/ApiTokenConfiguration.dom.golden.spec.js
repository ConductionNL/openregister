/**
 * Golden record of the API token section: the GitHub and GitLab token cards
 * in each token, save and test state, and the handler each control runs.
 *
 * @spec exclude Golden record of client-side settings section markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
const { normalise, stubModule } = require('../../__mocks__/goldenDom.js')

const ICONS = [
	'AlertCircle',
	'CheckCircle',
	'ContentSave',
	'Delete',
	'Github',
	'Gitlab',
	'InformationOutline',
	'LockOutline',
	'TestTube',
	'Web',
]
ICONS.forEach((name) =>
	jest.mock(`vue-material-design-icons/${name}.vue`, () => stubModule(name)),
)
jest.mock('@nextcloud/vue', () => require('../../__mocks__/goldenDom.js').library())
jest.mock('../../../components/shared/SettingsCard.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('SettingsCard'),
)
jest.mock('../../../components/shared/SettingsSection.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('SettingsSection'),
)
jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const ApiTokenConfiguration = require('./ApiTokenConfiguration.vue').default
const axios = jest.requireMock('@nextcloud/axios')

/**
 * Mounts the section once the stored tokens loaded, then sets its state.
 *
 * @param {object} stored The tokens the backend returns
 * @param {object} state The data to set after loading
 * @return {Promise<object>} The wrapper
 */
async function section(stored, state = {}) {
	axios.get.mockResolvedValue({ data: stored })
	const bindings = {
		...ApiTokenConfiguration.components,
		t: (app, text) => `t:${text}`,
	}
	const wrapper = mount(
		{ ...ApiTokenConfiguration, setup: () => bindings },
		{ global: { config: { warnHandler: () => {} } } },
	)
	await flushPromises()
	await wrapper.setData(state)
	return wrapper
}

const STORED = {
	github_token: 'ghp_saved',
	gitlab_token: 'glpat_saved',
	gitlab_url: 'https://git.example/api/v4',
}

describe('api token configuration', () => {
	it.each([
		['without tokens', {}, {}],
		['with saved tokens', STORED, {}],
		[
			'with edited tokens while saving and testing',
			STORED,
			{
				githubToken: 'ghp_new',
				gitlabToken: 'glpat_new',
				gitlabUrl: 'https://other.example/api/v4',
				saving: true,
				testingGithub: true,
				testingGitlab: true,
			},
		],
		[
			'with test results',
			STORED,
			{
				githubTestResult: { success: true, message: 'GitHub fine' },
				gitlabTestResult: { success: false, message: 'GitLab refused' },
				saveMessage: 'Saved',
			},
		],
		[
			'with the opposite test results',
			STORED,
			{
				githubTestResult: { success: false, message: 'GitHub refused' },
				gitlabTestResult: { success: true, message: 'GitLab fine' },
			},
		],
	])('renders %s', async (name, stored, state) => {
		const html = normalise(await section(stored, state))
		expect(html).toContain('token-config')
		expect(html).toMatchSnapshot()
	})

	it('runs the handler of each token control', async () => {
		const calls = []
		const names = [
			'updateGitHubToken',
			'saveGitHubToken',
			'testGitHubToken',
			'clearGitHubToken',
			'updateGitLabToken',
			'saveGitLabToken',
			'testGitLabToken',
			'clearGitLabToken',
		]
		const methods = Object.fromEntries(
			names.map((method) => [
				method,
				(...args) => calls.push([method, ...args]),
			]),
		)
		axios.get.mockResolvedValue({ data: STORED })
		const bindings = {
			...ApiTokenConfiguration.components,
			t: (app, text) => `t:${text}`,
		}
		const wrapper = mount(
			{
				...ApiTokenConfiguration,
				setup: () => bindings,
				methods: { ...ApiTokenConfiguration.methods, ...methods },
			},
			{ global: { config: { warnHandler: () => {} } } },
		)
		await flushPromises()
		await wrapper.setData({ githubToken: 'ghp_new', gitlabToken: 'glpat_new' })
		const fields = wrapper.findAllComponents({ name: 'NcPasswordField' })
		expect(fields.map((field) => field.attributes('id'))).toEqual([
			'github-token',
			'gitlab-token',
		])
		fields[0].vm.$emit('update:modelValue', 'ghp_typed')
		const buttons = wrapper
			.findAllComponents({ name: 'NcButton' })
			.filter((button) => button.text().includes('Token'))
		expect(buttons).toHaveLength(6)
		buttons.slice(0, 3).forEach((button) => button.vm.$attrs.onClick())
		fields[1].vm.$emit('update:modelValue', 'glpat_typed')
		buttons.slice(3).forEach((button) => button.vm.$attrs.onClick())
		expect(calls.map(([method]) => method)).toEqual(names)
		expect(calls[0]).toEqual(['updateGitHubToken', 'ghp_typed'])
		expect(calls[4]).toEqual(['updateGitLabToken', 'glpat_typed'])
		expect(wrapper.vm.githubToken).toBe('ghp_typed')
		expect(wrapper.vm.gitlabToken).toBe('glpat_typed')
	})
})
