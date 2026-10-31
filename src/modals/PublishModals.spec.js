/**
 * Golden test for the two publish-to-GitHub modals (register OAS and configuration).
 *
 * Each scenario mounts the real modal with stubbed @nextcloud/vue components,
 * a scripted fetch and mocked stores, drives it, and snapshots the rendered
 * markup, every fetch call, the store calls and the component state.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import { mount } from '@vue/test-utils'
import PublishConfiguration from './configuration/PublishConfiguration.vue'
import PublishRegister from './register/PublishRegister.vue'

jest.mock('../store/store.js', () => {
	const { reactive: makeReactive } = jest.requireActual('vue')
	return {
		__esModule: true,
		navigationStore: makeReactive({ modal: null, setModal: jest.fn() }),
		registerStore: makeReactive({
			registerItem: null,
			refreshRegisterList: jest.fn(),
		}),
		configurationStore: makeReactive({
			configurationItem: null,
			refreshConfigurationList: jest.fn(),
		}),
	}
})
jest.mock(
	'vue-material-design-icons/CloudUploadOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
/**
 * The @nextcloud/vue components as plain stubs: a <div data-nc="Name"> that
 * renders its slots and shows its props as attributes.
 *
 * @return {object}
 */
function mockNcStubs() {
	const { h } = jest.requireActual('vue')
	const stub = (name) => ({
		name,
		render() {
			return h(
				'div',
				{ 'data-nc': name },
				Object.values(this.$slots).map((slot) => slot()),
			)
		},
	})
	return {
		NcButton: stub('NcButton'),
		NcDialog: stub('NcDialog'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
}

jest.mock('@nextcloud/vue', () => ({ __esModule: true, ...mockNcStubs() }))
// vue3-jest compiles a component that has both <script setup> and <script>
// so that its @nextcloud/vue imports are read off the `vue` module binding.
// Exposing the same stubs there renders such a component as the build does.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockNcStubs() }))

import {
	configurationStore,
	navigationStore,
	registerStore,
} from '../store/store.js'

const REPOS = [
	{ full_name: 'acme/site', owner: 'acme', name: 'site', private: false },
	{ full_name: 'acme/secret', owner: 'acme', name: 'secret', private: true },
]

/**
 * Let queued promise callbacks run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve()
	}
}

/**
 * A fetch that answers per URL and records every call.
 *
 * @param {object} script What to answer: repositories, branches, publish (ok or error).
 * @return {Function}
 */
function scriptedFetch(script) {
	return jest.fn(async (url) => {
		const json = (ok, body) => ({ ok, json: async () => body })
		if (url.includes('/github/repositories')) {
			return script.repositoriesFail
				? json(false, { error: 'no token' })
				: json(true, { repositories: REPOS })
		}

		if (url.includes('/github/branches')) {
			return script.branchesFail
				? json(false, {})
				: json(true, {
						branches: script.branches ?? [
							{ name: 'dev' },
							{ name: 'master' },
						],
					})
		}

		return script.publishFail
			? json(
					false,
					script.publishFail === 'empty' ? {} : { error: 'push rejected' },
				)
			: json(true, { commit_sha: 'abcdef123456', indexing_note: script.note })
	})
}

/**
 * The markup without scoped-style attributes.
 *
 * @param {object} wrapper The mounted modal.
 * @return {string}
 */
function markup(wrapper) {
	return wrapper.html().replace(/ data-v-[0-9a-f]+(="")?/g, '')
}

/**
 * The component that holds the modal's state: the publish form itself.
 *
 * @param {object} wrapper The mounted modal.
 * @return {object}
 */
function state(wrapper) {
	const form = wrapper.findComponent({ name: 'PublishToGithub' })
	return form.exists() ? form.vm : wrapper.vm
}

/**
 * Everything a scenario observes.
 *
 * @param {object} wrapper The mounted modal.
 * @return {object}
 */
function observe(wrapper) {
	const vm = state(wrapper).$data
	return {
		html: markup(wrapper),
		fetch: global.fetch.mock.calls,
		setModal: navigationStore.setModal.mock.calls,
		refreshRegisters: registerStore.refreshRegisterList.mock.calls.length,
		refreshConfigurations:
			configurationStore.refreshConfigurationList.mock.calls.length,
		state: {
			selectedRepository: vm.selectedRepository,
			selectedBranch: vm.selectedBranch,
			filePath: vm.filePath,
			commitMessage: vm.commitMessage,
			error: vm.error,
			success: vm.success,
			successMessage: vm.successMessage,
			loading: vm.loading,
		},
	}
}

const MODALS = {
	register: {
		component: PublishRegister,
		modal: 'publishRegister',
		setItem: (item) => {
			registerStore.registerItem = item
		},
		item: {
			id: 3,
			title: 'Parks',
			slug: 'parks',
			githubRepo: 'acme/site',
			githubBranch: 'dev',
			githubPath: 'x.json',
		},
	},
	configuration: {
		component: PublishConfiguration,
		modal: 'publishConfiguration',
		setItem: (item) => {
			configurationStore.configurationItem = item
		},
		item: {
			id: 8,
			title: 'Base',
			slug: 'base',
			githubRepo: 'acme/site',
			githubBranch: 'dev',
			githubPath: 'lib/settings/base.json',
		},
	},
}

const SCENARIOS = {
	'opens and loads repositories': {},
	'item without slug or published repo': { item: { id: 5, title: 'Plain' } },
	'item published to an unknown repo': {
		item: { id: 5, title: 'Gone', slug: 'gone', githubRepo: 'acme/other' },
	},
	'no item': { item: null },
	'closed modal': { modal: 'other' },
	'repositories fail': { repositoriesFail: true },
	'pick a repository': { pick: 'acme/secret' },
	'pick a repository object with main branch': {
		pick: { value: 'acme/secret' },
		branches: [{ name: 'x' }, { name: 'main' }],
	},
	'pick a repository without branches': { pick: 'acme/secret', branches: [] },
	'branches fail': { pick: 'acme/secret', branchesFail: true },
	'clear the repository': { pick: null },
	publish: { pick: 'acme/site', publish: true },
	'publish with note and empty commit message': {
		pick: 'acme/site',
		commit: '',
		note: 'Indexed in an hour.',
		publish: true,
	},
	'publish fails': { pick: 'acme/site', publish: true, publishFail: true },
	'publish fails without message': {
		pick: 'acme/site',
		publish: true,
		publishFail: 'empty',
	},
	'publish without a path': { pick: 'acme/site', path: '  ', publish: true },
	close: { close: true },
	'close while loading': { close: true, loading: true },
}

describe.each(Object.keys(MODALS))('%s publish modal', (kind) => {
	const modal = MODALS[kind]

	afterEach(() => {
		jest.useRealTimers()
		jest.clearAllMocks()
	})

	it.each(Object.keys(SCENARIOS))('%s', async (name) => {
		const scenario = SCENARIOS[name]
		jest.useFakeTimers()
		global.fetch = scriptedFetch(scenario)
		navigationStore.modal = scenario.modal ?? modal.modal
		modal.setItem('item' in scenario ? scenario.item : { ...modal.item })

		const wrapper = mount(modal.component, {
			global: {
				mocks: {
					navigationStore,
					t: (app, text) => text,
					n: (app, one, many, count) => (count === 1 ? one : many),
				},
			},
		})
		await flush()
		const steps = [observe(wrapper)]

		if ('pick' in scenario) {
			state(wrapper).$data.selectedRepository = scenario.pick
			await flush()
			steps.push(observe(wrapper))
		}

		if ('path' in scenario) {
			state(wrapper).$data.filePath = scenario.path
		}

		if ('commit' in scenario) {
			state(wrapper).$data.commitMessage = scenario.commit
		}

		if (scenario.publish) {
			if (!state(wrapper).$data.filePath && !('path' in scenario)) {
				state(wrapper).$data.filePath = 'out/openapi.json'
			}

			await wrapper.findAll('[data-nc="NcButton"]')[0].trigger('click')
			await flush()
			steps.push(observe(wrapper))
			jest.advanceTimersByTime(4000)
			steps.push(observe(wrapper))
		}

		if (scenario.close) {
			state(wrapper).$data.loading = scenario.loading === true
			state(wrapper).closeModal()
			steps.push(observe(wrapper))
		}

		expect(steps).toMatchSnapshot()
		wrapper.unmount()
	})
})
