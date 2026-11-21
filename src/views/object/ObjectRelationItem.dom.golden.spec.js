/**
 * Golden record of one row of the Uses / Used by tabs of the object detail
 * view: the list item's props, the cube icon and the relation label.
 *
 * @spec exclude Golden record of a presentational list row.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import ObjectRelationItem from './ObjectRelationItem.vue'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcListItem: {
		name: 'NcListItem',
		props: ['name', 'bold', 'forceDisplayActions'],
		template: `<li :data-name="name" :data-bold="String(bold)" :data-force-display-actions="String(forceDisplayActions)">
			<span class="icon"><slot name="icon" /></span>
			<span class="subname"><slot name="subname" /></span>
		</li>`,
	},
}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({
	__esModule: true,
	default: {
		name: 'CubeOutline',
		props: ['size', 'disableMenu'],
		template: '<i class="cube" :data-size="size" :data-disable-menu="String(disableMenu)" />',
	},
}))

describe('ObjectRelationItem', () => {
	it.each([
		[{ name: 'Gemeente Utrecht', subname: 'organisation' }],
		[{}],
	])('renders %j', (props) => {
		const wrapper = mount(ObjectRelationItem, { props })
		expect(wrapper.html()).toMatchSnapshot()
		wrapper.unmount()
	})
})
