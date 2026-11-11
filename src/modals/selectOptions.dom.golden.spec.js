/**
 * Golden record of the option markup the configuration and webhook edit
 * modals render inside their selects, for an option with and without a
 * description.
 *
 * @spec exclude Golden record of client-side select option markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import EditConfiguration from './configuration/EditConfiguration.vue'
import EditWebhook from './webhook/EditWebhook.vue'

jest.mock('../store/store.js', () => ({
	__esModule: true,
	applicationStore: { applicationList: [], refreshApplicationList: () => {} },
	configurationStore: { configurationItem: null },
	navigationStore: {
		modal: 'editConfiguration',
		setModal: () => {},
		getTransferData: () => null,
	},
	organisationStore: { organisationList: [], refreshOrganisationList: () => {} },
	webhookStore: { webhookItem: null, saveWebhook: () => {} },
}))
jest.mock('@nextcloud/axios', () => {
	const answer = () => Promise.resolve({ data: { results: [] } })
	return { __esModule: true, default: { get: answer, post: answer } }
})
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{},
			{
				get: (target, key) =>
					key === '__esModule'
						? true
						: { name: String(key), render: () => null },
			},
		),
)
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	__esModule: true,
	default: { name: 'Cancel', render: () => null },
}))
jest.mock('vue-material-design-icons/CloudSync.vue', () => ({
	__esModule: true,
	default: { name: 'CloudSync', render: () => null },
}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({
	__esModule: true,
	default: { name: 'Cog', render: () => null },
}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({
	__esModule: true,
	default: { name: 'ContentSave', render: () => null },
}))
jest.mock('vue-material-design-icons/Database.vue', () => ({
	__esModule: true,
	default: { name: 'Database', render: () => null },
}))
jest.mock('vue-material-design-icons/Tune.vue', () => ({
	__esModule: true,
	default: { name: 'Tune', render: () => null },
}))
jest.mock('vue-material-design-icons/Webhook.vue', () => ({
	__esModule: true,
	default: { name: 'Webhook', render: () => null },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: () => {},
	showSuccess: () => {},
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	t: (app, text) => text,
}))

const OPTIONS = [
	{ title: 'A title', name: 'A name', label: 'A label', description: 'Told' },
	{ title: 'B title', name: 'B name', label: 'B label', description: '' },
]

const SELECT = {
	name: 'NcSelect',
	setup(props, { slots }) {
		const { h: render } = require('vue')
		return () =>
			render(
				'x-select',
				OPTIONS.map((option) => render('x-option', slots.option?.(option))),
			)
	},
}

const PASS = {
	setup(props, { slots }) {
		const { h: render } = require('vue')
		return () => render('x-pass', slots.default?.())
	},
}

/**
 * A stand-in for a library component that renders nothing.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function blank(name) {
	return { name, render: () => null }
}

/**
 * The shared option component, as a binding, when the codebase has one.
 *
 * @return {object} The binding, or nothing
 */
function sharedOption() {
	try {
		return {
			SelectOption: require('../components/shared/SelectOption.vue').default,
		}
	} catch {
		return {}
	}
}

/**
 * The shared labelled form fields the modals render their selects with, as
 * bindings, when the codebase has them.
 *
 * @return {object} The bindings, or nothing
 */
function sharedFields() {
	try {
		return {
			FormSelectField: require('../components/shared/FormSelectField.vue').default,
			FormCheckboxField: require('../components/shared/FormCheckboxField.vue').default,
		}
	} catch {
		return {}
	}
}

/**
 * The configuration modal with its setup bindings supplied. Under jest the
 * compiled bindings of an SFC with both a setup and a plain script resolve
 * the library components to undefined, so they are given here.
 *
 * @return {object} The modal with stand-in bindings
 */
function configurationModal() {
	const stores = jest.requireMock('../store/store.js')
	const names = [
		'NcButton',
		'NcCheckboxRadioSwitch',
		'NcLoadingIcon',
		'NcNoteCard',
		'NcTextArea',
		'NcTextField',
		'Cancel',
		'CloudSync',
		'Cog',
		'ContentSave',
		'Database',
	]
	const bindings = {
		...Object.fromEntries(names.map((name) => [name, blank(name)])),
		NcSelect: SELECT,
		NcDialog: PASS,
		AppTabs: PASS,
		AppTab: PASS,
		t: (app, text) => text,
		...stores,
		...sharedOption(),
		...sharedFields(),
	}
	return { ...EditConfiguration, setup: () => bindings }
}

/**
 * The option markup of every select in a mounted modal.
 *
 * @param {object} component The modal
 * @return {Promise<string[]>} The option markup per select
 */
async function optionsOf(component) {
	jest.spyOn(console, 'error').mockImplementation(() => {})
	jest.spyOn(console, 'warn').mockImplementation(() => {})
	const wrapper = mount(component, {
		shallow: true,
		global: {
			stubs: {
				NcSelect: SELECT,
				NcDialog: PASS,
				AppTabs: PASS,
				AppTab: PASS,
				SelectOption: false,
				FormSelectField: false,
			},
			mocks: { t: (app, text) => text },
		},
	})
	await flushPromises()
	const html = wrapper
		.findAll('x-select')
		.map((select) => select.html().replace(/ data-v-[a-f0-9]+(="")?/g, ''))
	wrapper.unmount()
	return html
}

describe('edit modal select options DOM golden', () => {
	it('configuration', async () => {
		expect(await optionsOf(configurationModal())).toMatchSnapshot()
	})

	it('webhook', async () => {
		const { navigationStore } = jest.requireMock('../store/store.js')
		navigationStore.modal = 'editWebhook'
		expect(await optionsOf(EditWebhook)).toMatchSnapshot()
	})
})
