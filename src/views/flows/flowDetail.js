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
 * The badge variant of each status that has one.
 */
const STATUS_VARIANTS = new Map([
	['completed', 'success'],
	['published', 'success'],
	['enabled', 'success'],
	['failed', 'error'],
	['dead_letter', 'error'],
	['suspended', 'warning'],
	['awaiting_consent', 'warning'],
	['draft', 'warning'],
	['queued', 'info'],
	['running', 'info'],
])

/**
 * The badge variant for a run or lifecycle status.
 *
 * @param {string} status The status.
 * @return {string} A CnStatusBadge variant.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
export function statusVariant(status) {
	return STATUS_VARIANTS.get(status) || 'default'
}

/**
 * The words for each known status, translated when they are read.
 */
const STATUS_LABELS = new Map([
	['completed', () => t('openregister', 'Completed')],
	['failed', () => t('openregister', 'Failed')],
	['dead_letter', () => t('openregister', 'Gave up')],
	['stopped', () => t('openregister', 'Stopped')],
	['suspended', () => t('openregister', 'Waiting')],
	['awaiting_consent', () => t('openregister', 'Waiting for consent')],
	['queued', () => t('openregister', 'Queued')],
	['running', () => t('openregister', 'Running')],
	['published', () => t('openregister', 'Published')],
	['draft', () => t('openregister', 'Draft')],
	['deprecated', () => t('openregister', 'Deprecated')],
])

/**
 * The words for a run or lifecycle status.
 *
 * @param {string} status The status.
 * @return {string} The translated label, or the raw status when unknown.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
export function statusLabel(status) {
	const label = STATUS_LABELS.get(status)
	return label ? label() : String(status || '')
}

/**
 * The sentence for each known trigger, translated when it is read.
 */
const TRIGGER_LABELS = new Map([
	['object.created', () => t('openregister', 'An object is created')],
	['object.updated', () => t('openregister', 'An object changes')],
	['object.deleted', () => t('openregister', 'An object is deleted')],
	['schedule', () => t('openregister', 'On a schedule')],
	['manual', () => t('openregister', 'Someone starts it')],
	['test', () => t('openregister', 'A test run')],
])

/**
 * What starts a flow, or what started a run, in words.
 *
 * @param {string} trigger The flow or run `trigger`.
 * @return {string} The translated sentence, or the raw trigger when unknown.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
 */
