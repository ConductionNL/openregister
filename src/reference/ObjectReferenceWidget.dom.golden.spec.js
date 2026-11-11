import { mount } from '@vue/test-utils'
/**
 * Golden record of the object reference card: its markup with a full rich
 * object and with an empty one. No `updated` date is given, so the record
 * never depends on the clock, locale or timezone.
 *
 * @spec exclude Golden record of client-side reference widget markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ObjectReferenceWidget from './ObjectReferenceWidget.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
}))

describe('ObjectReferenceWidget', () => {
	it.each([
		['full', {
			title: 'Dune',
			url: 'https://cloud.example/apps/openregister/objects/1',
			schema: { title: 'Book' },
			register: { title: 'Library' },
			properties: [{ label: 'Author', value: 'Herbert' }],
		}],
		['empty', {}],
	])('renders a %s rich object', (name, richObject) => {
		expect(mount(ObjectReferenceWidget, { props: { richObject } }).html()).toMatchSnapshot()
	})

	it('prints the updated date in the reader\'s locale, or nothing', () => {
		const formattedDate = ObjectReferenceWidget.computed.formattedDate
		const updated = '2024-03-05T10:20:00Z'
		expect(formattedDate.call({ updated })).toBe(
			new Date(updated).toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			}),
		)
		expect(formattedDate.call({ updated: '' })).toBe('')
		expect(formattedDate.call({ updated: null })).toBe('')
	})
})
