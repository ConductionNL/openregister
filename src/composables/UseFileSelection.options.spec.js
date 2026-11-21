/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * useFileSelection beyond the drop itself: the accept filter it hands the
 * file dialog, empty drops, removing files and setting them from outside.
 *
 * @spec exclude Unit test of the client-side file selection composable.
 */
import { useDropZone, useFileDialog } from '@vueuse/core'
import { objectStore } from '../store/store.js'
import { useFileSelection } from './UseFileSelection.js'

jest.mock('@vueuse/core', () => ({
	__esModule: true,
	useDropZone: jest.fn(() => ({ isOverDropZone: 'over-ref' })),
	useFileDialog: jest.fn(),
}))
jest.mock('../store/store.js', () => ({
	__esModule: true,
	objectStore: { setAttachmentFile: jest.fn() },
}))

const open = jest.fn()
let dialogChange

beforeEach(() => {
	jest.clearAllMocks()
	useFileDialog.mockImplementation(() => ({
		onChange: (handler) => {
			dialogChange = handler
		},
		open,
	}))
})

/**
 * A plain file.
 *
 * @param {string} name The name
 * @return {File} The file
 */
function file(name) {
	return new File(['x'], name, { lastModified: 1 })
}

describe('useFileSelection options', () => {
	it.each([
		[undefined, '*'],
		['.pdf', '.pdf'],
		[['.pdf', 'image/*'], '.pdf,image/*'],
	])('hands the dialog accept %j as %s', (allowedFileTypes, accept) => {
		useFileSelection({ dropzone: 'zone', allowMultiple: true, allowedFileTypes })
		expect(useFileDialog).toHaveBeenCalledWith({ accept, multiple: true })
		expect(useDropZone).toHaveBeenCalledWith('zone', {
			dataTypes: '*',
			onDrop: expect.any(Function),
		})
	})

	it('exposes the drop zone state and the dialog opener', () => {
		const selection = useFileSelection({ dropzone: 'zone' })
		expect(selection.isOverDropZone).toBe('over-ref')
		selection.openFileUpload()
		expect(open).toHaveBeenCalledTimes(1)
	})

	it('ignores an empty or missing selection', () => {
		const onFileSelect = jest.fn()
		const selection = useFileSelection({ dropzone: 'zone', onFileSelect })
		dialogChange(null)
		dialogChange([])
		expect(selection.files.value).toBeNull()
		expect(onFileSelect).not.toHaveBeenCalled()
	})

	it('removes one file by name, and empties the list with the last one', () => {
		const selection = useFileSelection({ dropzone: 'zone', allowMultiple: true })
		dialogChange([file('a.txt'), file('b.txt')])
		selection.reset('a.txt')
		expect(selection.files.value.map((f) => f.name)).toEqual(['b.txt'])
		selection.reset('b.txt')
		expect(selection.files.value).toBeNull()
	})

	it('clears every file without a name', () => {
		const selection = useFileSelection({ dropzone: 'zone', allowMultiple: true })
		dialogChange([file('a.txt')])
		selection.reset()
		expect(selection.files.value).toBeNull()
	})

	it('takes files set from outside and clears the stored attachment', () => {
		const selection = useFileSelection({ dropzone: 'zone' })
		const files = [file('c.txt')]
		selection.setFiles(files)
		expect(selection.files.value).toEqual(files)
		expect(objectStore.setAttachmentFile).toHaveBeenCalledWith(null)
	})
})
