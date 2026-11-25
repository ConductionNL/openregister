import { dryRunImport, proposeMapping, runImport, watchImportProgress } from '../../services/recordImport.js'
import { navigationStore } from '../../store/store.js'
import RecordImportDialog from './RecordImportDialog.vue'

// The dialog: mapping confirmation, dry run vs import, progress and the result view.

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../modals/object/l10n.js', () => ({
	__esModule: true,
	t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => String(vars[key])),
	n: (app, singular, plural, count, vars = {}) => (count === 1 ? singular : plural).replace(/\{(\w+)\}/g, (_, key) => String(vars[key])),
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn(), clearTransferData: jest.fn(), getTransferData: jest.fn(() => null) },
}))
jest.mock('../../services/recordImport.js', () => {
	const actual = jest.requireActual('../../services/recordImport.js')
	return { ...actual, proposeMapping: jest.fn(), dryRunImport: jest.fn(), runImport: jest.fn(), watchImportProgress: jest.fn(() => jest.fn()) }
})

const FILE = { name: 'people.csv' }
const PROPOSAL = { columns: ['ref', 'Plaats', 'postcode'], properties: ['reference', 'city'], mapped: { ref: 'reference', Plaats: 'city' }, unmatched: ['postcode'] }

/**
 * A bare dialog context: its data, computed values as getters, and bound methods.
 *
 * @return {object} The context
 */
