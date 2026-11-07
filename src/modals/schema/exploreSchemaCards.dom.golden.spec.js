import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the analysis results in ExploreSchema: the summary cards
 * and the property behaviour switches.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ExploreSchema from './ExploreSchema.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/Check.vue', () => mockModule('Check'))
jest.mock('vue-material-design-icons/DatabaseSearch.vue', () => mockModule('DatabaseSearch'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockModule('Refresh'))
jest.mock('../../components/PaginationComponent.vue', () => mockModule('PaginationComponent'))
jest.mock('../../components/SchemaStatsBlock.vue', () => mockModule('SchemaStatsBlock'))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	schemaStore: { schemaItem: { id: 7 }, getSchemaStats: jest.fn(() => Promise.resolve({ objects: { total: 3 } })) },
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
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and default slot.
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
					.filter(([key]) => !/^on[A-Z]|^onUpdate/.test(key))
					.map(([key, value]) => [key, typeof value === 'object' ? JSON.stringify(value) : value]),
			)
			return render(`x-${name}`, attrs, this.$slots.default?.({}))
		},
	}
}

const ANALYSIS = {
	total_objects: 12,
	analysis_date: '2026-01-02T03:04:05Z',
	analysis_summary: { new_properties_count: 4, existing_properties_improvements: 2 },
	discovered_properties: { a: {}, b: {} },
	suggestions: [
		{ property_name: 'title', recommended_type: 'string', description: 'A title', confidence: 'high', improvement_status: 'new' },
	],
}

/**
 * Mounts the dialog with an analysis in place.
 *
 * @param {object} analysis The analysis
 * @return {Promise<object>} The wrapper
 */
async function dialog(analysis = ANALYSIS) {
	global.fetch = jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(analysis) }))
	const { translate: t, translatePlural: n } = jest.requireMock('@nextcloud/l10n')
	const stores = jest.requireMock('../../store/store.js')
	const bindings = { ...ExploreSchema.components, ...stores, t, n }
	const wrapper = mount({ ...ExploreSchema, setup: () => bindings }, {
		global: { config: { warnHandler: () => {} } },
	})
	await wrapper.vm.startAnalysis()
	await flushPromises()
	return wrapper
}

/**
 * The markup of one element, proven not empty.
 *
 * @param {object} wrapper The mounted dialog
 * @param {string} selector The element
 * @return {string} The markup
 */
function markup(wrapper, selector) {
	const html = wrapper
		.find(selector)
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/ style="[^"]*"/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/> +/g, '>')
		.replace(/ +</g, '<')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
		.join('\n')
	expect(html.length).toBeGreaterThan(20)
	return html
}

/**
 * The texts of the summary cards.
 *
 * @param {object} wrapper The mounted dialog
 * @return {string} One text per line
 */
function cards(wrapper) {
	const text = wrapper
		.find('.analysis-summary')
		.text()
		.replace(/\s+/g, ' ')
		.replace(/(Analysis completed:).*/, '$1')
	expect(text).toContain('Objects Analyzed')
	return text
}

describe('ExploreSchema analysis results', () => {
	it('shows the summary cards', async () => {
		expect(cards(await dialog())).toMatchSnapshot()
	})

	it('falls back to the discovered properties and to zero', async () => {
		const analysis = { ...ANALYSIS, analysis_summary: undefined }
		expect(cards(await dialog(analysis))).toMatchSnapshot()
	})

	it('counts the selection in the summary', async () => {
		const wrapper = await dialog()
		wrapper.vm.togglePropertySelection('title')
		await flushPromises()
		expect(cards(wrapper)).toMatchSnapshot()
	})

	it('shows the behaviour switches of a selected property', async () => {
		const wrapper = await dialog()
		wrapper.vm.togglePropertySelection('title')
		await flushPromises()
		expect(markup(wrapper, '.behaviors-section')).toMatchSnapshot()
	})

	it('writes each behaviour switch to the property configuration', async () => {
		const wrapper = await dialog()
		wrapper.vm.togglePropertySelection('title')
		await flushPromises()
		const switches = wrapper.find('.behaviors-section').findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(switches).toHaveLength(7)
		const before = { ...wrapper.vm.selectedPropertiesConfig.title }
		const changed = []
		for (const [index, item] of switches.entries()) {
			item.vm.$attrs['onUpdate:modelValue'](`on${index}`)
			await flushPromises()
		}
		for (const [key, value] of Object.entries(wrapper.vm.selectedPropertiesConfig.title)) {
			if (value !== before[key]) changed.push([key, value])
		}
		expect(changed).toMatchSnapshot()
	})
})
