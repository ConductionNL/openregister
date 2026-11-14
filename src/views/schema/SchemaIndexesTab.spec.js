/**
 * The schema indexes tab: one table per register, each index with the field
 * setting that asked for it, and the empty and unreadable states.
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SchemaIndexesTab from './SchemaIndexesTab.vue'
import { fetchSchemaIndexes } from '../../services/schemaIndexes.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match)),
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppContentDetails: { name: 'NcAppContentDetails', render() { return require('vue').h('div', {}, this.$slots.default()) } },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => require('vue').h('x-loading') },
}))
jest.mock('../../services/schemaIndexes.js', () => ({ __esModule: true, fetchSchemaIndexes: jest.fn() }))

const SCHEMA = { id: 12, slug: 'zaken' }

/**
 * Mount the tab and let its read settle.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountTab() {
	const wrapper = mount(SchemaIndexesTab, { props: { schema: SCHEMA } })
	await flushPromises()
	return wrapper
}

/**
 * The cell texts of every body row.
 *
 * @param {object} wrapper The wrapper
 * @return {Array<Array<string>>} The rows
 */
function rows(wrapper) {
	return wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('SchemaIndexesTab', () => {
	it('lists the indexes of each register table with the setting behind each', async () => {
		fetchSchemaIndexes.mockResolvedValue({
			results: [
				{
					register: 3,
					indexes: [
						{ name: 't_registratiedatum_sort_idx', property: 'registratiedatum', column: 'registratiedatum', kind: 'btree', flag: 'indexed' },
						{ name: 't_omschrijving_trgm_idx', property: 'omschrijving', column: 'omschrijving', kind: 'trigram', flag: 'searchable' },
						{ name: 't_status_facet_idx', property: 'status', column: 'status', kind: 'btree', flag: 'facetable' },
						{ name: 'handmade', property: null, column: null, kind: 'other', flag: null },
					],
				},
				{ register: 5, indexes: [] },
			],
			textSearchIndex: true,
		})

		const wrapper = await mountTab()

		expect(fetchSchemaIndexes).toHaveBeenCalledWith(12)
		expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['Table of register 3', 'Table of register 5'])
		expect(rows(wrapper)).toEqual([
			['registratiedatum', 'Index for filtering and sorting', 't_registratiedatum_sort_idx'],
			['omschrijving', 'Index for text search', 't_omschrijving_trgm_idx'],
			['status', 'Facetable', 't_status_facet_idx'],
			['—', 'Not a field setting (kept on synchronisation)', 'handmade'],
		])
		expect(wrapper.text()).toContain('This table has no indexes yet.')
		expect(wrapper.find('x-loading').exists()).toBe(false)
	})

	it('says so when no register stores the schema in a table yet', async () => {
		fetchSchemaIndexes.mockResolvedValue({ results: [], textSearchIndex: false })

		const wrapper = await mountTab()

		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.text()).toContain('No register stores objects of this schema in its own table yet.')
	})

	it('shows a refusal when the list cannot be read', async () => {
		fetchSchemaIndexes.mockRejectedValue(new Error('500'))

		const wrapper = await mountTab()

		expect(wrapper.find('[role="alert"]').text()).toBe('The index list could not be read.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows the loading state until the read settles', () => {
		fetchSchemaIndexes.mockReturnValue(new Promise(() => {}))

		const wrapper = mount(SchemaIndexesTab, { props: { schema: SCHEMA } })

		expect(wrapper.find('x-loading').exists()).toBe(true)
	})
})
