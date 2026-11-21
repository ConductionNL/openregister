/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Drafts tab: the list, compare, promote (with a conflict), discard and preview.
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
 */
import { getCurrentUser } from '@nextcloud/auth'
import { flushPromises, mount } from '@vue/test-utils'
import DraftsTab from './DraftsTab.vue'
import * as api from './draftsApi.js'

jest.mock('./draftsApi.js', () => ({
	...jest.requireActual('./draftsApi.js'),
	listDrafts: jest.fn(),
	readDraft: jest.fn(),
	discardDraft: jest.fn(),
	promoteDraft: jest.fn(),
	previewDraft: jest.fn(),
	schemaPreviewUrl: jest.fn(),
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['variant', 'disabled'], emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], template: '<div class="empty">{{ name }}</div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div :class="\'note-\' + type"><slot /></div>' },
}))
jest.mock('vue-material-design-icons/FileDocumentEditOutline.vue', () => ({ name: 'FileDocumentEditOutline', render: () => null }))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({ name: 'OpenInNew', render: () => null }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ name: 'Plus', render: () => null }))
jest.mock('../../modals/object/DraftEditModal.vue', () => ({ name: 'DraftEditModal', props: ['target', 'draft', 'changes'], render: () => null }))
jest.mock('../shared/TabErrorState.vue', () => ({ name: 'TabErrorState', props: ['name', 'description'], template: '<div class="failed">{{ name }}|{{ description }}</div>' }))

const DRAFT = { key: 'status-update', name: 'Status update', createdBy: 'medewerker-1', changedFields: ['status'] }
const PUBLISHED = { id: 'v-1', status: 'nieuw' }

/**
 * Mount the tab once the drafts and the template are read.
 *
 * @param {Array<object>} drafts The drafts the list answers.
 * @param {string|null} template The schema's preview template.
 * @return {Promise<object>} The wrapper.
 */
async function mountTab(drafts = [DRAFT], template = null) {
	api.listDrafts.mockResolvedValue(drafts)
	api.schemaPreviewUrl.mockResolvedValue(template)
	const wrapper = mount(DraftsTab, { props: { register: 'zaken', schema: 'vergunning', objectId: 'v-1', published: PUBLISHED } })
	await flushPromises()
	return wrapper
}

