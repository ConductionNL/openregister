import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ApiTokenConfiguration from './ApiTokenConfiguration.vue'
import CacheManagement from './CacheManagement.vue'

/**
 * The API-token section's load, save, clear and test flows, and the cache
 * section's hit-rate grading, warm-up interval and store hand-offs.
 *
 * @spec exclude Client-side behaviour of two admin settings sections.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../dialogs/settings/ClearCacheDialog.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../store/settings.js', () => ({ __esModule: true, useSettingsStore: () => ({}) }))
jest.mock('pinia', () => ({ __esModule: true, mapStores: () => ({}) }))

/**
 * A bare section state carrying the component's methods and computed getters.
 *
 * @param {object} component The section component
 * @param {object} data The section data
 * @return {object} The section
 */
function section(component, data = {}) {
	const state = {
		t: (app, text, vars) => `this.t:${text}${vars ? JSON.stringify(vars) : ''}`,
		...data,
	}
	const methods = Object.assign({}, ...(component.mixins || []).map((mixin) => mixin.methods || {}), component.methods)
	for (const [name, fn] of Object.entries(methods)) {
		state[name] = fn.bind(state)
	}
	for (const [name, getter] of Object.entries(component.computed)) {
		const get = typeof getter === 'function' ? getter : getter.get
		const set = typeof getter === 'function' ? undefined : getter.set
		Object.defineProperty(state, name, { get: get.bind(state), set: set && set.bind(state), configurable: true })
	}
	return state
}

/**
 * The API-token section with its data defaults.
 *
 * @param {object} data Overrides
 * @return {object} The section
 */
