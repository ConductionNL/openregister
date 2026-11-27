/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The draft form: a new draft with key, name and changes; added changes to an
 * existing one; a non-object refused before any call; the server's refusal shown.
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
 */
import { flushPromises, mount } from '@vue/test-utils'
import DraftEditModal from './DraftEditModal.vue'
import { createDraft, updateDraft } from '../../components/object-drafts/draftsApi.js'

jest.mock('../../components/object-drafts/draftsApi.js', () => ({
	...jest.requireActual('../../components/object-drafts/draftsApi.js'),
	createDraft: jest.fn(),
	updateDraft: jest.fn(),
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => {
	const field = (name) => ({
		name,
		props: ['modelValue', 'label', 'error', 'disabled', 'helperText'],
		emits: ['update:modelValue'],
		template: '<textarea :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	})
	return {
	NcDialog: { name: 'NcDialog', props: ['name'], template: '<div><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
	NcButton: { name: 'NcButton', props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
	NcTextArea: field('NcTextArea'),
	NcTextField: field('NcTextField'),
	}
})

const TARGET = { register: 'zaken', schema: 'vergunning', id: 'v-1' }

/**
 * The Save button.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The button.
 */
const save = (wrapper) => wrapper.findAll('button').filter((b) => b.text() === 'Save').at(0)

beforeEach(() => jest.resetAllMocks())

describe('DraftEditModal', () => {
	it('creates a new draft with the typed key, name and changes', async () => {
		createDraft.mockResolvedValue({ key: 'status-update' })
		const wrapper = mount(DraftEditModal, { props: { target: TARGET } })
		expect(wrapper.find('h2').text()).toBe('New draft')
		expect(save(wrapper).attributes('disabled')).toBeDefined()

		await wrapper.find('[data-label="Key"]').setValue(' status-update ')
		await wrapper.find('[data-label="Name"]').setValue('Status update')
		await wrapper.find('[data-label="Changes"]').setValue('{"status": "in_behandeling"}')
		await save(wrapper).trigger('click')
		await flushPromises()

		expect(createDraft).toHaveBeenCalledWith(TARGET, { key: 'status-update', name: 'Status update', changes: { status: 'in_behandeling' } })
		expect(wrapper.emitted('saved')).toEqual([[{ key: 'status-update' }]])
	})

	it('adds changes to an existing draft, starting from its current ones', async () => {
		updateDraft.mockResolvedValue({ key: 'status-update' })
		const wrapper = mount(DraftEditModal, { props: { target: TARGET, draft: { key: 'status-update', name: 'Status update' }, changes: { status: 'x' } } })
		expect(wrapper.find('h2').text()).toBe('Status update')
		expect(wrapper.find('[data-label="Key"]').exists()).toBe(false)
		expect(JSON.parse(wrapper.find('[data-label="Changes"]').element.value)).toEqual({ status: 'x' })

		await wrapper.find('[data-label="Changes"]').setValue('{"status": "y"}')
		await save(wrapper).trigger('click')
		await flushPromises()
		expect(updateDraft).toHaveBeenCalledWith(TARGET, 'status-update', { status: 'y' })
		expect(createDraft).not.toHaveBeenCalled()
	})

	it('refuses changes that are not a JSON object and shows the server refusal', async () => {
		const wrapper = mount(DraftEditModal, { props: { target: TARGET, draft: { key: 'a' } } })
		await wrapper.find('[data-label="Changes"]').setValue('[1, 2]')
		expect(save(wrapper).attributes('disabled')).toBeDefined()
		await wrapper.vm.save()
		expect(updateDraft).not.toHaveBeenCalled()

		updateDraft.mockRejectedValue({ response: { data: { error: 'The key "main" is reserved for the published version.' } } })
		await wrapper.find('[data-label="Changes"]').setValue('{}')
		await save(wrapper).trigger('click')
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('The key "main" is reserved for the published version.')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
