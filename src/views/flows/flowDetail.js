/**
 * What the flow overview and the run page derive from the flow and run
 * records: step order, the failed step, durations and run counts.
 *
 * Plain functions over API payloads, kept out of the two views so they are
 * tested without mounting a page. Nothing here fetches.
 *
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'

/**
 * Statuses a run still advances from: what "running" means to a person.
 * Mirrors FlowRun::ACTIVE on the server.
 */
export const RUN_ACTIVE_STATUSES = [
	'queued',
	'running',
	'suspended',
	'awaiting_consent',
]

/**
 * Statuses the retry endpoint accepts. Mirrors FlowRun::TERMINAL.
 */
export const RUN_TERMINAL_STATUSES = [
	'completed',
	'stopped',
	'dead_letter',
	'failed',
]

/**
 * Statuses that count as a failure.
 */
export const RUN_FAILED_STATUSES = ['failed', 'dead_letter']

/**
 * The badge variant for a run or lifecycle status.
 *
 * @param {string} status The status.
 * @return {string} A CnStatusBadge variant.
 */
export function statusVariant(status) {
	switch (status) {
		case 'completed':
		case 'published':
		case 'enabled':
			return 'success'
		case 'failed':
		case 'dead_letter':
			return 'error'
		case 'suspended':
		case 'awaiting_consent':
		case 'draft':
			return 'warning'
		case 'queued':
		case 'running':
			return 'info'
		default:
			return 'default'
	}
}

/**
 * The words for a run or lifecycle status.
 *
 * @param {string} status The status.
 * @return {string} The translated label, or the raw status when unknown.
 */
export function statusLabel(status) {
	switch (status) {
		case 'completed':
			return t('openregister', 'Completed')
		case 'failed':
			return t('openregister', 'Failed')
		case 'dead_letter':
			return t('openregister', 'Gave up')
		case 'stopped':
			return t('openregister', 'Stopped')
		case 'suspended':
			return t('openregister', 'Waiting')
		case 'awaiting_consent':
			return t('openregister', 'Waiting for consent')
		case 'queued':
			return t('openregister', 'Queued')
		case 'running':
			return t('openregister', 'Running')
		case 'published':
			return t('openregister', 'Published')
		case 'draft':
			return t('openregister', 'Draft')
		case 'deprecated':
			return t('openregister', 'Deprecated')
		default:
			return String(status || '')
	}
}

/**
 * What starts a flow, or what started a run, in words.
 *
 * @param {string} trigger The flow or run `trigger`.
 * @return {string} The translated sentence, or the raw trigger when unknown.
 */
export function triggerLabel(trigger) {
	switch (trigger) {
		case 'object.created':
			return t('openregister', 'An object is created')
		case 'object.updated':
			return t('openregister', 'An object changes')
		case 'object.deleted':
			return t('openregister', 'An object is deleted')
		case 'schedule':
			return t('openregister', 'On a schedule')
		case 'manual':
			return t('openregister', 'Someone starts it')
		case 'test':
			return t('openregister', 'A test run')
		default:
			return String(trigger || '')
	}
}

/**
 * The node catalogue as a map from node type to its display name.
 *
 * @param {Array<object>} catalog Rows from `GET /api/flow/node-catalog`.
 * @return {Object<string, string>} Type to display name.
 */
export function catalogNames(catalog) {
	const names = {}
	for (const entry of Array.isArray(catalog) ? catalog : []) {
		if (entry?.id && entry?.displayName) {
			names[entry.id] = entry.displayName
		}
	}
	return names
}

/**
 * The label a person reads for one node.
 *
 * @param {object} node The flow node.
 * @param {Object<string, string>} names Type to display name.
 * @return {string} The label.
 */
export function nodeLabel(node, names = {}) {
	return (
		node?.label
		|| node?.name
		|| names[node?.type]
		|| node?.type
		|| node?.id
		|| ''
	)
}

/**
 * The second line under a node: what it reads or writes, else its id.
 *
 * @param {object} node The flow node.
 * @return {string} `register › schema`, or the node id.
 */
export function nodeDetail(node) {
	const register = node?.config?.register
	const schema = node?.config?.schema
	if (register && schema) {
		return `${register} › ${schema}`
	}
	return node?.id || ''
}

