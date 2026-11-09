/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden record of the mail sidebar's entities and objects tabs: their props,
 * the markup while loading, when empty and with results, the request each
 * makes for the open message and the reload when the message changes.
 *
 * @spec exclude Golden record of client-side mail sidebar markup and requests.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import EntitiesTab from './EntitiesTab.vue'
import ObjectsTab from './ObjectsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), delete: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		't:' + text.replace(/{(\w+)}/g, (m, key) => vars[key] ?? m),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h(
				'div',
				{ 'data-stub': name, ...this.$attrs },
				Object.values(this.$slots).map((slot) => slot()),
			)
		},
	})
	return {
		NcButton: stub('NcButton'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
	}
})
jest.mock('vue-material-design-icons/Close.vue', () => mockIcon('Close'))
jest.mock('vue-material-design-icons/LinkVariant.vue', () =>
	mockIcon('LinkVariant'),
)
jest.mock('vue-material-design-icons/Plus.vue', () => mockIcon('Plus'))
jest.mock('../icons.js', () => ({
	schemaIconComponent: (icon) => ({
		name: 'SchemaIcon',
		render: () => require('vue').h('i', { 'data-icon': icon || 'default' }),
	}),
}))
jest.mock('../../dialogs/mail/RemoveConnectionDialog.vue', () => ({
	name: 'RemoveConnectionDialog',
	props: ['show', 'name', 'removing'],
	render: () => null,
}))

/**
 * A stand-in icon module.
 *
 * @param {string} name The icon
 * @return {object} The module
 */
function mockIcon(name) {
	return {
		__esModule: true,
		default: {
			name,
			render: () => require('vue').h('i', { 'data-icon': name }),
		},
	}
}

/**
 * A promise that never settles, to hold a tab in its loading state.
 *
 * @return {Promise} The pending promise
 */
function pending() {
	return new Promise(() => {})
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('mail tabs props', () => {
	it.each([
		['EntitiesTab', EntitiesTab],
		['ObjectsTab', ObjectsTab],
	])('%s takes a nullable numeric account and message id', (_name, tab) => {
		expect(tab.props).toEqual({
			accountId: { type: Number, default: null },
			messageId: { type: Number, default: null },
		})
	})
})

describe('EntitiesTab golden', () => {
	it('renders the empty state without a message and makes no request', async () => {
		const wrapper = mount(EntitiesTab, { props: { accountId: 1 } })
		await flushPromises()
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('renders the loading state while the body loads', async () => {
		axios.get.mockImplementation(pending)
		const wrapper = mount(EntitiesTab, {
			props: { accountId: 1, messageId: 7 },
		})
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/mail/api/messages/7/body',
			{ timeout: 10000 },
		)
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('renders grouped entities and reloads when the message changes', async () => {
		axios.get.mockResolvedValue({
			data: {
				data: {
					from: [{ email: 'a@example.com', label: 'Alice' }],
					body: 'Call +31 6 12345678 or see https://example.com/x',
				},
			},
		})
		const wrapper = mount(EntitiesTab, {
			props: { accountId: 1, messageId: 7 },
		})
		await flushPromises()
		expect(wrapper.html()).toMatchSnapshot()

		await wrapper.setProps({ messageId: 8 })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).toHaveBeenLastCalledWith(
			'/index.php/apps/mail/api/messages/8/body',
			{ timeout: 10000 },
		)
	})
})

describe('ObjectsTab golden', () => {
	it('renders the empty state and counts zero without an account', async () => {
		const wrapper = mount(ObjectsTab, { props: { messageId: 7 } })
		await flushPromises()
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.emitted('count')).toEqual([[0]])
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('renders the loading state while the links load', async () => {
		axios.get.mockImplementation(pending)
		const wrapper = mount(ObjectsTab, {
			props: { accountId: 1, messageId: 7 },
		})
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/linked/mail/1/7',
			{ timeout: 10000 },
		)
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('renders the linked objects and reloads when the message changes', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{
						uuid: 'u-1',
						name: '{"nl":"Zaak 1"}',
						schema: 'Zaken',
						register: 2,
						schemaId: 5,
					},
					{
						uuid: 'u-2',
						name: 'Lead 2',
						schema: 'Leads',
						url: '/apps/pipelinq/leads/u-2',
					},
				],
			},
		})
		const wrapper = mount(ObjectsTab, {
			props: { accountId: 1, messageId: 7 },
		})
		await flushPromises()
		expect(wrapper.emitted('count')).toEqual([[2]])
		expect(wrapper.html()).toMatchSnapshot()

		await wrapper.setProps({ messageId: 8 })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).toHaveBeenLastCalledWith(
			'/index.php/apps/openregister/api/linked/mail/1/8',
			{ timeout: 10000 },
		)
		expect(wrapper.emitted('count')).toEqual([[2], [2]])
	})
})
