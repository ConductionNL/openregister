import { navigationStore } from '../../store/store.js'
import { openRecordImport, recordImportHandoff } from './openRecordImport.js'

// The handoff from a drop target to the record-import dialog.

jest.mock('../../store/store.js', () => {
	const store = { transferData: null }
	store.setTransferData = jest.fn((data) => {
		store.transferData = data
	})
	store.getTransferData = jest.fn(() => store.transferData)
	store.setDialog = jest.fn()
	return { navigationStore: store }
})

describe('openRecordImport', () => {
	beforeEach(() => {
		navigationStore.transferData = null
		navigationStore.setDialog.mockClear()
	})

	it('hands a record file and its target to the dialog', () => {
		const file = { name: 'people.csv' }

		expect(openRecordImport({ file, register: 5, schema: 7 })).toBe(true)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('recordImport')
		expect(recordImportHandoff()).toEqual({ file, register: 5, schema: 7 })
	})

	it.each([
		['a file that is not a record file', { file: { name: 'scan.pdf' }, register: 5, schema: 7 }],
		['no register', { file: { name: 'people.csv' }, register: null, schema: 7 }],
		['no schema', { file: { name: 'people.csv' }, register: 5, schema: '' }],
	])('opens nothing for %s', (_, handoff) => {
		expect(openRecordImport(handoff)).toBe(false)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		expect(recordImportHandoff()).toBeNull()
	})
})