/**
 * The nodes in graph order: start at the nodes nothing points at, then
 * follow the edges breadth first. Nodes the walk never reaches keep their
 * stored order at the end, so nothing silently disappears.
 *
 * @param {Array<object>} nodes The flow's nodes.
 * @param {Array<object>} edges The flow's edges (`from`/`to`, or `source`/`target`).
 * @return {Array<object>} The nodes, ordered.
 */
export function orderedNodes(nodes, edges) {
	const list = Array.isArray(nodes) ? nodes.filter((node) => node?.id) : []
	const links = (Array.isArray(edges) ? edges : [])
		.map((edge) => ({
			from: edge?.from ?? edge?.source,
			to: edge?.to ?? edge?.target,
		}))
		.filter((edge) => edge.from && edge.to)

	const byId = new Map(list.map((node) => [node.id, node]))
	const pointedAt = new Set(links.map((edge) => edge.to))
	const queue = list
		.filter((node) => !pointedAt.has(node.id))
		.map((node) => node.id)
	const seen = new Set()
	const ordered = []

	while (queue.length > 0) {
		const id = queue.shift()
		if (seen.has(id) || !byId.has(id)) {
			continue
		}
		seen.add(id)
		ordered.push(byId.get(id))
		for (const edge of links) {
			if (edge.from === id && !seen.has(edge.to)) {
				queue.push(edge.to)
			}
		}
	}

	for (const node of list) {
		if (!seen.has(node.id)) {
			ordered.push(node)
		}
	}

	return ordered
}

/**
 * A run's duration: the sum of its recorded step durations.
 *
 * @param {object} run The run record.
 * @return {number|null} Milliseconds, or null when no step recorded one.
 */
export function runDuration(run) {
	let total = null
	for (const entry of Array.isArray(run?.log) ? run.log : []) {
		if (typeof entry?.durationMs === 'number') {
			total = (total ?? 0) + entry.durationMs
		}
	}
	return total
}

/**
 * The mean duration over the runs that recorded one.
 *
 * @param {Array<object>} runs The runs.
 * @return {number|null} Milliseconds, or null when none recorded a duration.
 */
export function averageDuration(runs) {
	const durations = (Array.isArray(runs) ? runs : [])
		.map(runDuration)
		.filter((ms) => ms !== null)
	if (durations.length === 0) {
		return null
	}
	return Math.round(durations.reduce((sum, ms) => sum + ms, 0) / durations.length)
}

/**
 * A duration in words a person reads at a glance.
 *
 * @param {number|null} ms Milliseconds.
 * @return {string} The duration, or an empty string for null.
 */
export function formatDuration(ms) {
	if (typeof ms !== 'number') {
		return ''
	}
	const locale = getCanonicalLocale()
	if (ms < 1000) {
		return unitFormat(locale, 'millisecond', 0).format(ms)
	}
	if (ms < 60000) {
		return unitFormat(locale, 'second', 1).format(ms / 1000)
	}
	const minutes = Math.floor(ms / 60000)
	const seconds = Math.round((ms % 60000) / 1000)
	return `${unitFormat(locale, 'minute', 0).format(minutes)} ${unitFormat(locale, 'second', 0).format(seconds)}`
}

/**
 * A short unit formatter. The browser localises the unit, so a duration
 * needs no catalogue key per language.
 *
 * @param {string} locale The BCP 47 locale.
 * @param {string} unit An Intl unit identifier.
 * @param {number} digits The most fraction digits to show.
 * @return {Intl.NumberFormat} The formatter.
 */
function unitFormat(locale, unit, digits) {
	return new Intl.NumberFormat(locale, {
		style: 'unit',
		unit,
		unitDisplay: 'short',
		maximumFractionDigits: digits,
	})
}

/**
 * How many of the runs ended which way.
 *
 * @param {Array<object>} runs The runs.
 * @return {{total: number, completed: number, failed: number, stopped: number, active: number}} The counts.
 */
export function runCounts(runs) {
	const counts = { total: 0, completed: 0, failed: 0, stopped: 0, active: 0 }
	for (const run of Array.isArray(runs) ? runs : []) {
		counts.total++
		if (run?.status === 'completed') {
			counts.completed++
		} else if (RUN_FAILED_STATUSES.includes(run?.status)) {
			counts.failed++
		} else if (run?.status === 'stopped') {
			counts.stopped++
		} else if (RUN_ACTIVE_STATUSES.includes(run?.status)) {
			counts.active++
		}
	}
	return counts
}

