/**
 * Golden record of the data quality panels that list rows in a table: the
 * queue health page and the golden-record detail panel, in every state.
 *
 * @spec exclude Golden record of client-side quality table markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import GoldenRecordDetail from './GoldenRecordDetail.vue'
import QueueHealthIndex from './QueueHealthIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
jest.mock('vue-material-design-icons/AccountBoxOutline.vue', () =>
	mockIcon('AccountBoxOutline'),
)
jest.mock('vue-material-design-icons/Webhook.vue', () => mockIcon('Webhook'))
jest.mock('../../modals/mdm/MdmConflictResolutionModal.vue', () =>
	mockIcon('MdmConflictResolutionModal'),
)
jest.mock('../../store/store.js', () => mockStores())

/**
 * The quality store, logging every action called on it.
 *
 * @return {object} The module
 */
function mockStores() {
	const { reactive } = require('vue')
	const log = (globalThis.mockStoreLog = [])
	const state = reactive({})
	const qualityStore = new Proxy(state, {
		get(obj, key) {
			if (typeof key === 'string' && key.startsWith('fetch')) {
				return (...args) => {
					log.push([key, ...args])
					return Promise.resolve([])
				}
			}
			return obj[key]
		},
	})
	return { qualityStore }
}

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = (globalThis.madeStubs = globalThis.madeStubs || {})
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockIcon(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = {}
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (!/^on[A-Z]/.test(key) && typeof value !== 'function') {
					attrs[key] =
						typeof value === 'object' ? JSON.stringify(value) : value
				}
			}
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => render(`slot-${slot}`, this.$slots[slot]({}))),
			)
		},
	}
}

const { qualityStore } = jest.requireMock('../../store/store.js')

const QUEUE_STATES = {
	loading: { isLoading: true, webhooks: [], webhookFailures: [] },
	empty: { isLoading: false, webhooks: [], webhookFailures: [] },
	noFailures: {
		isLoading: false,
		webhooks: [
			{ id: 1, name: 'Hook one', url: 'https://one.test' },
			{ id: 2, url: 'https://two.test' },
		],
		webhookStats: { 1: { successful: 4, failed: 0, pendingRetries: 2 } },
		webhookFailures: [],
	},
	failures: {
		isLoading: false,
		webhooks: [{ id: 1, name: 'Hook one' }],
		webhookStats: { 1: { successful: 1, failed: 3 } },
		webhookFailures: [
			{
				id: 7,
				webhook: 1,
				eventClass: 'ObjectCreatedEvent',
				statusCode: 500,
				errorMessage: 'Server error',
				created: '2026-01-02T03:04:05+00:00',
			},
			{ webhook: 1, eventClass: 'ObjectUpdatedEvent' },
		],
	},
}

const GOLDEN_OBJECTS = {
	none: null,
	noProvenance: { id: 'g1', qualityScore: 80 },
	provenance: {
		id: 'g2',
		qualityScore: 91,
		qualityStatus: 'good',
		attributeProvenance: {
			name: { source: 'crm', confidence: 0.9, timestamp: '2026-01-01' },
			email: { winningSource: 'erp', at: '2026-02-02' },
			phone: { override: true, overriddenBy: 'admin', rationale: 'checked' },
			city: 'registry',
			street: {},
		},
	},
}

const mounted = []

/**
 * The markup without comment nodes: template comments and fragment anchors
 * only exist in development builds.
 *
 * @param {object} wrapper The component
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * Mount a component over a store state.
 *
 * @param {object} component The component
 * @param {object} state The store state
 * @param {object} props The props
 * @return {Promise<object>} The wrapper
 */
async function mountWith(component, state, props = {}) {
	for (const key of Object.keys(qualityStore)) {
		delete qualityStore[key]
	}
	Object.assign(qualityStore, { selectedRegister: 1, selectedSchema: 2 }, state)
	globalThis.mockStoreLog.length = 0
	const wrapper = mount(component, {
		props,
		global: {
			mocks: {
				t: (app, text, vars) =>
					`t:${text}${vars ? JSON.stringify(vars) : ''}`,
				n: (app, one) => one,
			},
		},
	})
	await flushPromises()
	mounted.push(wrapper)
	return wrapper
}

describe('quality tables golden', () => {
	afterEach(() => {
		while (mounted.length) {
			mounted.pop().unmount()
		}
	})

	for (const [stateName, state] of Object.entries(QUEUE_STATES)) {
		it(`queue health: renders ${stateName}`, async () => {
			const wrapper = await mountWith(QueueHealthIndex, state)
			expect({
				markup: markup(wrapper),
				mounted: globalThis.mockStoreLog,
			}).toMatchSnapshot()
		})
	}

	for (const [name, object] of Object.entries(GOLDEN_OBJECTS)) {
		it(`golden record: renders ${name}`, async () => {
			const wrapper = await mountWith(GoldenRecordDetail, {}, { object })
			expect(markup(wrapper)).toMatchSnapshot()
		})
	}
})
