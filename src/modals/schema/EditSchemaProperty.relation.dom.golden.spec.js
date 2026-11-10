/**
 * Golden record of the rendered DOM of EditSchemaProperty's relation fields:
 * the object configuration of an object property and of an array of objects,
 * empty and filled.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import EditSchemaProperty from './EditSchemaProperty.vue'
import { schemaStore } from '../../store/store.js'

const NC = [
	'NcButton', 'NcCheckboxRadioSwitch', 'NcDateTimePicker', 'NcDialog', 'NcInputField',
	'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField',
]

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	t: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/vue', () => Object.fromEntries([
	'NcButton', 'NcCheckboxRadioSwitch', 'NcDateTimePicker', 'NcDialog', 'NcInputField',
	'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField',
].map((name) => [name, mockStub(name)])))
// As in viewModals.dom.golden.spec.js, the library components are stubbed on
// 'vue' as well, or the mixed <script setup> file renders nothing.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	...Object.fromEntries([
		'NcButton', 'NcCheckboxRadioSwitch', 'NcDateTimePicker', 'NcDialog', 'NcInputField',
		'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField',
	].map((name) => [name, mockStub(name)])),
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: () => Promise.resolve({ data: {} }) },
}))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (p) => p }))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: () => {}, setDialog: () => {} },
	registerStore: { registerList: [], refreshRegisterList: () => Promise.resolve() },
	schemaStore: {
		schemaPropertyKey: null,
		schemaItem: { title: 'S', properties: {} },
		schemaList: [],
		refreshSchemaList: () => Promise.resolve(),
	},
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({ __esModule: true, default: mockStub('Cancel') }))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({ __esModule: true, default: mockStub('ContentSaveOutline') }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: mockStub('Plus') }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ __esModule: true, default: mockStub('CloseCancelButton') }))

/**
 * A stand-in that renders its attributes and slots.
 *
 * @param {string} name The component name.
 * @return {object} The stand-in.
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const plain = Object.fromEntries(
					Object.entries(attrs)
						.filter(([key]) => !/^on[A-Z]/.test(key))
						.map(([key, value]) => [key, typeof value === 'object' && value !== null ? JSON.stringify(value) : value]),
				)
				return render(
					`x-${name}`,
					plain,
					Object.entries(slots).map(([slot, fill]) =>
						render(`slot-${slot}`, fill({})),
					),
				)
			}
		},
	}
}

/**
 * The markup without scoped-style ids and comment nodes.
 *
 * @param {string} html The markup.
 * @return {string} The cleaned markup.
 */
function clean(html) {
	return html.replace(/ data-v-[a-z0-9]+(="")?/g, '').replace(/<!--[\s\S]*?-->/g, '')
}

const RELATION = {
	register: 'r1',
	$ref: 's1',
	inversedBy: 'owner',
	writeBack: true,
	removeAfterWriteBack: false,
}

/**
 * Mount the dialog on a stored property and return one block's markup.
 *
 * @param {object} property The stored property definition.
 * @param {Function} pick Picks the block from the mounted wrapper.
 * @return {Promise<string>} The block's markup.
 */
async function render(property, pick) {
	schemaStore.schemaPropertyKey = 'p'
	schemaStore.schemaItem = { title: 'S', properties: { p: property } }
	const wrapper = mount(EditSchemaProperty, {
		global: { stubs: Object.fromEntries(NC.map((name) => [name, mockStub(name)])) },
	})
	await flushPromises()
	const block = pick(wrapper)
	const html = block ? clean(block.outerHTML) : 'absent'
	wrapper.unmount()
	return html
}

/**
 * The array-of-objects block: the parent of its title.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {Element|undefined} The block.
 */
function arrayBlock(wrapper) {
	const title = wrapper.findAll('.objectConfigurationTitle').find((node) => node.text().includes('Array'))
	return title?.element.parentElement
}

describe('EditSchemaProperty relation fields (DOM golden)', () => {
	it.each([
		['object, empty', { type: 'object' }],
		['object, filled', { type: 'object', ...RELATION, objectConfiguration: { handling: 'related-object', queryParams: 'a=b' } }],
		['object, write-back removes', { type: 'object', ...RELATION, removeAfterWriteBack: true }],
		['object, no write-back', { type: 'object', ...RELATION, writeBack: false }],
	])('%s', async (label, property) => {
		const html = await render(property, (wrapper) => wrapper.find('.objectConfigurationContainer').element)
		expect(html).not.toBe('absent')
		expect(html).toMatchSnapshot()
	})

	it.each([
		['array, empty', { type: 'array', items: { type: 'object' } }],
		['array, filled', { type: 'array', objectConfiguration: { handling: 'related-object' }, items: { type: 'object', ...RELATION, objectConfiguration: { queryParams: 'a=b' }, cascadeDelete: true } }],
		['array, write-back removes', { type: 'array', items: { type: 'object', ...RELATION, removeAfterWriteBack: true } }],
		['array, no write-back', { type: 'array', items: { type: 'object', ...RELATION, writeBack: false } }],
	])('%s', async (label, property) => {
		const html = await render(property, arrayBlock)
		expect(html).not.toBe('absent')
		expect(html).toMatchSnapshot()
	})
})

describe('EditSchemaProperty relation fields (writes)', () => {
	it('writes each field a relation block changes into the property form', async () => {
		schemaStore.schemaPropertyKey = 'p'
		schemaStore.schemaItem = { title: 'S', properties: { p: { type: 'array', items: { type: 'object', ...RELATION } } } }
		const wrapper = mount(EditSchemaProperty, {
			global: { stubs: Object.fromEntries(NC.map((name) => [name, mockStub(name)])) },
		})
		await flushPromises()
		const block = wrapper.findComponent({ name: 'SchemaRelationFields' })
		const vm = wrapper.vm
		block.vm.$emit('set', vm.properties.items, 'writeBack', false)
		block.vm.$emit('set', vm.properties.objectConfiguration, 'handling', 'uri')
		block.vm.$emit('set', vm.properties.items.objectConfiguration, 'queryParams', 'x=y')
		const afterSet = {
			writeBack: vm.properties.items.writeBack,
			handling: vm.properties.objectConfiguration.handling,
			queryParams: vm.properties.items.objectConfiguration.queryParams,
		}
		block.vm.$emit('inversedByChange', { value: 'other' })
		const afterInversed = {
			inversedBy: vm.properties.inversedBy,
			items: vm.properties.items.inversedBy,
			queryParams: vm.properties.items.objectConfiguration.queryParams,
		}
		block.vm.$emit('schemaChange', { id: 's2' })
		const afterSchema = { ref: vm.properties.$ref, items: vm.properties.items.$ref, inversedBy: vm.properties.items.inversedBy }
		block.vm.$emit('registerChange', { id: 'r2' })
		const afterRegister = { register: vm.properties.items.register, ref: vm.properties.items.$ref }
		expect({ afterSet, afterInversed, afterSchema, afterRegister }).toEqual({
			afterSet: { writeBack: false, handling: 'uri', queryParams: 'x=y' },
			afterInversed: { inversedBy: 'other', items: 'other', queryParams: '' },
			afterSchema: { ref: 's2', items: 's2', inversedBy: '' },
			afterRegister: { register: 'r2', ref: '' },
		})
		wrapper.unmount()
	})
})
