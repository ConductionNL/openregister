import MassValidateModal from './MassValidateModal.vue'

/**
 * Golden record of the mass-validation duration estimate: how many objects
 * it counts (all, or the configured maximum), the batches, the seconds per
 * batch in serial and parallel mode, and the wording in seconds, minutes and
 * hours.
 *
 * @spec exclude Golden record of a client-side estimate.
 */

jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ __esModule: true, default: {} }))

const CASES = [
	['no objects', 0, { maxObjects: 0, batchSize: 100, mode: 'serial' }],
	['a few objects in parallel', 50, { maxObjects: 0, batchSize: 100, mode: 'parallel' }],
	['a minute of serial batches', 1500, { maxObjects: 0, batchSize: 100, mode: 'serial' }],
	['several minutes', 10000, { maxObjects: 0, batchSize: 100, mode: 'parallel' }],
	['exactly one minute', 3000, { maxObjects: 0, batchSize: 100, mode: 'parallel' }],
	['hours', 200000, { maxObjects: 0, batchSize: 100, mode: 'serial' }],
	['a maximum below the total', 200000, { maxObjects: 500, batchSize: 100, mode: 'serial' }],
	['a maximum above the total', 300, { maxObjects: 5000, batchSize: 100, mode: 'serial' }],
	['exactly an hour', 180000, { maxObjects: 0, batchSize: 100, mode: 'parallel' }],
]

describe('MassValidateModal.estimateValidationDuration', () => {
	it.each(CASES)('estimates %s', (label, totalObjects, localConfig) => {
		const estimate = MassValidateModal.methods.estimateValidationDuration.call({
			objectStats: { totalObjects },
			localConfig,
		})
		expect(estimate).toMatchSnapshot()
	})
})
