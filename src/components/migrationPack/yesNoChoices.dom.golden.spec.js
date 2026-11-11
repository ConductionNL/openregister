/**
 * Golden record of the yes/no choices the migration-pack editor offers: the
 * stored value of a row in a yes/no table, and the value a yes/no table uses
 * for other values. The placeholder shows only while nothing is chosen.
 *
 * @spec exclude Golden record of client-side select markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import TransformDialog from '../../dialogs/migrationPack/TransformDialog.vue'
import KeyValueRows from './KeyValueRows.vue'
import { newTransform } from '../../views/migrationPack/packDraft.js'

jest.mock(
	'vue-material-design-icons/Plus.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock('@nextcloud/vue', () => {
	const { h } = jest.requireActual('vue')
	const stubs = {}
	const stub = (name) => {
		stubs[name] ??= {
			name,
			render() {
				return h(
					'div',
					{ 'data-nc': name },
					Object.values(this.$slots).map((slot) => slot()),
				)
			},
		}
		return stubs[name]
	}
	return new Proxy(
		{ __esModule: true },
		{
			get: (target, prop) =>
				prop in target ? target[prop] : stub(String(prop)),
		},
	)
})

/**
 * The yes/no rows editor with one row holding `text`.
 *
 * @param {string} text The row's stored value.
 * @return {object} The mounted wrapper.
 */
function rowsWith(text) {
	return mount(KeyValueRows, {
		props: {
			rows: [{ uid: 'a', key: 'Ja', text, json: true }],
			keyLabel: 'Source value',
			valueLabel: 'Stored value',
			addLabel: 'Add',
			booleanValues: true,
		},
	})
}

/**
 * The transform dialog of a yes/no table whose other values get `text`.
 *
 * @param {string} text The default cell's text.
 * @return {object} The mounted wrapper.
 */
function dialogWith(text) {
	return mount(TransformDialog, {
		props: {
			transform: {
				...newTransform('bool-map'),
				defaultMode: 'value',
				defaultCell: { text, json: true },
			},
			rowNumber: 1,
		},
	})
}

describe('the yes/no choices of the migration-pack editor', () => {
	it.each(['true', 'false', 'maybe'])('renders a row holding %s', (text) => {
		const wrapper = rowsWith(text)
		expect(wrapper.find('select').html()).toMatchSnapshot()
		wrapper.unmount()
	})

	it.each(['true', 'false', 'maybe'])(
		'renders the value for other values %s',
		(text) => {
			const wrapper = dialogWith(text)
			const select = wrapper.find('.transform-dialog__cell select')
			expect(select.html()).toMatchSnapshot()
			wrapper.unmount()
		},
	)
})
