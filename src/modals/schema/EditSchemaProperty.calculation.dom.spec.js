import axios from '@nextcloud/axios'
/**
 * EditSchemaProperty's computed-value section as rendered: hidden until the
 * property is computed, filled from the stored declaration, an operator pick
 * appended to the expression, the JSON error on the expression box, and the
 * trial result, error and dependencies shown after "Try it".
 *
 * @spec openspec/specs/computed-fields/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import EditSchemaProperty from './EditSchemaProperty.vue'
import { schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => `t:${text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key] ?? m))}`,
	t: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/vue', () => mockStubs())
// As in EditSchemaProperty.relation.dom.golden.spec.js, the library
// components are stubbed on 'vue' as well, or the mixed <script setup> file
// renders nothing.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockStubs() }))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
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
jest.mock('vue-material-design-icons/Cancel.vue', () => ({ __esModule: true, default: { name: 'Cancel', render: () => null } }))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({ __esModule: true, default: { name: 'ContentSaveOutline', render: () => null } }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: { name: 'Plus', render: () => null } }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ __esModule: true, default: { name: 'CloseCancelButton', render: () => null } }))

/**
 * Stand-ins for the library components: a root element carrying the
 * attributes, the default slot, and a modelValue prop so v-model binds.
 *
 * @return {object} The stand-ins by name
 */
function mockStubs() {
	const { h: render } = jest.requireActual('vue')
	const names = [
		'NcButton', 'NcCheckboxRadioSwitch', 'NcDateTimePicker', 'NcDialog', 'NcInputField',
		'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField',
	]
	return Object.fromEntries(names.map((name) => [name, {
		name,
		props: ['modelValue', 'error', 'helperText', 'disabled', 'options'],
		emits: ['update:modelValue', 'click'],
		setup(props, { slots }) {
			return () => render(`x-${name}`, {}, slots.default ? slots.default() : [])
		},
	}]))
}

const OPERATORS = { data: { operators: [{ op: 'concat', category: 'text', description: 'Join strings' }] } }

/**
 * Mount the dialog on one stored property.
 *
 * @param {object} property The stored property definition
 * @return {Promise<object>} The wrapper
 */
async function open(property) {
	schemaStore.schemaPropertyKey = 'p'
	schemaStore.schemaItem = { title: 'S', properties: { p: property } }
	const wrapper = mount(EditSchemaProperty)
	await flushPromises()
	return wrapper
}

/**
 * The stand-in carrying a data-testid.
 *
 * @param {object} wrapper The mounted dialog
 * @param {string} name The component name
 * @param {string} testid The data-testid
 * @return {object} The component wrapper
 */
function part(wrapper, name, testid) {
	return wrapper.findAllComponents({ name }).find((c) => c.attributes('data-testid') === testid)
}

describe('EditSchemaProperty computed-value section (DOM)', () => {
	beforeEach(() => {
		axios.get.mockReset().mockResolvedValue(OPERATORS)
		axios.post.mockReset()
	})

	it('is not rendered for a property without a calculation', async () => {
		const wrapper = await open({ type: 'string' })
		expect(wrapper.find('[data-testid="property-calculation"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('renders the stored declaration with the loaded operator catalogue', async () => {
		const wrapper = await open({ type: 'string', calculation: { type: 'integer', expression: { concat: ['a'] } } })
		expect(wrapper.find('[data-testid="property-calculation"]').exists()).toBe(true)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/schemas/calculation-operators')
		expect(part(wrapper, 'NcSelect', 'property-calculation-type').props('modelValue').value).toBe('integer')
		expect(part(wrapper, 'NcSelect', 'property-calculation-operators').props('options')).toEqual([
			{ value: 'concat', label: 'concat (text): Join strings' },
		])
		const expression = part(wrapper, 'NcTextArea', 'property-calculation-expression')
		expect(JSON.parse(expression.props('modelValue'))).toEqual({ concat: ['a'] })
		expect(expression.props('error')).toBe(false)
		expect(expression.props('helperText')).toBe('')
		wrapper.unmount()
	})

	it('changes the result type from its select', async () => {
		const wrapper = await open({ type: 'string', calculation: { type: 'string', expression: null } })
		await part(wrapper, 'NcSelect', 'property-calculation-type').vm.$emit('update:modelValue', { value: 'number' })
		expect(wrapper.vm.calculationType).toBe('number')
		wrapper.unmount()
	})

	it('appends a picked operator to the expression and flags the box as invalid JSON', async () => {
		const wrapper = await open({ type: 'string', calculation: { type: 'string', expression: { concat: [] } } })
		await part(wrapper, 'NcSelect', 'property-calculation-operators').vm.$emit('update:modelValue', { value: 'concat' })
		const expression = part(wrapper, 'NcTextArea', 'property-calculation-expression')
		expect(expression.props('modelValue')).toBe('{\n  "concat": []\n}\n{"concat":[]}')
		expect(expression.props('error')).toBe(true)
		expect(expression.props('helperText')).toBe('t:This is not valid JSON yet.')
		wrapper.unmount()
	})

	it('shows the trial value and the properties it reads after "Try it"', async () => {
		axios.post.mockResolvedValue({ data: { value: 42, dependencies: ['a', 'b'] } })
		const wrapper = await open({ type: 'string', calculation: { type: 'integer', expression: { var: 'a' } } })
		await part(wrapper, 'NcButton', 'property-calculation-try').vm.$emit('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/schemas/calculation-evaluate', {
			calculation: { type: 'integer', expression: { var: 'a' } },
			object: {},
		})
		expect(wrapper.find('[data-testid="property-calculation-result"]').text()).toBe('42')
		expect(wrapper.find('[data-testid="property-calculation-dependencies"]').text()).toBe('t:Reads: a, b')
		expect(part(wrapper, 'NcNoteCard', 'property-calculation-error')).toBeUndefined()
		wrapper.unmount()
	})

	it('shows the evaluator error instead of a value when the trial is refused', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: { message: 'unknown operator' } } } })
		const wrapper = await open({ type: 'string', calculation: { type: 'string', expression: { nope: [] } } })
		await part(wrapper, 'NcButton', 'property-calculation-try').vm.$emit('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="property-calculation-result"]').exists()).toBe(false)
		expect(part(wrapper, 'NcNoteCard', 'property-calculation-error').text()).toBe('unknown operator')
		wrapper.unmount()
	})
})
