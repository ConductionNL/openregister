/**
 * Golden record of the agent limits dialog: the two selects it renders, the
 * dialog shell around them, what saving sends and what a refused save shows.
 *
 * @spec exclude Golden record of client-side dialog markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import EditAgentLimits from './EditAgentLimits.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		`t:${text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? vars[k] : m))}`,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars) =>
		path.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { patch: jest.fn() } }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())

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
 * A stand-in for a component: an `x-<name>` element with its props as
 * attributes and every slot rendered, a form slot with empty form bindings.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	const text = (value) =>
		typeof value === 'object' || typeof value === 'function'
			? JSON.stringify(value)
			: value
	return {
		name,
		inheritAttrs: false,
		methods: {
			setValidationErrors(fields, message) {
				globalThis.dialogCalls.push(['setValidationErrors', fields, message])
			},
			setResult(result) {
				globalThis.dialogCalls.push(['setResult', result])
			},
		},
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [key, text(value)]),
			)
			const form = { fields: [], formData: {}, errors: {}, updateField: () => {} }
			return render(
				`x-${name}`,
				attrs,
				Object.entries(this.$slots).map(([slot, fill]) =>
					render(`x-slot-${slot}`, {}, fill(form)),
				),
			)
		},
	}
}

const PROPS = {
	agent: { id: 7, name: 'Helper', tools: ['a', 'gone'], views: ['v-open'] },
	toolOptions: [
		{ id: 'a', label: 'Tool A' },
		{ id: 'b', label: 'Tool B' },
	],
	viewOptions: [
		{ id: 'v-open', label: 'Open cases' },
		{ id: 'v-closed', label: 'Closed cases' },
	],
}

/**
 * Mount the dialog for the recorded agent.
 *
 * @return {object} The wrapper
 */
function mountDialog() {
	return mount(EditAgentLimits, { props: PROPS })
}

beforeEach(() => {
	globalThis.dialogCalls = []
	axios.patch.mockReset()
})

describe('EditAgentLimits golden record', () => {
	it('renders the recorded selects', () => {
		const selects = mountDialog()
			.findAll('x-ncselect')
			.map((el) => el.html())
		expect(selects).toMatchSnapshot()
	})

	it('renders the recorded dialog', () => {
		expect(mountDialog().html()).toMatchSnapshot()
	})

	it('saves the chosen tools and views on the agent', async () => {
		axios.patch.mockResolvedValue({ data: { id: 7, tools: ['a'] } })
		const wrapper = mountDialog()
		wrapper.vm.selectedTools = [PROPS.toolOptions[1]]
		await wrapper.vm.save()
		await flushPromises()
		expect(axios.patch.mock.calls).toEqual([
			['/apps/openregister/api/agents/7', { tools: ['b'], views: ['v-open'] }],
		])
		expect(wrapper.emitted()).toEqual({ saved: [[{ id: 7, tools: ['a'] }]] })
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('shows the refusal and keeps the dialog open when a save is refused', async () => {
		axios.patch.mockRejectedValue({ response: { data: { error: 'No access' } } })
		const wrapper = mountDialog()
		await wrapper.vm.save()
		await flushPromises()
		expect(wrapper.vm.error).toBe('No access')
		expect(wrapper.emitted().saved).toBeUndefined()
		expect(wrapper.text()).toContain('No access')
	})

	it('falls back to its own message when the refusal carries none', async () => {
		axios.patch.mockRejectedValue(new Error('network'))
		const wrapper = mountDialog()
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('t:Could not save the limits')
	})
})
