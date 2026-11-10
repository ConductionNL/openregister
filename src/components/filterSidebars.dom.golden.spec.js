/**
 * Golden record of the Files and Webhooks filter sidebars: their markup in
 * each filter state, the events each radio, the search field and the clear
 * button emit, and how a changed prop moves the selection.
 *
 * @spec exclude Golden record of client-side filter sidebar markup and events.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import FilesSidebar from './FilesSidebar.vue'
import WebhooksSidebar from './WebhooksSidebar.vue'

jest.mock('@nextcloud/l10n', () => ({
	t: (app, text) => `t:${text}`,
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/Magnify.vue', () => ({
	__esModule: true,
	default: mockStub('Magnify'),
}))

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
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
 * A stand-in for a component: its tag, plain attributes and slots. Listeners
 * stay reachable through the vnode props so the golden can fire them.
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
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(([key]) => !/^on[A-Z]/.test(key)),
			)
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

const PAGES = {
	files: {
		component: FilesSidebar,
		props: { search: '', status: null, riskLevel: null },
		changed: { status: 'failed', riskLevel: 'high' },
		events: ['update:search', 'update:status', 'update:riskLevel'],
	},
	webhooks: {
		component: WebhooksSidebar,
		props: { search: '', enabled: null },
		changed: { enabled: false },
		events: ['update:search', 'update:enabled'],
	},
}

/**
 * Fire a stub's listener the way the library component would.
 *
 * @param {object} stub The stub wrapper
 * @param {string} event The event, e.g. 'update:modelValue'
 * @param {*} value The payload
 * @return {Promise<void>}
 */
async function fire(stub, event, value) {
	stub.vm.$emit(event, value)
	await stub.vm.$nextTick()
}

/**
 * The sidebar's markup without comment nodes: template comments and fragment
 * anchors only exist in development builds.
 *
 * @param {object} wrapper The sidebar
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * The radios of the sidebar with their checked state.
 *
 * @param {object} wrapper The sidebar
 * @return {Array<string>} One line per radio
 */
function radios(wrapper) {
	return wrapper
		.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		.map(
			(radio) =>
				`${radio.attributes('value')}=${radio.attributes('modelvalue')}`,
		)
}

describe('filter sidebars golden', () => {
	beforeEach(() => jest.useFakeTimers())
	afterEach(() => jest.useRealTimers())

	for (const [page, setup] of Object.entries(PAGES)) {
		it(`${page}: renders without filters`, () => {
			const wrapper = mount(setup.component, { props: setup.props })
			expect(markup(wrapper).length).toBeGreaterThan(0)
			expect(markup(wrapper)).toMatchSnapshot()
		})

		it(`${page}: renders with filters from the props`, () => {
			const wrapper = mount(setup.component, {
				props: { ...setup.props, ...setup.changed, search: 'abc' },
			})
			expect(markup(wrapper)).toMatchSnapshot()
		})

		it(`${page}: emits what the radios, search and clear do`, async () => {
			const order = []
			const listeners = Object.fromEntries(
				setup.events.map((event) => [
					`on${event[0].toUpperCase()}${event.slice(1)}`,
					(value) => order.push([event, value]),
				]),
			)
			const wrapper = mount(setup.component, {
				props: { ...setup.props, ...listeners },
			})
			const states = []
			const all = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
			for (let index = 0; index < all.length; index++) {
				await fire(
					wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[
						index
					],
					'update:modelValue',
					true,
				)
				states.push(radios(wrapper).join(' '))
			}
			const search = wrapper.findComponent({ name: 'NcTextField' })
			await fire(search, 'update:modelValue', 'fir')
			await fire(search, 'update:modelValue', 'first')
			jest.advanceTimersByTime(499)
			states.push(
				`before debounce: ${JSON.stringify(wrapper.emitted('update:search') ?? null)}`,
			)
			jest.advanceTimersByTime(1)
			await wrapper.vm.$nextTick()
			states.push(`clear shown: ${wrapper.find('.clear-filters').exists()}`)
			await fire(wrapper.findComponent({ name: 'NcButton' }), 'click')
			states.push(radios(wrapper).join(' '))
			states.push(`clear shown: ${wrapper.find('.clear-filters').exists()}`)
			expect(states).toMatchSnapshot()
			expect(order).toMatchSnapshot()
			expect(wrapper.emitted()).toMatchSnapshot()
		})

		it(`${page}: follows changed props`, async () => {
			const wrapper = mount(setup.component, { props: setup.props })
			await wrapper.setProps({ ...setup.changed, search: 'typed' })
			const after = [radios(wrapper).join(' '), markup(wrapper)]
			await wrapper.setProps(setup.props)
			after.push(radios(wrapper).join(' '))
			expect(after).toMatchSnapshot()
			expect(wrapper.emitted()).toMatchSnapshot()
		})
	}
})