/**
 * Whether a run matches a runs-table filter.
 *
 * @param {object} run The run.
 * @param {string} filter One of all, failed, completed, running.
 * @return {boolean} True when the run belongs in the filtered list.
 */
export function matchesRunFilter(run, filter) {
	switch (filter) {
		case 'failed':
			return RUN_FAILED_STATUSES.includes(run?.status)
		case 'completed':
			return run?.status === 'completed'
		case 'running':
			return RUN_ACTIVE_STATUSES.includes(run?.status)
		default:
			return true
	}
}

/**
 * The step a failed run stopped at.
 *
 * Reads the LAST failed log entry: a run that loops can fail a node it
 * passed before, and the last failure is the one that ended it. The
 * position is the node's place in the flow's graph order when the node is
 * known, so it matches the steps a person sees on the overview.
 *
 * @param {object} run The run record.
 * @param {Array<object>} ordered The flow's nodes in graph order.
 * @param {Object<string, string>} names Type to display name.
 * @return {{nodeId: string, position: number|null, label: string, error: string}|null} The failed step, or null.
 */
export function failedStep(run, ordered = [], names = {}) {
	const log = Array.isArray(run?.log) ? run.log : []
	for (let i = log.length - 1; i >= 0; i--) {
		const entry = log[i]
		if (entry?.status !== 'failed') {
			continue
		}
		const index = ordered.findIndex((node) => node.id === entry.transition)
		const node =
			index >= 0 ? ordered[index] : { id: entry.transition, type: entry.type }
		return {
			nodeId: entry.transition || '',
			position: index >= 0 ? index + 1 : null,
			label: nodeLabel(node, names),
			error: entry.error || run?.error || '',
		}
	}
	return null
}

/**
 * The run's steps, one row per node.
 *
 * The log can hold the same node many times (a loop, or a run that woke
 * and parked again on every worker pass), so rows merge per node: the
 * LAST entry wins, and `times` counts the entries. Nodes the run never
 * logged follow in graph order as not reached.
 *
 * @param {object} run The run record.
 * @param {Array<object>} ordered The flow's nodes in graph order.
 * @param {Object<string, string>} names Type to display name.
 * @return {Array<object>} Rows: nodeId, label, status, times, itemsIn, itemsOut, durationMs, input, output, error, reached.
 */
export function stepRows(run, ordered = [], names = {}) {
	const rows = new Map()
	for (const entry of Array.isArray(run?.log) ? run.log : []) {
		const nodeId = entry?.transition
		if (!nodeId) {
			continue
		}
		const node = ordered.find((candidate) => candidate.id === nodeId) || {
			id: nodeId,
			type: entry.type,
		}
		const previous = rows.get(nodeId)
		rows.set(nodeId, {
			nodeId,
			label: nodeLabel(node, names),
			status: entry.status || '',
			times: (previous?.times ?? 0) + 1,
			itemsIn: entry.itemsIn ?? null,
			itemsOut: entry.itemsOut ?? null,
			durationMs:
				typeof entry.durationMs === 'number'
					? (previous?.durationMs ?? 0) + entry.durationMs
					: (previous?.durationMs ?? null),
			input: entry.input ?? null,
			output: entry.output ?? null,
			error: entry.error || entry.reason || '',
			reached: true,
		})
	}

	const result = [...rows.values()]
	for (const node of ordered) {
		if (!rows.has(node.id)) {
			result.push({
				nodeId: node.id,
				label: nodeLabel(node, names),
				status: '',
				times: 0,
				itemsIn: null,
				itemsOut: null,
				durationMs: null,
				input: null,
				output: null,
				error: '',
				reached: false,
			})
		}
	}
	return result
}

/**
 * A short form of a uuid for a table cell.
 *
 * @param {string|null} uuid The uuid.
 * @return {string} The first eight characters, or an empty string.
 */
export function shortUuid(uuid) {
	return uuid ? String(uuid).slice(0, 8) : ''
}

/**
 * A timestamp in the viewer's locale.
 *
 * @param {string|null} iso An ISO 8601 timestamp.
 * @return {string} The date and time, or an empty string.
 */
export function formatDateTime(iso) {
	if (!iso) {
		return ''
	}
	const date = new Date(iso)
	if (Number.isNaN(date.getTime())) {
		return String(iso)
	}
	return date.toLocaleString(getCanonicalLocale(), {
		dateStyle: 'medium',
		timeStyle: 'medium',
	})
}