function tokens(data = {}) {
	return section(ApiTokenConfiguration, { ...ApiTokenConfiguration.data(), ...data })
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('ApiTokenConfiguration loading and saving', () => {
	it('takes the loaded tokens and URL as both current and original values', async () => {
		axios.get.mockResolvedValue({ data: { github_token: 'gh', gitlab_token: 'gl', gitlab_url: 'https://git.test' } })
		const state = tokens()
		await state.loadTokens()
		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/api-tokens')
		expect([state.githubToken, state.gitlabToken, state.gitlabUrl]).toEqual(['gh', 'gl', 'https://git.test'])
		expect([state.originalGithubToken, state.originalGitlabToken, state.originalGitlabUrl]).toEqual(['gh', 'gl', 'https://git.test'])
		expect(state.loading).toBe(false)
	})

	it('empties missing values, and only logs a failed load', async () => {
		const state = tokens({ githubToken: 'old' })
		state.applyLoadedTokens({})
		expect([state.githubToken, state.gitlabToken, state.gitlabUrl]).toEqual(['', '', ''])
		axios.get.mockRejectedValue(new Error('down'))
		const logged = jest.spyOn(console, 'error').mockImplementation(() => {})
		await state.loadTokens()
		expect(logged).toHaveBeenCalledWith('Failed to load API tokens:', expect.any(Error))
		expect(showError).not.toHaveBeenCalled()
		expect(state.loading).toBe(false)
		logged.mockRestore()
	})

	it.each([
		['saveGitHubToken', 'githubToken', 'originalGithubToken', 'github_token', 'this.t:GitHub token saved successfully'],
		['saveGitLabToken', 'gitlabToken', 'originalGitlabToken', 'gitlab_token', 'this.t:GitLab token saved successfully'],
		['saveGitLabUrl', 'gitlabUrl', 'originalGitlabUrl', 'gitlab_url', 'this.t:GitLab URL saved successfully'],
	])('%s posts %s, marks it saved and says so', async (method, field, original, key, message) => {
		axios.post.mockResolvedValue({})
		const state = tokens({ [field]: 'new' })
		await state[method]()
		expect(axios.post).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/api-tokens', { [key]: 'new' })
		expect(state[original]).toBe('new')
		expect(showSuccess).toHaveBeenCalledWith(message)
		expect(state.saving).toBe(false)
	})

	it.each([
		['saveGitHubToken', 'githubToken', 'originalGithubToken', 'this.t:Failed to save GitHub token'],
		['saveGitLabToken', 'gitlabToken', 'originalGitlabToken', 'this.t:Failed to save GitLab token'],
		['saveGitLabUrl', 'gitlabUrl', 'originalGitlabUrl', 'this.t:Failed to save GitLab URL'],
	])('%s keeps the original %s and shows an error when the save fails', async (method, field, original, message) => {
		axios.post.mockRejectedValue(new Error('down'))
		const logged = jest.spyOn(console, 'error').mockImplementation(() => {})
		const state = tokens({ [field]: 'new', [original]: 'old' })
		await state[method]()
		expect(state[original]).toBe('old')
		expect(showError).toHaveBeenCalledWith(message)
		expect(state.saving).toBe(false)
		logged.mockRestore()
	})

	it('clears a token by saving it empty', async () => {
		axios.post.mockResolvedValue({})
		const state = tokens({ githubToken: 'gh', gitlabToken: 'gl' })
		await state.clearGitHubToken()
		await state.clearGitLabToken()
		expect(axios.post.mock.calls.map(([, body]) => body)).toEqual([{ github_token: '' }, { gitlab_token: '' }])
		expect([state.originalGithubToken, state.originalGitlabToken]).toEqual(['', ''])
	})

	it('updates the typed values', () => {
		const state = tokens()
		state.updateGitHubToken('a')
		state.updateGitLabToken('b')
		state.updateGitLabUrl('c')
		expect([state.githubToken, state.gitlabToken, state.gitlabUrl]).toEqual(['a', 'b', 'c'])
	})
})

describe('ApiTokenConfiguration token tests', () => {
	it('records a passing GitHub test with its user and scopes', async () => {
		axios.post.mockResolvedValue({ data: { message: 'ok', username: 'octo', scopes: ['repo'] } })
		const state = tokens({ githubToken: 'gh' })
		await state.testGitHubToken()
		expect(axios.post).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/api-tokens/test/github', { token: 'gh' })
		expect(state.githubTestResult).toEqual({ success: true, message: 'ok', username: 'octo', scopes: ['repo'] })
		expect(showSuccess).toHaveBeenCalledWith('this.t:GitHub token is valid! Username: {username}{"username":"octo"}')
		expect(state.testingGithub).toBe(false)
	})

	it('records a passing GitLab test with its user and instance', async () => {
		axios.post.mockResolvedValue({ data: { message: 'ok', username: 'tan', instance: 'git.test' } })
		const state = tokens({ gitlabToken: 'gl', gitlabUrl: 'https://git.test' })
		await state.testGitLabToken()
		expect(axios.post).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/api-tokens/test/gitlab', { token: 'gl', url: 'https://git.test' })
		expect(state.gitlabTestResult).toEqual({ success: true, message: 'ok', username: 'tan', instance: 'git.test' })
		expect(state.testingGitlab).toBe(false)
	})

	it.each([
		[{ response: { data: { message: 'Bad credentials' } }, message: 'ignored' }, 'Bad credentials'],
		[{ message: 'Network Error' }, 'Network Error'],
		[{}, 'Unknown error'],
	])('reports a failing test with the most specific message from %j', async (error, message) => {
		axios.post.mockRejectedValue(error)
		const state = tokens()
		await state.testGitHubToken()
		expect(state.githubTestResult).toEqual({ success: false, message })
		expect(showError).toHaveBeenCalledWith(`this.t:GitHub token test failed: {message}${JSON.stringify({ message })}`)
		await state.testGitLabToken()
		expect(state.gitlabTestResult).toEqual({ success: false, message })
		expect(state.testingGitlab).toBe(false)
	})

	it('binds each card to its own token fields and actions', async () => {
		const state = tokens({ githubToken: 'gh', originalGithubToken: 'o', testingGitlab: true, gitlabTestResult: { success: true } })
		const [github, gitlab] = state.tokenCards
		expect([github.key, github.value, github.original, github.testing, github.result]).toEqual(['github', 'gh', 'o', false, null])
		expect([gitlab.key, gitlab.value, gitlab.testing, gitlab.result]).toEqual(['gitlab', '', true, { success: true }])
		gitlab.update('typed')
		expect(state.gitlabToken).toBe('typed')
		axios.post.mockResolvedValue({ data: {} })
		await github.save()
		expect(axios.post).toHaveBeenLastCalledWith(expect.any(String), { github_token: 'gh' })
		await gitlab.clear()
		expect(axios.post).toHaveBeenLastCalledWith(expect.any(String), { gitlab_token: '' })
		await github.test()
		expect(axios.post).toHaveBeenLastCalledWith('/gen/apps/openregister/api/settings/api-tokens/test/github', { token: 'gh' })
	})
})

describe('CacheManagement', () => {
	/**
	 * The cache section over a store double.
	 *
	 * @param {object} store Store fields
	 * @return {object} The section
	 */
	function cache(store = {}) {
		return section(CacheManagement, {
			settingsStore: {
				cacheStats: {},
				loadCacheStats: jest.fn(),
				showClearCacheDialog: jest.fn(),
				hideClearCacheDialog: jest.fn(),
				performClearCache: jest.fn(),
				saveWarmupInterval: jest.fn(),
				warmupNamesCache: jest.fn(),
				showRebaseDialog: jest.fn(),
				...store,
			},
		})
	}

	it.each([
		[{ hits: 30, requests: 40 }, 75],
		[{ hits: 0, requests: 40 }, 0],
		[{ hits: 3, requests: 0 }, 0],
		[null, 0],
	])('rates %j at %d percent', (service, rate) => {
		expect(cache().getServiceHitRate(service)).toBe(rate)
	})

	it.each([
		[80, 'status-enabled', '✓ Excellent'],
		[79.9, 'status-warning', '⚠ Good'],
		[60, 'status-warning', '⚠ Good'],
		[59, 'status-error', '❌ Poor'],
	])('grades %d percent as %s', (rate, className, text) => {
		expect(cache().getHitRateClass(rate)).toBe(className)
		expect(cache().getHitRateText(rate)).toBe(text)
	})

	it('grades the overall hit rate, counting a missing one as zero', () => {
		expect(cache({ cacheStats: { overview: { overallHitRate: 90 } } }).hitRateClass).toBe('status-enabled')
		expect(cache({ cacheStats: {} }).hitRateClass).toBe('status-error')
	})

	it('names the distributed backend, File by default and Unknown without one', () => {
		expect(cache({ cacheStats: { distributed: { backend: 'Redis' } } }).getDistributedCacheBackend()).toBe('Redis')
		expect(cache({ cacheStats: { distributed: {} } }).getDistributedCacheBackend()).toBe('File')
		expect(cache({ cacheStats: {} }).getDistributedCacheBackend()).toBe('Unknown')
	})

	it('selects the stored warm-up interval, or every hour for an unknown one', () => {
		expect(cache({ warmupInterval: 900 }).selectedWarmupOption).toEqual({ label: 'Every 15 minutes', value: 900 })
		expect(cache({ warmupInterval: 0 }).selectedWarmupOption).toEqual({ label: 'Disabled', value: 0 })
		expect(cache({ warmupInterval: 7 }).selectedWarmupOption).toEqual({ label: 'Every hour', value: 3600 })
	})

	it('saves a chosen warm-up interval, and ignores a cleared choice', () => {
		const state = cache()
		state.onWarmupIntervalChange({ value: 1800 })
		expect(state.settingsStore.saveWarmupInterval).toHaveBeenCalledWith(1800)
		state.onWarmupIntervalChange(null)
		expect(state.settingsStore.saveWarmupInterval).toHaveBeenCalledTimes(1)
	})

	it('passes its buttons on to the store', () => {
		const state = cache()
		state.loadCacheStats()
		state.showClearCacheDialog()
		state.hideClearCacheDialog()
		state.performClearCache()
		state.triggerWarmup()
		const store = state.settingsStore
		for (const call of [store.loadCacheStats, store.showClearCacheDialog, store.hideClearCacheDialog, store.performClearCache, store.warmupNamesCache]) {
			expect(call).toHaveBeenCalledTimes(1)
		}
	})

	it('reads and writes the clear-cache type through the store', () => {
		const state = cache({ clearCacheType: 'all' })
		expect(state.clearCacheType).toBe('all')
		state.clearCacheType = 'object'
		expect(state.settingsStore.clearCacheType).toBe('object')
	})

	it('says Never without a date, and formats one in local time', () => {
		expect(cache().formatDate(null)).toBe('Never')
		expect(cache().formatDate('2026-01-05T07:09:00Z')).toBe(new Date('2026-01-05T07:09:00Z').toLocaleString())
	})

	it('lists the object, schema and facet caches', () => {
		expect(cache().cacheServices.map((service) => service.key)).toEqual(['object', 'schema', 'facet'])
	})
})