function context() {
	navigationStore.getTransferData.mockReturnValue({ recordImport: { file: FILE, register: 5, schema: 7 } })
	const ctx = RecordImportDialog.data()
	for (const [name, getter] of Object.entries(RecordImportDialog.computed)) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx) })
	}
	for (const [name, method] of Object.entries(RecordImportDialog.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe('RecordImportDialog', () => {
	beforeEach(() => jest.clearAllMocks())

	it('loads the proposal and keeps the import disabled while a column is undecided', async () => {
		proposeMapping.mockResolvedValue(PROPOSAL)
		const ctx = context()

		await ctx.loadProposal()

		expect(proposeMapping).toHaveBeenCalledWith(expect.objectContaining({ file: FILE, register: 5, schema: 7 }))
		expect(ctx.undecided).toEqual(['postcode'])
		expect(ctx.canRun).toBe(false)

		await ctx.run(false)
		expect(runImport).not.toHaveBeenCalled()
	})

	it('dry-runs with the confirmed decisions, an ignored column sent as null', async () => {
		proposeMapping.mockResolvedValue(PROPOSAL)
		dryRunImport.mockResolvedValue({ dryRun: true, createdCount: 1, updatedCount: 1, failedCount: 0, failures: [] })
		const ctx = context()
		await ctx.loadProposal()

		ctx.setDecision('postcode', ctx.IGNORE)
		ctx.identifier = 'reference'
		expect(ctx.canRun).toBe(true)
		await ctx.run(true)

		expect(runImport).not.toHaveBeenCalled()
		expect(dryRunImport).toHaveBeenCalledWith(expect.objectContaining({
			decisions: { ref: 'reference', Plaats: 'city', postcode: null },
			identifier: 'reference',
		}))
		expect(ctx.result.dryRun).toBe(true)
		expect(ctx.busy).toBe(false)
	})

	it('imports create-only without an identifier, and offers the failed rows', async () => {
		proposeMapping.mockResolvedValue(PROPOSAL)
		runImport.mockResolvedValue({
			dryRun: false,
			createdCount: 2,
			failedCount: 1,
			failures: [{ line: 3, reason: 'invalid' }, { line: null, reason: 'unplaced' }],
			failedRows: 'ref,reason\nA2,invalid\n',
			referenceCacheHits: 4,
			referenceCacheMisses: 1,
			durationSeconds: 1.5,
		})
		const ctx = context()
		await ctx.loadProposal()
		ctx.setDecision('postcode', ctx.IGNORE)

		await ctx.run(false)

		expect(runImport).toHaveBeenCalledWith(expect.objectContaining({ identifier: null }))
		expect(ctx.resultRows).toEqual([
			{ label: 'Created', value: 2 },
			{ label: 'Updated', value: 0 },
			{ label: 'Unchanged', value: 0 },
			{ label: 'Failed', value: 1 },
			{ label: 'Reference cache hits', value: 4 },
			{ label: 'Reference cache misses', value: 1 },
			{ label: 'Duration (seconds)', value: '1.50' },
		])
		expect(ctx.shownFailures.map((failure) => ctx.failureText(failure))).toEqual(['Line 3: invalid', 'unplaced'])
		expect(ctx.failedFile.name).toBe('people-failed.csv')
	})

	it('drops the identifier when its column is unmapped again', async () => {
		proposeMapping.mockResolvedValue(PROPOSAL)
		const ctx = context()
		await ctx.loadProposal()
		ctx.identifier = 'reference'

		ctx.setDecision('ref', '')

		expect(ctx.identifier).toBe('')
		expect(ctx.undecided).toEqual(['ref', 'postcode'])
		expect(ctx.selectValue('ref')).toBe('')
	})

	it('shows the server message of a refused request and stops being busy', async () => {
		proposeMapping.mockRejectedValue({ response: { data: { error: '"notes.pdf" is not a record file' } } })
		const ctx = context()

		await ctx.loadProposal()

		expect(ctx.error).toBe('"notes.pdf" is not a record file')
		expect(ctx.busy).toBe(false)
		expect(ctx.proposal).toBeNull()
	})

	it('tracks upload progress during a request', async () => {
		let report
		proposeMapping.mockImplementation(({ onProgress }) => {
			report = onProgress
			return new Promise(() => {})
		})
		const ctx = context()

		ctx.loadProposal()
		await Promise.resolve()
		expect(ctx.busy).toBe(true)
		report(0.5)
		expect(ctx.uploaded).toBe(0.5)
	})

	it('refuses to close while busy, and clears the handoff when it closes', () => {
		const ctx = context()
		ctx.busy = true
		ctx.close()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()

		ctx.busy = false
		ctx.close()
		expect(navigationStore.clearTransferData).toHaveBeenCalled()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('says so when no file was handed over', async () => {
		navigationStore.getTransferData.mockReturnValue(null)
		const ctx = RecordImportDialog.data()
		ctx.loadProposal = RecordImportDialog.methods.loadProposal.bind(ctx)

		await ctx.loadProposal()

		expect(proposeMapping).not.toHaveBeenCalled()
		expect(ctx.error).toBe('No record file was handed to the import.')
	})

	it('watches the run under its own progress token, shows the rows read and stops watching after', async () => {
		proposeMapping.mockResolvedValue(PROPOSAL)
		const stop = jest.fn()
		let onRows
		watchImportProgress.mockImplementation((options) => {
			onRows = options.onRows
			return stop
		})
		let finish
		runImport.mockImplementation(() => new Promise((resolve) => {
			finish = resolve
		}))
		const ctx = context()
		await ctx.loadProposal()
		ctx.setDecision('postcode', ctx.IGNORE)

		const running = ctx.run(false)
		await Promise.resolve()
		onRows({ rows: 150, done: false })

		expect(ctx.rowsRead).toBe(150)
		const token = watchImportProgress.mock.calls[0][0].token
		expect(token).toMatch(/^[0-9a-f]{32}$/)
		expect(runImport).toHaveBeenCalledWith(expect.objectContaining({ progressToken: token }))
		expect(stop).not.toHaveBeenCalled()

		finish({ createdCount: 3, failedCount: 0, failures: [] })
		await running
		expect(stop).toHaveBeenCalledTimes(1)
		expect(ctx.busy).toBe(false)
	})

	it('stops watching the progress when the run fails', async () => {
		proposeMapping.mockResolvedValue(PROPOSAL)
		const stop = jest.fn()
		watchImportProgress.mockReturnValue(stop)
		dryRunImport.mockRejectedValue({ response: { data: { error: 'Nope' } } })
		const ctx = context()
		await ctx.loadProposal()
		ctx.setDecision('postcode', ctx.IGNORE)

		await ctx.run(true)

		expect(stop).toHaveBeenCalledTimes(1)
		expect(ctx.error).toBe('Nope')
	})
})
