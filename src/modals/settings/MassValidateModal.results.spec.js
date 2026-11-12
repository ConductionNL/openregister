/**
 * The mass-validate modal's events and result formatting: the start, retry
 * and reset events, config syncing, execution time, memory prediction and
 * usage classes, error details and the success rate. Methods run against a
 * plain `this` built from the component's props.
 *
 * @spec exclude Client-side mass-validate modal formatting.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MassValidateModal from './MassValidateModal.vue'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))

/**
 * A plain modal instance: prop defaults, the given props, data and methods.
 *
 * @param {object} props Props to set
 * @return {object} The instance
 */
function modal(props = {}) {
	const vm = { $emit: jest.fn() }
	for (const [name, prop] of Object.entries(MassValidateModal.props)) {
		vm[name] = typeof prop.default === 'function' ? prop.default() : prop.default
	}
	Object.assign(vm, props)
	Object.assign(vm, MassValidateModal.data.call(vm))
	for (const [name, fn] of Object.entries(MassValidateModal.methods)) vm[name] = fn.bind(vm)
	return vm
}

describe('MassValidateModal events', () => {
	it('starts with a copy of the config and emits the local one', () => {
		const config = { mode: 'parallel', maxObjects: 10, batchSize: 5, collectErrors: true }
		const vm = modal({ config })
		expect(vm.localConfig).toEqual(config)
		expect(vm.localConfig).not.toBe(config)
		vm.startMassValidate()
		expect(vm.$emit).toHaveBeenCalledWith('start-validate', config)
		vm.retryValidation()
		vm.resetModal()
		expect(vm.$emit).toHaveBeenCalledWith('retry')
		expect(vm.$emit).toHaveBeenCalledWith('reset')
	})

	it('copies a new config in and reports local changes', () => {
		const vm = modal()
		MassValidateModal.watch.config.handler.call(vm, { mode: 'serial', maxObjects: 3 })
		expect(vm.localConfig).toEqual({ mode: 'serial', maxObjects: 3 })
		MassValidateModal.watch.localConfig.handler.call(vm, { a: 1 })
		expect(vm.$emit).toHaveBeenCalledWith('config-changed', { a: 1 })
	})
})

describe('MassValidateModal formatting', () => {
	it('formats execution times in ms, seconds or minutes', () => {
		const vm = modal()
		expect(vm.formatExecutionTime(0)).toBe('Unknown')
		expect(vm.formatExecutionTime(450)).toBe('450ms')
		expect(vm.formatExecutionTime(2500)).toBe('2.50s')
		expect(vm.formatExecutionTime(125000)).toBe('2m 5.0s')
	})

	it('describes the memory prediction', () => {
		expect(modal().formatMemoryPrediction()).toBe('Unknown / Unknown available')
		expect(modal({ memoryPrediction: { formatted: { total_predicted: '1 GB', available: '4 GB' } } }).formatMemoryPrediction()).toBe('1 GB / 4 GB available')
		expect(modal({ memoryPrediction: { error: 'x' } }).formatMemoryPrediction()).toBe('Unable to predict')
		expect(modal({ memoryPrediction: null }).formatMemoryPrediction()).toBe('Unable to predict')
		expect(modal({ memoryPrediction: {} }).formatMemoryPrediction()).toBe('Unknown')
	})

	it('classes memory usage by threshold', () => {
		const vm = modal()
		expect(vm.getMemoryUsageClass('95')).toBe('error')
		expect(vm.getMemoryUsageClass(90)).toBe('error')
		expect(vm.getMemoryUsageClass('80.5')).toBe('warning')
		expect(vm.getMemoryUsageClass(74.9)).toBe('')
	})

	it('shows detailed errors when there are details or a long message', () => {
		expect(modal().hasDetailedError()).toBe(false)
		expect(modal({ results: { error: 'short' } }).hasDetailedError()).toBe(false)
		expect(modal({ results: { error: 'x'.repeat(101) } }).hasDetailedError()).toBe(true)
		expect(modal({ results: { error_details: { line: 3 } } }).hasDetailedError()).toBe(true)
	})

	it('formats the error details', () => {
		expect(modal({ results: { error_details: 'trace' } }).formatErrorDetails()).toBe('trace')
		expect(modal({ results: { error_details: { line: 3 } } }).formatErrorDetails()).toBe('{\n  "line": 3\n}')
		expect(modal({ results: { error: 'boom' } }).formatErrorDetails()).toBe('boom')
		expect(modal().formatErrorDetails()).toBe('No detailed error information available')
	})

	it('computes and classes the success rate', () => {
		expect(modal().getSuccessRate()).toBe(0)
		expect(modal({ results: { stats: { successfulSaves: 0, totalObjects: 0 } } }).getSuccessRate()).toBe(100)
		expect(modal({ results: { stats: { successfulSaves: 19, totalObjects: 20 } } }).getSuccessRateClass()).toBe('success')
		expect(modal({ results: { stats: { successfulSaves: 17, totalObjects: 20 } } }).getSuccessRateClass()).toBe('warning')
		expect(modal({ results: { stats: { successfulSaves: 10, totalObjects: 20 } } }).getSuccessRateClass()).toBe('error')
	})
})
