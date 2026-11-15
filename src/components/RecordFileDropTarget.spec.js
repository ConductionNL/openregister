import { mount } from '@vue/test-utils'
import { openRecordImport } from '../dialogs/recordImport/openRecordImport.js'
import RecordFileDropTarget from './RecordFileDropTarget.vue'

// The drop target hands a record file over and does nothing else with it.

jest.mock('../dialogs/recordImport/openRecordImport.js', () => ({ __esModule: true, openRecordImport: jest.fn(() => true) }))
jest.mock('../modals/object/l10n.js', () => ({ __esModule: true, t: (app, text) => text }))

/**
 * A drop event carrying these files.
 *
 * @param {Array<{name: string}>} files The files
 * @return {object} The event init
 */
function dropOf(files) {
	return { dataTransfer: { files, types: ['Files'] } }
}

describe('RecordFileDropTarget', () => {
	beforeEach(() => openRecordImport.mockClear())

	it('hands the first record file of a drop to the record import', async () => {
		const wrapper = mount(RecordFileDropTarget, { props: { register: 5, schema: 7 }, slots: { default: '<p>list</p>' } })
		const csv = { name: 'people.csv' }

		await wrapper.trigger('drop', dropOf([{ name: 'scan.pdf' }, csv]))

		expect(openRecordImport).toHaveBeenCalledTimes(1)
		expect(openRecordImport).toHaveBeenCalledWith({ file: csv, register: 5, schema: 7 })
		expect(wrapper.text()).toContain('list')
	})

	it('leaves a drop without a record file alone', async () => {
		const wrapper = mount(RecordFileDropTarget, { props: { register: 5, schema: 7 } })

		await wrapper.trigger('drop', dropOf([{ name: 'scan.pdf' }]))

		expect(openRecordImport).not.toHaveBeenCalled()
	})

	it('does nothing without a register and schema in scope', async () => {
		const wrapper = mount(RecordFileDropTarget, { props: { register: 5, schema: null } })

		await wrapper.trigger('dragover', dropOf([]))
		await wrapper.trigger('drop', dropOf([{ name: 'people.csv' }]))

		expect(openRecordImport).not.toHaveBeenCalled()
		expect(wrapper.find('[role="status"]').exists()).toBe(false)
	})

	it('shows the notice while files are dragged over it, and hides it on the drop', async () => {
		const wrapper = mount(RecordFileDropTarget, { props: { register: 5, schema: 7 } })

		await wrapper.trigger('dragover', dropOf([]))
		expect(wrapper.find('[role="status"]').exists()).toBe(true)

		await wrapper.trigger('drop', dropOf([]))
		expect(wrapper.find('[role="status"]').exists()).toBe(false)
	})
})
