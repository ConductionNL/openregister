/**
 * Golden record of the upload modal's label handling: which labels can be
 * picked next to the current choice, the label value sent, the label lists
 * filled from the stored tags, and the initial state.
 *
 * @spec exclude Golden record of client-side label selection plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import UploadFiles from './UploadFiles.vue'
import { objectStore } from '../../store/store.js'

const mockLog = []

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		objectTypes: [],
		registerObjectType: (...args) =>
			mockLog.push(['registerObjectType', ...args]),
		uploadFiles: jest.fn(),
		fetchFiles: (...args) => mockLog.push(['fetchFiles', ...args]),
		fetchTags: jest.fn(),
	},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('./../../composables/UseFileSelection.js', () => ({
	useFileSelection: () => ({}),
}))
jest.mock('vue-material-design-icons/AlphaXCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Exclamation.vue', () => ({}))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Minus.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TagEdit.vue', () => ({}))
jest.mock('vue-material-design-icons/TrayArrowDown.vue', () => ({}))

/**
 * A bare modal context with the component's state and methods.
 *
 * @param {Array|undefined} chosen The chosen labels
 * @return {object} The context
 */
function modal(chosen) {
	const ctx = UploadFiles.data.call({})
	ctx.labelOptions.value = chosen
	for (const [name, method] of Object.entries(UploadFiles.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const CHOICES = {
	nothing: undefined,
	empty: [],
	noLabel: ['No label'],
	oneTag: ['t1'],
	noLabelAndTag: ['No label', 't1'],
}

describe('upload files labels golden', () => {
	it('starts with this state', () => {
		expect(UploadFiles.data.call({})).toMatchSnapshot()
	})

	for (const [name, chosen] of Object.entries(CHOICES)) {
		it(`selectable next to ${name}`, () => {
			const ctx = modal(chosen)
			expect(
				['No label', 't1', 't2'].map((option) => ctx.isSelectable(option)),
			).toMatchSnapshot()
		})

		it(`labels sent for ${name}`, () => {
			expect(modal(chosen).getLabels()).toMatchSnapshot()
		})
	}

	for (const [name, answer] of Object.entries({
		tags: () => Promise.resolve(['b', 'a', 'b', 'No label']),
		none: () => Promise.resolve([]),
	})) {
		it(`fills the label lists from ${name}`, async () => {
			objectStore.fetchTags.mockImplementation(answer)
			const ctx = modal(undefined)
			ctx.labelOptions.options = ['old']
			ctx.getAllTags()
			const during = ctx.tagsLoading
			await new Promise((resolve) => setTimeout(resolve, 0))
			expect({
				during,
				after: ctx.tagsLoading,
				labelOptions: ctx.labelOptions,
				labelOptionsEdit: ctx.labelOptionsEdit,
			}).toMatchSnapshot()
		})
	}
})