/**
 * The button of a draft row with this label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.findAll('button').filter((b) => b.text() === label).at(0)
}

beforeEach(() => {
	jest.resetAllMocks()
	getCurrentUser.mockReturnValue({ uid: 'medewerker-1', isAdmin: false })
})

describe('DraftsTab', () => {
	it('lists each draft with its key, creator and changed fields, for this object', async () => {
		const wrapper = await mountTab()
		expect(api.listDrafts).toHaveBeenCalledWith({ register: 'zaken', schema: 'vergunning', id: 'v-1' })
		const row = wrapper.find('[data-testid="draft-status-update"]')
		expect(row.text()).toContain('Status update')
		expect(row.text()).toContain('status-update')
		expect(row.text()).toContain('medewerker-1')
		expect(row.text()).toContain('Changed fields: status')
	})

	it('shows the empty state without drafts and the failure when the list cannot be read', async () => {
		const empty = await mountTab([])
		expect(empty.find('.empty').text()).toBe('No drafts')

		api.listDrafts.mockRejectedValue({ response: { data: { error: 'Please sign in.' } } })
		api.schemaPreviewUrl.mockResolvedValue(null)
		const failed = mount(DraftsTab, { props: { register: 'zaken', schema: 'vergunning', objectId: 'v-1' } })
		await flushPromises()
		expect(failed.find('.failed').text()).toBe('The drafts could not be read|Please sign in.')
	})

	it('compares the changed fields against the published version', async () => {
		api.readDraft.mockResolvedValue({ id: 'v-1', status: 'in_behandeling' })
		const wrapper = await mountTab()
		await button(wrapper, 'Compare').trigger('click')
		await flushPromises()
		expect(api.readDraft).toHaveBeenCalledWith({ register: 'zaken', schema: 'vergunning', id: 'v-1' }, 'status-update')
		const cells = wrapper.findAll('tbody td').map((td) => td.text())
		expect(cells).toEqual(['status', 'nieuw', 'in_behandeling'])

		await button(wrapper, 'Compare').trigger('click')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('promotes a draft: it leaves the list and the published object is handed up', async () => {
		api.promoteDraft.mockResolvedValue({ id: 'v-1', status: 'in_behandeling' })
		const wrapper = await mountTab()
		await wrapper.find('[data-testid="draft-promote"]').trigger('click')
		await flushPromises()
		expect(api.promoteDraft).toHaveBeenCalledWith({ register: 'zaken', schema: 'vergunning', id: 'v-1' }, 'status-update', false)
		expect(wrapper.find('[data-testid="draft-status-update"]').exists()).toBe(false)
		expect(wrapper.emitted('promoted')).toEqual([[{ id: 'v-1', status: 'in_behandeling' }]])
	})

	it('lists the conflicting fields of a refused promotion and offers force only to an administrator', async () => {
		api.promoteDraft.mockRejectedValue({ response: { status: 409, data: { conflicts: [{ field: 'status', draft: 'a', live: 'b' }] } } })
		const wrapper = await mountTab()
		await wrapper.find('[data-testid="draft-promote"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note-error').text()).toContain('status')
		expect(button(wrapper, 'Promote anyway')).toBeUndefined()
		expect(wrapper.find('[data-testid="draft-status-update"]').exists()).toBe(true)
		expect(wrapper.emitted('promoted')).toBeUndefined()

		getCurrentUser.mockReturnValue({ uid: 'admin', isAdmin: true })
		const admin = await mountTab()
		await admin.find('[data-testid="draft-promote"]').trigger('click')
		await flushPromises()
		api.promoteDraft.mockResolvedValue({ id: 'v-1' })
		await button(admin, 'Promote anyway').trigger('click')
		await flushPromises()
		expect(api.promoteDraft).toHaveBeenLastCalledWith({ register: 'zaken', schema: 'vergunning', id: 'v-1' }, 'status-update', true)
		expect(admin.find('[data-testid="draft-status-update"]').exists()).toBe(false)
	})

	it('shows the server message of another refusal on the draft', async () => {
		api.promoteDraft.mockRejectedValue({ response: { status: 403, data: { error: 'Forbidden.' } } })
		const wrapper = await mountTab()
		await wrapper.find('[data-testid="draft-promote"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note-error').text()).toBe('Forbidden.')
	})

	it('discards only after the confirmation', async () => {
		api.discardDraft.mockResolvedValue(undefined)
		const wrapper = await mountTab()
		await button(wrapper, 'Discard').trigger('click')
		expect(api.discardDraft).not.toHaveBeenCalled()
		expect(wrapper.find('.note-warning').text()).toContain('The published object stays as it is.')

		await wrapper.find('[data-testid="draft-discard-confirm"]').trigger('click')
		await flushPromises()
		expect(api.discardDraft).toHaveBeenCalledWith({ register: 'zaken', schema: 'vergunning', id: 'v-1' }, 'status-update')
		expect(wrapper.find('[data-testid="draft-status-update"]').exists()).toBe(false)
	})

	it('offers Preview only when the schema declares previewUrl, and opens the filled URL with its token', async () => {
		const without = await mountTab([DRAFT], null)
		expect(without.find('[data-testid="draft-preview"]').exists()).toBe(false)

		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		api.previewDraft.mockResolvedValue({ previewUrl: 'https://www.voorbeeld.nl/preview/v-1?versie=status-update&token=abc' })
		const wrapper = await mountTab([DRAFT], 'https://www.voorbeeld.nl/preview/{uuid}?versie={version}&token={token}')
		await wrapper.find('[data-testid="draft-preview"]').trigger('click')
		await flushPromises()
		expect(api.previewDraft).toHaveBeenCalledWith({ register: 'zaken', schema: 'vergunning', id: 'v-1' }, 'status-update')
		expect(open).toHaveBeenCalledWith('https://www.voorbeeld.nl/preview/v-1?versie=status-update&token=abc', '_blank', 'noopener')
		open.mockRestore()
	})

	it('opens the editor with the draft\'s current changes, or empty for a new draft', async () => {
		api.readDraft.mockResolvedValue({ id: 'v-1', status: 'in_behandeling', title: 'Vergunning' })
		const wrapper = await mountTab()
		await button(wrapper, 'Edit').trigger('click')
		await flushPromises()
		const editor = wrapper.findComponent({ name: 'DraftEditModal' })
		expect(editor.props('draft')).toEqual(DRAFT)
		expect(editor.props('changes')).toEqual({ status: 'in_behandeling' })

		editor.vm.$emit('close')
		await flushPromises()
		await wrapper.find('[data-testid="draft-new"]').trigger('click')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'DraftEditModal' }).props('draft')).toBeNull()
	})
})
