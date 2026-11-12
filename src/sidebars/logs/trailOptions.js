/**
 * The select options the audit and search trail sidebars start with.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * One select option.
 *
 * @param {string} label The shown label
 * @param {string} value The filter value
 * @return {{label: string, value: string}} The option
 */
function selectOption(label, value) {
	return { label, value }
}

/**
 * The audit trail action filter's options.
 *
 * @spec exclude Presentational labels of the action filter.
 * @return {Array<{label: string, value: string}>} The options
 */
export function auditActionOptions() {
	return [
		selectOption(t('openregister', 'Create'), 'create'),
		selectOption(t('openregister', 'Read'), 'read'),
		selectOption(t('openregister', 'Update'), 'update'),
		selectOption(t('openregister', 'Delete'), 'delete'),
	]
}

/**
 * The search trail success status filter's options.
 *
 * @spec exclude Presentational labels of the success filter.
 * @return {Array<{label: string, value: string}>} The options
 */
export function searchSuccessOptions() {
	return [
		selectOption(t('openregister', 'Successful'), 'true'),
		selectOption(t('openregister', 'Failed'), 'false'),
	]
}

/**
 * The search trail activity chart's period options.
 *
 * @spec exclude Presentational labels of the activity periods.
 * @return {Array<{label: string, value: string}>} The options
 */
export function activityPeriodOptions() {
	return [
		selectOption(t('openregister', 'Hourly'), 'hourly'),
		selectOption(t('openregister', 'Daily'), 'daily'),
		selectOption(t('openregister', 'Weekly'), 'weekly'),
		selectOption(t('openregister', 'Monthly'), 'monthly'),
	]
}
