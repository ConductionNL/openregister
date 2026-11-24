/**
 * Mass-validating the selected objects: each row is saved as it is (fetched
 * first when the list did not load it), the outcome is reported, and the
 * dialog closes by itself only when every object was saved. Runs on the
 * options object against a synthetic `this`, with the object store mocked.
 *
 * @spec exclude Client-side mass-validate dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MassValidateObjects from './MassValidateObjects.vue'
import { refreshObjectLists } from './selectedObjectsDialog.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count, vars) => (count === 1 ? one : many).replace('{count}', vars.count),
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	objectStore: { currentType: 'r-s', fetchObject: jest.fn(), saveObject: jest.fn() },
}))
jest.mock('./selectedObjectsDialog.js', () => ({ __esModule: true, default: {}, refreshObjectLists: jest.fn() }))
jest.mock('./SelectedObjectsDialogFrame.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

/**
 * A dialog context over the given selection, with the real methods bound.
 *
 * @param {Array<object>} selectedObjects The selected rows
 * @return {object} The context
 */
function dialog(selectedObjects) {
	const ctx = { ...MassValidateObjects.data(), selectedObjects }
	for (const [name, fn] of Object.entries(MassValidateObjects.methods)) ctx[name] = fn.bind(ctx)
	return ctx
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	objectStore.saveObject.mockImplementation(async (type, row) => row)
})

afterEach(() => {
	console.error.mockRestore()
	jest.useRealTimers()
})

describe('MassValidateObjects — validating the selection', () => {
	it('saves loaded rows as they are, fetches the others first, and closes after two seconds', async () => {
		objectStore.fetchObject.mockResolvedValue({ '@self': {}, title: 'Fetched' })
		const ctx = dialog([{ id: 1, '@self': {}, title: 'Loaded' }, { id: 2 }])

		await ctx.validateObjects()

		expect(objectStore.fetchObject).toHaveBeenCalledTimes(1)
		expect(objectStore.fetchObject).toHaveBeenCalledWith('r-s', 2)
		expect(objectStore.saveObject.mock.calls.map(([, row]) => [row.id, row.title])).toEqual([[1, 'Loaded'], [2, 'Fetched']])
		expect(ctx).toMatchObject({ loading: false, success: true, error: false, validatedCount: 2 })
		expect(refreshObjectLists).toHaveBeenCalledTimes(1)

		jest.advanceTimersByTime(1999)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('reports how many failed and stays open when some cannot be saved', async () => {
		objectStore.fetchObject.mockResolvedValueOnce(null).mockRejectedValueOnce(new Error('gone'))
		objectStore.saveObject.mockImplementation(async (type, row) => (row.id === 4 ? null : row))
		const ctx = dialog([{ id: 1, '@self': {} }, { id: 2 }, { id: 3 }, { id: 4, '@self': {} }])

		await ctx.validateObjects()
		jest.advanceTimersByTime(5000)

		expect(ctx).toMatchObject({ success: true, validatedCount: 1, error: '3 objects could not be validated' })
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('reports a single failure in the singular and refreshes nothing when none saved', async () => {
		objectStore.saveObject.mockResolvedValue(null)
		const ctx = dialog([{ id: 1, '@self': {} }])

		await ctx.validateObjects()

		expect(ctx).toMatchObject({ success: null, validatedCount: 0, error: '1 object could not be validated' })
		expect(refreshObjectLists).not.toHaveBeenCalled()
	})
})
