/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The schema draft bar mounted: shown only for a draft, its buttons, the
 * breaking-change question and the errors.
 *
 * @spec exclude Unit test of the client-side schema draft bar.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SchemaDraftBar from './SchemaDraftBar.vue'
import { schemaStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	schemaStore: { publishSchemaDraft: jest.fn(), discardSchemaDraft: jest.fn() },
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	describeSchemaChange: (change) => `changed ${change.property}`,
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({
	NcNoteCard: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' },
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}))

/**
 * Mount the bar.
 *
 * @param {object|null} schema The schema
 * @return {object} The wrapper
 */
function mountBar(schema = { id: 7, draft: { title: 'x' } }) {
	return mount(SchemaDraftBar, { props: { schema } })
}

/**
 * A button by its label.
 *
 * @param {object} wrapper The wrapper
 * @param {string} label The label
 * @return {object} The button
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((b) => b.text() === label)
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('SchemaDraftBar mounted', () => {
	it('renders nothing without a schema or a draft', () => {
		expect(mountBar(null).find('.note').exists()).toBe(false)
		expect(mountBar({ id: 7, draft: null }).find('.note').exists()).toBe(false)
	})

	it('offers edit, publish and discard for a draft', async () => {
		const wrapper = mountBar()
		expect(wrapper.findAll('button').map((b) => b.text())).toEqual([
			'Edit draft',
			'Publish draft',
			'Discard draft',
		])
		await button(wrapper, 'Edit draft').trigger('click')
		expect(wrapper.emitted('editDraft')).toHaveLength(1)
	})

	it('asks before a breaking publish and publishes acknowledged on request', async () => {
		schemaStore.publishSchemaDraft
			.mockRejectedValueOnce({ response: { status: 409, data: { changes: [{ property: 'name' }] } } })
			.mockResolvedValueOnce({})
		const wrapper = mountBar()
		await button(wrapper, 'Publish draft').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('.schema-draft-bar__changes li').map((l) => l.text())).toEqual([
			'changed name',
		])
		await button(wrapper, 'Publish anyway').trigger('click')
		await flushPromises()
		expect(schemaStore.publishSchemaDraft.mock.calls).toEqual([
			[7, { acknowledgeBreaking: false }],
			[7, { acknowledgeBreaking: true }],
		])
		expect(wrapper.find('.schema-draft-bar__changes').exists()).toBe(false)
	})

	it('shows a refused acknowledged publish as an error', async () => {
		schemaStore.publishSchemaDraft.mockRejectedValue({
			message: 'm',
			response: { status: 409, data: { changes: [], error: 'Still breaking' } },
		})
		const wrapper = mountBar()
		await wrapper.vm.publish(true)
		expect(wrapper.find('.schema-draft-bar__error').text()).toBe('Still breaking')
	})

	it('discards the draft, or shows why it could not', async () => {
		schemaStore.discardSchemaDraft.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('Locked'))
		const wrapper = mountBar()
		await button(wrapper, 'Discard draft').trigger('click')
		await flushPromises()
		expect(schemaStore.discardSchemaDraft).toHaveBeenCalledWith(7)
		expect(wrapper.find('.schema-draft-bar__error').exists()).toBe(false)
		await button(wrapper, 'Discard draft').trigger('click')
		await flushPromises()
		expect(wrapper.find('.schema-draft-bar__error').text()).toBe('Locked')
		expect(button(wrapper, 'Discard draft').attributes('disabled')).toBeUndefined()
	})
})
