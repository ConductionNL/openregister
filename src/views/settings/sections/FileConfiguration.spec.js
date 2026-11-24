/**
 * The file configuration page's disclosure per detection backend
 * (anonymisation-discloses-itself, tasks 2.3 and 3.2): measured figures for
 * the current detector version or "not measured", and the training use and
 * retention an administrator states for an external backend.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import FileConfiguration from './FileConfiguration.vue'
import { accuracyOf, detectorLabel, isRetention, percent, statementsPayload } from './anonymisationDisclosure.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `[${text}]` }))

const { computed, methods } = FileConfiguration

const REGEX_1 = { engine: 'regex', engineVersion: 'unknown', model: 'unknown', modelVersion: 'unknown', patternSetVersion: '1.0.0' }
const REGEX_2 = { ...REGEX_1, patternSetVersion: '2.0.0' }
const MEASURED = {
	corpus: 'nl-woo-synthetic',
	corpusVersion: '1',
	detector: REGEX_1,
	measuredAt: '2026-11-14T10:00:00+00:00',
	current: true,
	types: { EMAIL: { precision: 0.8, recall: 1.0, support: 8 }, PERSON: { precision: null, recall: 0.0, support: 14 } },
}

/**
 * A backend-state with the regex backend (product-declared) and a configured Presidio.
 *
 * @param {object} regex Overrides for the regex backend.
 * @param {object} presidio Overrides for the Presidio backend.
 * @return {object}
 */
function backendState(regex = {}, presidio = {}) {
	return {
		activeMethod: 'regex',
		effectiveMethod: 'regex',
		backends: {
			regex: { name: 'regex', configured: true, detector: REGEX_1, statement: { trainingUse: 'excluded', retention: 'none', declaredBy: 'product' }, ...regex },
			presidio: { name: 'presidio', configured: true, detector: { engine: 'presidio', model: 'nl_core_news_lg', modelVersion: '3.7' }, statement: { trainingUse: 'unknown', retention: 'unknown', declaredBy: 'administrator' }, ...presidio },
			llm: { name: 'llm', configured: false, statement: { trainingUse: 'unknown', retention: 'unknown', declaredBy: 'administrator' } },
			hybrid: { name: 'hybrid', configured: true, statement: { trainingUse: 'unknown', retention: 'unknown', declaredBy: 'administrator' } },
		},
	}
}

/**
 * A section instance with its methods and disclosure computeds bound to it.
 *
 * @param {object} state The backend state.
 * @param {object} store The settings store.
 * @return {object} The instance
 */
function section(state, store = {}) {
	const vm = { ...FileConfiguration.data(), settingsStore: store, backendState: state }
	for (const [name, fn] of Object.entries(methods)) vm[name] = fn.bind(vm)
	for (const name of ['disclosureRows', 'trainingUses']) {
		Object.defineProperty(vm, name, { get: computed[name].bind(vm) })
	}
	return vm
}

describe('FileConfiguration measured accuracy', () => {
	it('shows the figures of the current detector version with the corpus and the date', () => {
		const [regex] = section(backendState({ evaluation: MEASURED })).disclosureRows

		expect(regex.accuracy).toEqual({
			state: 'measured',
			corpus: 'nl-woo-synthetic',
			corpusVersion: '1',
			detector: 'regex 1.0.0',
			measuredAt: '2026-11-14',
			types: [
				{ type: 'EMAIL', precision: '80%', recall: '100%', support: 8 },
				{ type: 'PERSON', precision: '—', recall: '0%', support: 14 },
			],
		})
	})

	it('says the current version is not measured instead of showing old figures', () => {
		const stale = { ...MEASURED, current: false, types: undefined }
		const [regex] = section(backendState({ detector: REGEX_2, evaluation: stale })).disclosureRows

		expect(regex.accuracy).toEqual({ state: 'notMeasured', detector: 'regex 2.0.0' })
	})

	it('says a backend that was never measured is not measured', () => {
		expect(accuracyOf({ detector: { engine: 'presidio', model: 'nl_core_news_lg', modelVersion: '3.7' } })).toEqual({ state: 'notMeasured', detector: 'nl_core_news_lg 3.7' })
	})

	it('describes the configured or active backends, never the composed hybrid one', () => {
		expect(section(backendState()).disclosureRows.map((row) => [row.name, row.editable])).toEqual([['regex', false], ['presidio', true]])
	})
})

describe('FileConfiguration training use and retention', () => {
	it('shows an external backend as unknown until someone states it, and lets it be set', () => {
		const [, presidio] = section(backendState()).disclosureRows

		expect(presidio.statement).toEqual({ trainingUse: 'unknown', retention: 'unknown' })
		expect(presidio.editable).toBe(true)
	})

	it('saves the stated values with the other administrator statements and reloads the state', async () => {
		const store = { saveFileSettings: jest.fn().mockResolvedValue({}), getAnonymisationBackendState: jest.fn().mockResolvedValue(backendState()) }
		const vm = section(backendState({}, {}), store)
		const [, presidio] = vm.disclosureRows

		vm.setStatementDraft(presidio, 'trainingUse', 'excluded')
		vm.setStatementDraft(presidio, 'retention', ' P30D ')
		await vm.saveStatement(presidio)

		const sent = store.saveFileSettings.mock.calls[0][0]
		expect(sent.anonymisation).toEqual({
			statements: {
				presidio: { trainingUse: 'excluded', retention: 'P30D' },
				llm: { trainingUse: 'unknown', retention: 'unknown' },
			},
		})
		expect(store.getAnonymisationBackendState).toHaveBeenCalled()
		expect(vm.statementDrafts).toEqual({})
		expect(vm.statementErrors.presidio).toBe('')
	})

	it('refuses a retention the server would not keep and sends nothing', async () => {
		const store = { saveFileSettings: jest.fn() }
		const vm = section(backendState(), store)
		const [, presidio] = vm.disclosureRows

		vm.setStatementDraft(presidio, 'retention', 'thirty days')
		await vm.saveStatement(presidio)

		expect(store.saveFileSettings).not.toHaveBeenCalled()
		expect(vm.statementErrors.presidio).toBe('[Retention is none, unknown or an ISO 8601 duration such as P30D.]')
	})

	it('names a failed save', async () => {
		const store = { saveFileSettings: jest.fn().mockRejectedValue(new Error('500')) }
		const vm = section(backendState(), store)
		const [, presidio] = vm.disclosureRows

		await vm.saveStatement(presidio)

		expect(vm.statementErrors.presidio).toBe('[The statement could not be saved.]')
		expect(vm.savingStatement).toBeNull()
	})
})

describe('anonymisationDisclosure helpers', () => {
	it('reads the retention values the server keeps', () => {
		expect(['none', 'unknown', 'P30D', 'P1Y6M', 'PT12H'].every(isRetention)).toBe(true)
		expect(['', 'P', 'thirty days', 'P1H'].some(isRetention)).toBe(false)
	})

	it('names the detector by its pattern set, its model, or its engine', () => {
		expect(detectorLabel(REGEX_1)).toBe('regex 1.0.0')
		expect(detectorLabel({ engine: 'openanonymiser', engineVersion: '0.9', model: 'unknown' })).toBe('openanonymiser 0.9')
		expect(detectorLabel(null)).toBe('unknown')
		expect(percent(0.456)).toBe('46%')
		expect(percent(null)).toBe('—')
	})

	it('never sends the product\'s own statements', () => {
		expect(Object.keys(statementsPayload(backendState(), 'presidio', { trainingUse: 'permitted', retention: 'none' }))).toEqual(['presidio', 'llm'])
	})
})
