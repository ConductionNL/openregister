import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
/**
 * Golden record of the import, export, upload and download dialogs: their
 * markup while open, after a refusal and after success, so a move onto a
 * shared dialog shows every change it makes to what a person sees.
 *
 * DownloadObject is not recorded: its editor binds `objectItem.object`, which
 * the component never defines, so it cannot render outside a success.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ReimportDescriptorDialog from '../dialogs/settings/ReimportDescriptorDialog.vue'
import ExportConfiguration from './configuration/ExportConfiguration.vue'
import UploadObject from './object/UploadObject.vue'
import ExportRegister from './register/ExportRegister.vue'
import UploadSchema from './schema/UploadSchema.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		`t:${text}${vars ? ' ' + JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/axios', () => jest.fn(() => Promise.resolve({})))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The real CnDialog and CnDialogActions, drawn with the stand-ins above, so
// the golden shows what the shared frame and footer really render.
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDialog: jest.requireActual(
		'@conduction/nextcloud-vue/dist/esm/components/CnDialog/CnDialog.vue.js',
	).default,
	CnDialogActions: jest.requireActual(
		'@conduction/nextcloud-vue/dist/esm/components/CnDialogActions/CnDialogActions.vue.js',
	).default,
}))
jest.mock(
	'@conduction/nextcloud-vue/dist/esm/components/CnIcon/CnIcon.vue.js',
	() => mockModule('CnIcon'),
)
jest.mock(
	'@conduction/nextcloud-vue/dist/esm/components/CnDialogHeader/CnDialogHeader.vue.js',
	() => mockModule('CnDialogHeader'),
)
jest.mock('vue-codemirror6', () => mockModule('CodeMirror'))
jest.mock('@codemirror/lang-json', () => ({
	json: () => 'json',
	jsonParseLinter: () => 'linter',
}))
jest.mock('../services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockModule('ArrowLeft'))
jest.mock('vue-material-design-icons/AutoFix.vue', () => mockModule('AutoFix'))
jest.mock('vue-material-design-icons/Cancel.vue', () => mockModule('Cancel'))
jest.mock('vue-material-design-icons/Export.vue', () => mockModule('Export'))
jest.mock('vue-material-design-icons/Upload.vue', () => mockModule('Upload'))
jest.mock('../store/store.js', () => ({}))

const stores = jest.requireMock('../store/store.js')

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
 * A stand-in default-export module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
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
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [
						key,
						typeof value === 'function' ? '[fn]' : value,
					]),
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

/**
 * Stores whose unknown members are logged calls answered by `reply`.
 *
 * @param {Array} log The log of store calls
 * @param {object} fields The store fields by store name
 * @param {Function} reply The answer to a store call
 * @return {void}
 */
function resetStores(log, fields, reply) {
	const names = [
		'configurationStore',
		'navigationStore',
		'objectStore',
		'registerStore',
		'schemaStore',
	]
	names.forEach((store) => {
		stores[store] = new Proxy(fields[store] || {}, {
			get(target, prop) {
				if (prop in target) return target[prop]
				if (typeof prop !== 'string' || prop === 'then') return undefined
				return (...args) => {
					log.push([`${store}.${prop}`, ...args])
					return reply(prop)
				}
			},
		})
	})
}

const DIALOGS = {
	exportRegister: {
		component: ExportRegister,
		modal: 'exportRegister',
		fields: {
			registerStore: { registerItem: { id: 1, slug: 'reg', title: 'Reg' } },
			schemaStore: { schemaItem: { id: 2, slug: 'sch', title: 'Sch' } },
		},
	},
	exportConfiguration: {
		component: ExportConfiguration,
		modal: 'exportConfiguration',
		fields: {
			configurationStore: { configurationItem: { id: 4, title: 'Conf' } },
		},
	},
	uploadSchema: {
		component: UploadSchema,
		modal: 'uploadSchema',
		fields: { schemaStore: { schemaItem: null } },
	},
	uploadObject: {
		component: UploadObject,
		modal: 'uploadObject',
		fields: {
			objectStore: { objectItem: null },
			registerStore: { registerList: [{ id: 1, title: 'Reg' }] },
			schemaStore: { schemaList: [{ id: 2, title: 'Sch' }] },
		},
	},
}

/**
 * The answer to a store call: two mappings for UploadObject, else ok.
 *
 * @param {string} prop The store member called
 * @return {Promise<object>} The answer
 */
function answer(prop) {
	return Promise.resolve(
		prop === 'getMappings' ? { data: [{ id: 7, name: 'Map' }] } : { ok: true },
	)
}

/**
 * Mount a dialog with its setup bindings supplied.
 *
 * @param {object} entry The table entry
 * @param {Function} reply The answer to a store call
 * @return {Promise<object>} The wrapper and the call log
 */
async function open(entry, reply = answer) {
	const { translate, translatePlural } = jest.requireMock('@nextcloud/l10n')
	const log = []
	const navigation = reactive({
		modal: entry.modal,
		setModal(value) {
			log.push(['navigationStore.setModal', value])
			navigation.modal = value
		},
	})
	resetStores(log, { ...entry.fields, navigationStore: navigation }, reply)
	const bindings = {
		...entry.component.components,
		...stores,
		...jest.requireMock('@codemirror/lang-json'),
		...jest.requireMock('../services/getTheme.js'),
		n: translatePlural,
		t: translate,
	}
	const wrapper = mount({ ...entry.component, setup: () => bindings })
	await flushPromises()
	return { log, wrapper }
}

describe('import, export, upload and download dialogs (DOM golden)', () => {
	it.each(Object.keys(DIALOGS))('%s renders while open', async (name) => {
		const { wrapper } = await open(DIALOGS[name])
		expect(wrapper.html()).toMatchSnapshot()
	})

	it.each(Object.keys(DIALOGS))('%s renders a reported error', async (name) => {
		const { wrapper } = await open(DIALOGS[name])
		const key = 'error' in wrapper.vm.$data ? 'error' : 'errorMessage'
		wrapper.vm[key] = 'refused'
		await flushPromises()
		expect(wrapper.html()).toMatchSnapshot()
	})

	it.each(['uploadSchema', 'uploadObject'])(
		'%s renders a success',
		async (name) => {
			const { wrapper } = await open(DIALOGS[name])
			wrapper.vm.success = true
			await flushPromises()
			expect(wrapper.html()).toMatchSnapshot()
		},
	)

})

describe('ReimportDescriptorDialog (DOM golden)', () => {
	/**
	 * Mount the question for one descriptor row.
	 *
	 * @return {object} The wrapper
	 */
	function ask() {
		const { translate } = jest.requireMock('@nextcloud/l10n')
		return mount(ReimportDescriptorDialog, {
			props: { row: { appId: 'app', title: 'Reg' } },
			global: { mocks: { t: translate } },
		})
	}

	it('renders the question', () => {
		expect(ask().html()).toMatchSnapshot()
	})

	it('cancels on its buttons and on close, confirms on Re-import', async () => {
		const wrapper = ask()
		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		await buttons[0].vm.$emit('click')
		await buttons[1].vm.$emit('click')
		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')
		expect(Object.keys(wrapper.emitted())).toEqual(
			expect.arrayContaining(['cancel', 'confirm']),
		)
		expect(wrapper.emitted('cancel')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})
})
