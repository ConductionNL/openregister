/**
 * The anonymisation pipeline list reads the admin report and names each step's state.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import AnonymisationPipelineList from './AnonymisationPipelineList.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))

/**
 * A list instance over plain state.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function list(state = {}) {
	const vm = { ...AnonymisationPipelineList.data(), ...state }
	for (const [name, method] of Object.entries(AnonymisationPipelineList.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('AnonymisationPipelineList', () => {
	it('reads the steps from the admin pipeline route', async () => {
		const steps = [
			{ step: 'anonymiser', label: 'x', state: 'on', setting: 'entityRecognitionEnabled' },
			{ step: 'pdf-metadata-sanitiser', label: 'y', state: 'always-on', setting: null },
		]
		axios.get.mockResolvedValue({ data: { steps } })
		const vm = list()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/admin/anonymisation/pipeline')
		expect(vm.steps).toEqual(steps)
		expect(vm.error).toBe(false)
		expect(vm.loading).toBe(false)
	})

	it('shows an error and no steps when the report cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const vm = list({ steps: [{ step: 'stale' }] })

		await vm.load()

		expect(vm.steps).toEqual([])
		expect(vm.error).toBe(true)
		expect(vm.loading).toBe(false)
	})

	it('keeps an empty list when the answer has no steps array', async () => {
		axios.get.mockResolvedValue({ data: { steps: 'nope' } })
		const vm = list()

		await vm.load()

		expect(vm.steps).toEqual([])
		expect(vm.error).toBe(false)
	})

	it('names a step that is off by its setting', () => {
		const vm = list()

		expect(vm.stateLabel({ step: 'anonymiser', state: 'off', setting: 'entityRecognitionEnabled' }))
			.toBe('Off (setting: Entity Recognition)')
		expect(vm.stateLabel({ step: 'new-step', state: 'off', setting: 'someSetting' }))
			.toBe('Off (setting: someSetting)')
		expect(vm.stateLabel({ step: 'new-step', state: 'off', setting: null })).toBe('Off')
	})

	it('names on and always-on states', () => {
		const vm = list()

		expect(vm.stateLabel({ state: 'on' })).toBe('On')
		expect(vm.stateLabel({ state: 'always-on' })).toBe('Always on')
	})

	it('translates known steps and keeps the server label for an unknown one', () => {
		const vm = list()

		expect(vm.stepLabel({ step: 'pdf-metadata-sanitiser', label: 'server' })).toBe('PDF metadata sanitiser')
		expect(vm.stepLabel({ step: 'odt-sanitiser', label: 'server' })).toBe('ODT sanitiser')
		expect(vm.stepLabel({ step: 'xlsx-sanitiser', label: 'XLSX sanitiser' })).toBe('XLSX sanitiser')
		expect(vm.stepLabel({ step: 'bare' })).toBe('bare')
	})
})