export function triggerLabel(trigger) {
	const label = TRIGGER_LABELS.get(trigger)
	return label ? label() : String(trigger || '')
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
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
export function nodeLabel(node, names = {}) {
	return node?.label || node?.name || typeLabel(node, names)
}

/**
 * The label of a node that names none itself: its type's display name,
 * else its type, else its id.
 *
 * @param {object} node The flow node.
 * @param {Object<string, string>} names Type to display name.
 * @return {string} The label.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
function typeLabel(node, names) {
	return names[node?.type] || node?.type || node?.id || ''
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
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
export function orderedNodes(nodes, edges) {
	const list = Array.isArray(nodes) ? nodes.filter((node) => node?.id) : []
	const links = graphLinks(edges)
	const pointedAt = new Set(links.map((edge) => edge.to))
	const walked = breadthFirst(
		{ byId: new Map(list.map((node) => [node.id, node])), links },
		list.filter((node) => !pointedAt.has(node.id)).map((node) => node.id),
	)
	const seen = new Set(walked.map((node) => node.id))
	return [...walked, ...list.filter((node) => !seen.has(node.id))]
}

/**
 * The edges as `from`/`to` pairs, without the edges that miss an end.
 *
 * @param {Array<object>} edges The flow's edges (`from`/`to`, or `source`/`target`).
 * @return {Array<{from: string, to: string}>} The links.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
function graphLinks(edges) {
	return (Array.isArray(edges) ? edges : [])
		.map((edge) => ({
			from: edge?.from ?? edge?.source,
			to: edge?.to ?? edge?.target,
		}))
		.filter((edge) => edge.from && edge.to)
}

/**
 * The nodes a breadth-first walk reaches from the start ids, each once.
 *
 * @param {{byId: Map<string, object>, links: Array<{from: string, to: string}>}} graph The nodes by id and the links.
 * @param {Array<string>} queue The start ids; the walk appends to it.
 * @return {Array<object>} The reached nodes, in walk order.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
function breadthFirst(graph, queue) {
	const seen = new Set()
	const walked = []
	while (queue.length > 0) {
		const id = queue.shift()
		if (seen.has(id) || !graph.byId.has(id)) {
			continue
		}
		seen.add(id)
		walked.push(graph.byId.get(id))
		const next = graph.links.filter(
			(edge) => edge.from === id && !seen.has(edge.to),
		)
		queue.push(...next.map((edge) => edge.to))
	}
	return walked
}

/**
 * A run's log entries, or none when the run has no log.
 *
 * @param {object} run The run record.
 * @return {Array<object>} The log entries.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
function runLog(run) {
	return Array.isArray(run?.log) ? run.log : []
}

/**
 * A run's duration: the sum of its recorded step durations.
 *
 * @param {object} run The run record.
 * @return {number|null} Milliseconds, or null when no step recorded one.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
 */
export function runDuration(run) {
	let total = null
	for (const entry of runLog(run)) {
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
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
 */
export function runCounts(runs) {
	const counts = { total: 0, completed: 0, failed: 0, stopped: 0, active: 0 }
	for (const run of Array.isArray(runs) ? runs : []) {
		counts.total++
		const outcome = runOutcome(run?.status)
		if (outcome) {
			counts[outcome]++
		}
	}
	return counts
}

/**
 * The count a run status adds to.
 *
 * @param {string} status The run status.
 * @return {string|null} completed, failed, stopped or active; null for none.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
 */
function runOutcome(status) {
	if (status === 'completed') {
		return 'completed'
	}
	if (RUN_FAILED_STATUSES.includes(status)) {
		return 'failed'
	}
	if (status === 'stopped') {
		return 'stopped'
	}
	return RUN_ACTIVE_STATUSES.includes(status) ? 'active' : null
}

// The run statuses each runs-table filter keeps. A filter not listed keeps
// every run.
const RUN_FILTERS = new Map([
	['failed', (status) => RUN_FAILED_STATUSES.includes(status)],
	['completed', (status) => status === 'completed'],
	['running', (status) => RUN_ACTIVE_STATUSES.includes(status)],
])

/**
 * Whether a run matches a runs-table filter.
 *
 * @param {object} run The run.
 * @param {string} filter One of all, failed, completed, running.
 * @return {boolean} True when the run belongs in the filtered list.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-the-overview-summarises-the-run-history-it-loaded
 */
export function matchesRunFilter(run, filter) {
	const matches = RUN_FILTERS.get(filter)
	return matches ? matches(run?.status) : true
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
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
export function failedStep(run, ordered = [], names = {}) {
	const entry = [...runLog(run)]
		.reverse()
		.find((candidate) => candidate?.status === 'failed')
	return entry ? failedStepOf(entry, { run, ordered, names }) : null
}

/**
 * The failed step one failed log entry names.
 *
 * @param {object} entry The failed log entry.
 * @param {{run: object, ordered: Array<object>, names: Object<string, string>}} context The run, its nodes in graph order and the type names.
 * @return {{nodeId: string, position: number|null, label: string, error: string}} The failed step.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
function failedStepOf(entry, context) {
	const index = context.ordered.findIndex((node) => node.id === entry.transition)
	return {
		nodeId: entry.transition || '',
		position: index >= 0 ? index + 1 : null,
		label: nodeLabel(nodeFor(entry, context.ordered), context.names),
		error: entry.error || context.run?.error || '',
	}
}

/**
 * The node a log entry belongs to, or a stand-in made from the entry when
 * the flow no longer has it.
 *
 * @param {object} entry The log entry.
 * @param {Array<object>} ordered The flow's nodes in graph order.
 * @return {object} The node.
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
function nodeFor(entry, ordered) {
	return (
		ordered.find((node) => node.id === entry.transition) || {
			id: entry.transition,
			type: entry.type,
		}
	)
}

/**
 * The named fields of a log entry, null where the entry has none.
 *
 * @param {object} entry The log entry.
 * @param {Array<string>} keys The field names.
 * @return {object} The fields, in the order given.
 */
function entryFields(entry, keys) {
	return Object.fromEntries(keys.map((key) => [key, entry[key] ?? null]))
}

/**
 * The time a node took so far: the earlier total plus this entry's, or the
 * earlier total when this entry names no number.
 *
 * @param {object} entry The log entry.
 * @param {object|undefined} previous The node's row so far.
 * @return {number|null} Milliseconds, or null when no entry named any.
 */
function summedDuration(entry, previous) {
	if (typeof entry.durationMs === 'number') {
		return (previous?.durationMs ?? 0) + entry.durationMs
	}
	return previous?.durationMs ?? null
}

/**
 * The row of a node after one more of its log entries.
 *
 * @param {object} entry The log entry.
 * @param {{nodeId: string, label: string, previous: object|undefined}} step The node, its label and its row so far.
 * @return {object} The row.
 */
function loggedStepRow(entry, step) {
	return {
		nodeId: step.nodeId,
		label: step.label,
		status: entry.status || '',
		times: (step.previous?.times ?? 0) + 1,
		...entryFields(entry, ['itemsIn', 'itemsOut']),
		durationMs: summedDuration(entry, step.previous),
		...entryFields(entry, ['input', 'output']),
		error: entry.error || entry.reason || '',
		reached: true,
	}
}

/**
 * The row of a node the run never logged.
 *
 * @param {string} nodeId The node id.
 * @param {string} label The node label.
 * @return {object} The row.
 */
function unreachedStepRow(nodeId, label) {
	return {
		nodeId,
		label,
		status: '',
		times: 0,
		itemsIn: null,
		itemsOut: null,
		durationMs: null,
		input: null,
		output: null,
		error: '',
		reached: false,
	}
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
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
 */
export function stepRows(run, ordered = [], names = {}) {
	const rows = new Map()
	for (const entry of runLog(run).filter((logged) => logged?.transition)) {
		const nodeId = entry.transition
		rows.set(
			nodeId,
			loggedStepRow(entry, {
				nodeId,
				label: nodeLabel(nodeFor(entry, ordered), names),
				previous: rows.get(nodeId),
			}),
		)
	}

	const unreached = ordered
		.filter((node) => !rows.has(node.id))
		.map((node) => unreachedStepRow(node.id, nodeLabel(node, names)))
	return [...rows.values(), ...unreached]
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
