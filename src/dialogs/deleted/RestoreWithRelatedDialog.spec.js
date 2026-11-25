/**
 * The related-records restore dialog: it shows the server's plan grouped by
 * what will happen, refuses to confirm a plan the server would refuse, and
 * restores only on confirmation.
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RestoreWithRelatedDialog, {
	restoreGroups,
	restoreItemLabel,
} from './RestoreWithRelatedDialog.vue'

jest.mock('../../store/store.js', () => ({
	deletedStore: {
		previewRestore: jest.fn(),
		restoreDeleted: jest.fn(),
	},
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcNoteCard: mockStub('NcNoteCard'),
}))
jest.mock('vue-material-design-icons/Restore.vue', () => ({
	__esModule: true,
	default: mockStub('Restore'),
}))
jest.mock('../../modals/shared/ConfirmDialogFrame.vue', () => ({
	__esModule: true,
	default: mockStub('ConfirmDialogFrame'),
}))

/**
 * A stand-in for a component: its tag, its attributes and its slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () =>
				render(
					`x-${name}`,
					Object.fromEntries(
						Object.entries(attrs).filter(([key]) => !/^on[A-Z]/.test(key)),
					),
					Object.values(slots).map((fill) => fill()),
				)
		},
	}
}

const PLAN = {
	root: 'lead-1',
	cascadeKnown: true,
	restore: [
		{ uuid: 'act-1', reason: 'deleted with this object', schema: 'activity' },
		{ reason: 'deleted with this object', schema: 'activity' },
	],
	relink: [
		{ uuid: 'quote-1', reason: 'the field still holds what the delete wrote', schema: 'quote', property: 'lead' },
	],
	changed: [
		{ uuid: 'quote-2', reason: 'the field was changed since the delete', schema: 'quote', property: 'lead' },
	],
	gone: [],
	already: [],
	forbidden: { count: 0, schemas: {} },
}

/**
 * The dialog, opened on one deleted object.
 *
 * @param {object} props The props
 * @return {Promise<object>} The wrapper, after the preview has loaded
 */
async function opened(props = {}) {
	const wrapper = mount(RestoreWithRelatedDialog, {
		props: { show: true, objectId: 'lead-1', ...props },
	})
	await flushPromises()
	return wrapper
}

const { deletedStore } = jest.requireMock('../../store/store.js')

beforeEach(() => {
	deletedStore.previewRestore.mockReset()
	deletedStore.restoreDeleted.mockReset()
})

describe('restoreGroups', () => {
	it('keeps the display order and leaves out empty classes', () => {
		expect(restoreGroups(PLAN).map((group) => [group.key, group.items.length])).toEqual([
			['restore', 2],
			['relink', 1],
			['changed', 1],
		])
	})

	it('has nothing to show without a plan', () => {
		expect(restoreGroups(null)).toEqual([])
	})
})

describe('restoreItemLabel', () => {
	it('names the schema, the property and the id', () => {
		expect(restoreItemLabel(PLAN.relink[0])).toBe('quote · lead — quote-1')
	})

	it('never invents an id the user may not see', () => {
		expect(restoreItemLabel(PLAN.restore[1])).toBe('activity')
	})
})

describe('RestoreWithRelatedDialog', () => {
	it('loads the preview of the object and shows it by class', async () => {
		deletedStore.previewRestore.mockResolvedValue(PLAN)

		const wrapper = await opened()

		expect(deletedStore.previewRestore).toHaveBeenCalledWith('lead-1')
		const text = wrapper.text()
		expect(text).toContain('Will be restored (2)')
		expect(text).toContain('References put back (1)')
		expect(text).toContain('Changed since the delete (1)')
		expect(text).not.toContain('Already restored')
		expect(text).toContain('activity — act-1')
		expect(wrapper.find('x-confirmdialogframe').attributes('confirmdisabled')).toBe('false')
		expect(deletedStore.restoreDeleted).not.toHaveBeenCalled()
	})

	it('restores on confirmation and hands the counts to the page', async () => {
		deletedStore.previewRestore.mockResolvedValue(PLAN)
		deletedStore.restoreDeleted.mockResolvedValue({ success: true, restored: 3, relinked: 1 })
		const wrapper = await opened()

		await wrapper.vm.restore()

		expect(deletedStore.restoreDeleted).toHaveBeenCalledWith('lead-1')
		expect(wrapper.emitted('restored')).toEqual([[{ success: true, restored: 3, relinked: 1 }]])
	})

	it('counts the records the user may not restore and will not confirm', async () => {
		deletedStore.previewRestore.mockResolvedValue({
			...PLAN,
			forbidden: { count: 2, schemas: { activity: 2 } },
		})
		const wrapper = await opened()

		expect(wrapper.text()).toContain('Related records you may not restore: {count} {"count":2}')
		expect(wrapper.find('x-confirmdialogframe').attributes('confirmdisabled')).toBe('true')
		await wrapper.vm.restore()
		expect(deletedStore.restoreDeleted).not.toHaveBeenCalled()
	})

	it('says when the delete recorded no related records', async () => {
		deletedStore.previewRestore.mockResolvedValue({ root: 'lead-1', cascadeKnown: false })

		const wrapper = await opened()

		expect(wrapper.text()).toContain('This delete did not record its related records; only this object will be restored.')
	})

	it('says when nothing else came with the object', async () => {
		deletedStore.previewRestore.mockResolvedValue({ ...PLAN, restore: [], relink: [], changed: [] })

		const wrapper = await opened()

		expect(wrapper.text()).toContain('Nothing else was deleted or unlinked with this object.')
	})

	it('shows a failed preview and a failed restore', async () => {
		deletedStore.previewRestore.mockRejectedValue(new Error('Object not found'))
		const wrapper = await opened()

		expect(wrapper.find('x-confirmdialogframe').attributes('error')).toBe('Object not found')

		deletedStore.previewRestore.mockResolvedValue(PLAN)
		deletedStore.restoreDeleted.mockRejectedValue(new Error('You may not restore 1 related object(s)'))
		await wrapper.vm.load()
		await wrapper.vm.restore()

		expect(wrapper.find('x-confirmdialogframe').attributes('error')).toBe('You may not restore 1 related object(s)')
		expect(wrapper.emitted('restored')).toBeUndefined()
	})

	it('renders and loads nothing while closed', async () => {
		const wrapper = await opened({ show: false })

		expect(wrapper.find('x-confirmdialogframe').exists()).toBe(false)
		expect(deletedStore.previewRestore).not.toHaveBeenCalled()
	})
})
