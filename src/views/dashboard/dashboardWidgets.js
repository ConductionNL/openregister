import { translate as t } from '@nextcloud/l10n'

/**
 * The dashboard's static definitions: widget grid, widget list, table columns
 * and chart options.
 *
 * @spec exclude UI plumbing: static dashboard definitions for display.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

const NO_TITLE = { showTitle: false }
const FLUSH = { flush: true }

/** One tile per row: widget id, grid x, y, width, height, extra options. */
const LAYOUT_TILES = [
	// Row 1: structural totals (point-in-time).
	['count-objects', 0, 0, 4, 2, NO_TITLE],
	['count-registers', 4, 0, 4, 2, NO_TITLE],
	['count-schemas', 8, 0, 4, 2, NO_TITLE],
	// Row 2: activity over the selected date range (range-driven, carry a pill).
	['count-searches', 0, 2, 6, 2, NO_TITLE],
	['count-events', 6, 2, 6, 2, NO_TITLE],
	['popular-terms', 0, 4, 6, 4, FLUSH],
	['objects-by-register', 6, 4, 6, 4, FLUSH],
	['objects-by-schema', 0, 8, 6, 4, FLUSH],
	['objects-chart', 6, 8, 6, 4],
	// Row 4: the viewer's task inbox (flow-task-inbox-projections 5.1).
	['my-tasks', 0, 12, 6, 4],
]

/**
 * A grid tile from its row in the table.
 *
 * @param {Array} tile The row
 * @param {number} index The row's position
 * @return {object} The tile
 */
function layoutTile(tile, index) {
	const [widgetId, gridX, gridY, gridWidth, gridHeight, options] = tile
	return {
		id: index + 1,
		widgetId,
		gridX,
		gridY,
		gridWidth,
		gridHeight,
		...options,
	}
}

export const DEFAULT_LAYOUT = LAYOUT_TILES.map(layoutTile)

/**
 * A widget filled by a slot of the dashboard page.
 *
 * @param {string} id The widget id
 * @param {string} title The translated title
 * @return {object} The widget
 */
function customWidget(id, title) {
	return { id, title, type: 'custom' }
}

/**
 * A slot widget without padding around its content.
 *
 * @param {string} id The widget id
 * @param {string} title The translated title
 * @return {object} The widget
 */
function flushWidget(id, title) {
	return { ...customWidget(id, title), flush: true }
}

/**
 * The KPI widgets.
 *
 * @return {Array<object>} The widgets
 */
function kpiWidgets() {
	return [
		customWidget('count-objects', t('openregister', 'Objects')),
		customWidget('count-registers', t('openregister', 'Registers')),
		customWidget('count-schemas', t('openregister', 'Schemas')),
		customWidget('count-searches', t('openregister', 'Searches')),
		customWidget('count-events', t('openregister', 'Events')),
	]
}

/**
 * The table and chart widgets.
 *
 * @return {Array<object>} The widgets
 */
function panelWidgets() {
	return [
		flushWidget('popular-terms', t('openregister', 'Popular Search Terms')),
		flushWidget('objects-by-register', t('openregister', 'Objects by Register')),
		flushWidget('objects-by-schema', t('openregister', 'Objects by Schema')),
		customWidget('objects-chart', t('openregister', 'Objects Distribution')),
	]
}

/**
 * The library widget (CnTasksWidget, registered as the `tasks` dashboard type
 * by nextcloud-vue 2.30.0): the viewer's open tasks read from the flow-tasks
 * inbox, with claim and complete offered per row. No slot template on purpose:
 * CnDashboardPage resolves the type from the dashboard widget registry and
 * hands it this content.
 *
 * @return {object} The widget
 */
function tasksWidget() {
	return {
		id: 'my-tasks',
		title: t('openregister', 'Tasks'),
		type: 'tasks',
		content: { scope: 'assigned', limit: 6, pollSeconds: 30 },
	}
}

/**
 * Widget definitions for the dashboard grid.
 *
 * @return {Array<object>} The widgets
 */
export function dashboardWidgets() {
	return [...kpiWidgets(), ...panelWidgets(), tasksWidget()]
}

/**
 * The right-aligned count column of a table.
 *
 * @param {string} label The translated header
 * @return {object} The column
 */
function countColumn(label) {
	return { key: 'count', label, sortable: true, align: 'right' }
}

/**
 * Columns for the Popular Search Terms table.
 *
 * @return {Array<object>} The columns
 */
export function termColumns() {
	return [
		{ key: 'term', label: t('openregister', 'Search Term'), sortable: true },
		countColumn(t('openregister', 'Count')),
		{ key: 'effectiveness', label: t('openregister', 'Effectiveness') },
	]
}

/**
 * Column definitions for an "Objects by X" table (Register/Schema).
 *
 * @param {string} label The first column header (e.g. Register or Schema).
 * @return {Array<object>} The columns
 */
export function objectColumns(label) {
	return [
		{ key: 'label', label, sortable: true },
		countColumn(t('openregister', 'Objects')),
	]
}

/**
 * Table rows from a labels-and-series chart payload.
 *
 * @param {object} [chartData] The chart payload
 * @return {Array<object>} One { label, count } per label
 */
export function chartRows(chartData) {
	if (!chartData?.labels || !chartData?.series) return []
	return chartData.labels.map((label, i) => ({
		label,
		count: chartData.series[i] || 0,
	}))
}

/**
 * ApexCharts options for the objects-by-register distribution pie.
 *
 * @param {Array<string>} labels The slice labels
 * @return {object} The options
 */
export function pieChartOptions(labels) {
	const legend = { position: 'bottom' }
	const small = { chart: { width: 200 }, legend }
	return {
		chart: { type: 'pie' },
		labels,
		legend,
		responsive: [{ breakpoint: 480, options: small }],
	}
}
