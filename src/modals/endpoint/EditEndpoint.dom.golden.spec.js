/**
 * Golden record of the endpoint edit dialog: the fields it renders, the
 * dialog shell around them, and what saving a new and a stored endpoint does.
 *
 * @spec exclude Golden record of client-side dialog markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import EditEndpoint from './EditEndpoint.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({
	__esModule: true,
	default: { name: 'ContentSaveOutline', render: () => null },
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	__esModule: true,
	default: { name: 'Cancel', render: () => null },
}))
jest.mock('../../store/store.js', () => ({
	endpointStore: {
		endpointItem: {},
		createEndpoint: jest.fn(),
		updateEndpoint: jest.fn(),
	},
	navigationStore: { modal: 'editEndpoint', setModal: jest.fn() },
}))

const { endpointStore, navigationStore } = jest.requireMock('../../store/store.js')

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

const STORED = {
	id: 4,
	name: 'Cases',
	description: 'D',
	endpoint: '/api/cases',
	method: 'GET',
	targetType: 'view',
	targetId: '12',
	version: '1.0.0',
	inputMapping: '',
	outputMapping: '',
}

beforeEach(() => {
	globalThis.dialogCalls = []
	globalThis.OCP = { Toast: { success: jest.fn(), error: jest.fn() } }
	navigationStore.setModal.mockReset()
	endpointStore.createEndpoint.mockReset()
	endpointStore.updateEndpoint.mockReset()
})

/**
 * Mount the dialog over the given store item.
 *
 * @param {object} item The endpoint in the store
 * @return {object} The wrapper
 */
function mountDialog(item) {
	endpointStore.endpointItem = { ...item }
	return mount(withComponentBindings(EditEndpoint))
}

/**
 * The component with its registered components added to its setup
 * bindings. Jest compiles a `<script setup>` template in non-inline mode,
 * where a component imported by the plain `<script>` block is read from the
 * setup bindings, which only the inline (webpack) build has in scope.
 *
 * @param {object} component The component
 * @return {object} The component, with the bindings
 */
function withComponentBindings(component) {
	return {
		...component,
		setup: (props, ctx) => ({
			...component.setup(props, ctx),
			...component.components,
		}),
	}
}

describe('EditEndpoint golden record', () => {
	it('renders the recorded fields', () => {
		const fields = mountDialog(STORED)
			.findAll('x-nctextfield, x-nctextarea, x-ncselect')
			.map((el) => el.html())
		expect(fields).toMatchSnapshot()
	})

	it('renders the recorded dialog for a stored endpoint', () => {
		expect(mountDialog(STORED).html()).toMatchSnapshot()
	})

	it('renders the recorded dialog for a new endpoint', () => {
		expect(mountDialog({}).html()).toMatchSnapshot()
	})

	it('updates a stored endpoint, closes and toasts', async () => {
		endpointStore.updateEndpoint.mockResolvedValue({})
		const wrapper = mountDialog(STORED)
		wrapper.vm.saveEndpoint()
		await flushPromises()
		expect(endpointStore.updateEndpoint.mock.calls).toEqual([[STORED]])
		expect(endpointStore.createEndpoint).not.toHaveBeenCalled()
		expect(navigationStore.setModal.mock.calls).toEqual([[false]])
		expect(globalThis.OCP.Toast.success.mock.calls).toEqual([['Endpoint updated successfully']])
	})

	it('creates a new endpoint, closes and toasts', async () => {
		endpointStore.createEndpoint.mockResolvedValue({})
		const wrapper = mountDialog({ name: 'N', endpoint: '/n', method: 'GET', targetType: 'view' })
		wrapper.vm.saveEndpoint()
		await flushPromises()
		expect(endpointStore.createEndpoint).toHaveBeenCalledTimes(1)
		expect(navigationStore.setModal.mock.calls).toEqual([[false]])
		expect(globalThis.OCP.Toast.success.mock.calls).toEqual([['Endpoint created successfully']])
	})

	it('keeps the dialog open and toasts the error when a save fails', async () => {
		endpointStore.updateEndpoint.mockRejectedValue(new Error('boom'))
		const wrapper = mountDialog(STORED)
		wrapper.vm.saveEndpoint()
		await flushPromises()
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		expect(globalThis.OCP.Toast.error.mock.calls).toEqual([['Error updating endpoint: boom']])
	})
})
