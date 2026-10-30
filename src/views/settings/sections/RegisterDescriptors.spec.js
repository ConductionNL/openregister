/**
 * The Register descriptors panel: a re-import asks first, and when it took
 * authorization from the file over a local change it says so, naming each block.
 *
 * The panel is mounted for real; each @nextcloud/vue component is a
 * <div data-nc="Name"> that renders its slots and passes its listeners to the
 * element, so a click on a button reaches the panel's own handler.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import RegisterDescriptors from './RegisterDescriptors.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((p) => p),
}))
jest.mock(
	'vue-material-design-icons/Download.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock('../../../components/shared/SettingsSection.vue', () => {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		default: {
			name: 'SettingsSection',
			render() {
				return h('section', this.$slots.default ? this.$slots.default() : [])
			},
		},
	}
})
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

const ROW = {
	appId: 'dossiq',
	slug: 'dossiq',
	title: 'Dossiq',
	state: 'current',
	installedVersion: '1.0.0',
	shippedVersion: '1.0.0',
}

/**
 * Let queued promise callbacks run.
 *
 * @return {Promise<void>}
 */
function flush() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

/**
 * The panel mounted on one inventory row.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel() {
	axios.get.mockResolvedValue({
		data: { results: [ROW], total: 1, absent: 0, behind: 0 },
	})
	const wrapper = mount(RegisterDescriptors, {
		global: {
			mocks: {
				t: (app, text, params = {}) =>
					text.replace(/\{(\w+)\}/g, (match, key) =>
						String(params[key] ?? match),
					),
				n: (app, singular, plural, count) =>
					singular.replace('%n', String(count)),
			},
		},
	})
	await flush()
	return wrapper
}

/**
 * The rendered NcButton whose text contains the given text, inside a root.
 *
 * @param {object} root A wrapper to search in.
 * @param {string} text Part of the button text.
 * @return {object|undefined} The button.
 */
function button(root, text) {
	return root
		.findAllComponents({ name: 'NcButton' })
		.find((candidate) => candidate.text().includes(text))
}

describe('the Register descriptors panel', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('asks before it re-imports, and imports nothing on Cancel', async () => {
		const wrapper = await mountPanel()
		expect(wrapper.find('[data-nc="NcDialog"]').exists()).toBe(false)

		await button(wrapper, 'Re-import').trigger('click')
		const dialog = wrapper.find('[data-nc="NcDialog"]')
		expect(dialog.exists()).toBe(true)
		expect(dialog.text()).toContain(
			'takes the authorization of its schemas from the file',
		)
		expect(axios.post).not.toHaveBeenCalled()

		await button(dialog, 'Cancel').trigger('click')
		expect(wrapper.find('[data-nc="NcDialog"]').exists()).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('names each authorization block the import took over a local change, as a warning', async () => {
		axios.post.mockResolvedValueOnce({
			data: {
				outcome: 'imported',
				counts: { authorizationReplaced: 1 },
				replaced: [{ schema: 'x', path: 'authorization' }],
			},
		})
		const wrapper = await mountPanel()

		await button(wrapper, 'Re-import').trigger('click')
		await button(wrapper.find('[data-nc="NcDialog"]'), 'Re-import').trigger(
			'click',
		)
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const note = wrapper.find('.descriptors__outcome')
		expect(note.text()).toContain('Dossiq was imported from dossiq.')
		expect(note.text()).toContain(
			'Authorization taken from the file over a local change: 1.',
		)
		expect(note.text()).toContain('x: authorization')
		expect(note.classes()).toContain('descriptors__outcome--warning')
		wrapper.unmount()
	})

	it('is a plain success when no authorization block was replaced', async () => {
		axios.post.mockResolvedValueOnce({
			data: {
				outcome: 'imported',
				counts: { authorizationReplaced: 0 },
				replaced: [],
			},
		})
		const wrapper = await mountPanel()

		await button(wrapper, 'Re-import').trigger('click')
		await button(wrapper.find('[data-nc="NcDialog"]'), 'Re-import').trigger(
			'click',
		)
		await flush()

		const note = wrapper.find('.descriptors__outcome')
		expect(note.text()).toContain('Dossiq was imported from dossiq.')
		expect(note.text()).not.toContain('Authorization taken')
		expect(note.classes()).toContain('descriptors__outcome--good')
		wrapper.unmount()
	})
})
