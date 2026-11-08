/**
 * The search sidebar's facet checkboxes: metadata fields keyed under
 * `@self.`, object fields under their own name, each with its label.
 *
 * @spec exclude Client-side facet checkbox grouping.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexSidebar: {} }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Filter.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Star.vue', () => ({}))
jest.mock('vue-material-design-icons/StarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => ({}))
jest.mock('../../modals/view/DeleteView.vue', () => ({}))
jest.mock('../../modals/view/EditView.vue', () => ({}))
jest.mock('../../store/store.js', () => {
	const circular = () => {
		const store = {}
		store.self = store
		return store
	}
	return {
		navigationStore: circular(),
		objectStore: circular(),
		registerStore: circular(),
		schemaStore: circular(),
		viewsStore: circular(),
	}
})

/**
 * The facet groups for some facetable fields.
 *
 * @param {object} facetableFields The fields the schema can facet on
 * @return {Array<object>} The groups
 */
function categories(facetableFields) {
	return SearchSideBar.computed.facetCategories.call({ facetableFields })
}

describe('SearchSideBar facet checkboxes', () => {
	it('keys metadata fields under @self and labels them by description', () => {
		const [metadata, content] = categories({
			'@self': {
				created: { description: 'Created', facet_types: ['range'] },
				owner: { facet_types: ['terms', 'exists'] },
			},
		})
		expect(metadata.title).toBe('Metadata Filters')
		expect(metadata.entries).toEqual([
			{
				key: '@self.created',
				id: 'facet-@self-created',
				field: { description: 'Created', facet_types: ['range'] },
				label: 'Created',
				types: 'range',
			},
			{
				key: '@self.owner',
				id: 'facet-@self-owner',
				field: { facet_types: ['terms', 'exists'] },
				label: 'owner',
				types: 'terms, exists',
			},
		])
		expect(content.entries).toBeNull()
	})

	it('keys object fields by name and labels them by title first', () => {
		const [metadata, content] = categories({
			object_fields: {
				city: { title: 'City', description: 'Town', facet_types: ['terms'] },
				zip: { description: 'Postcode', facet_types: ['terms'] },
			},
		})
		expect(metadata.entries).toBeNull()
		expect(content.title).toBe('Content Filters')
		expect(
			content.entries.map(({ key, id, label }) => [key, id, label]),
		).toEqual([
			['city', 'facet-city', 'City'],
			['zip', 'facet-zip', 'Postcode'],
		])
	})
})
