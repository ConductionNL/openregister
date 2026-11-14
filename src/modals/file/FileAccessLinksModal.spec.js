/**
 * Sharing a file row by link: the file subject the modal names, and the row
 * action that opens it only for a file an object carries.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import FilesIndex from '../../views/files/FilesIndex.vue'
import FileAccessLinksModal from './FileAccessLinksModal.vue'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppContent: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
	NcModal: {},
}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

describe('FileAccessLinksModal', () => {
	it('names the file as <object uuid>/<file id>', () => {
		const subject = FileAccessLinksModal.computed.fileSubject.call({ file: { id: 42, objectUuid: 'obj-1' } })

		expect(subject).toBe('obj-1/42')
	})
})

describe('FilesIndex row actions', () => {
	/**
	 * The share action of a row, with the component context it runs in.
	 *
	 * @param {object} file The row
	 * @return {{action: object, context: object}} The action and its context
	 */
	function shareAction(file) {
		const context = { sharingFile: null, retryExtraction: jest.fn(), showError: jest.fn() }
		const actions = FilesIndex.methods.fileActions.call(context, file)
		return { action: actions.find((a) => a.label === 'Share by link'), context }
	}

	it('offers sharing for a file an object carries, and opens the modal on it', () => {
		const file = { id: 42, objectUuid: 'obj-1', extractionStatus: 'completed' }
		const { action, context } = shareAction(file)

		expect(action.hidden).toBe(false)
		action.run()
		expect(context.sharingFile).toBe(file)
	})

	it('hides sharing for a file no object carries', () => {
		const { action } = shareAction({ id: 43, extractionStatus: 'completed' })

		expect(action.hidden).toBe(true)
	})
})
