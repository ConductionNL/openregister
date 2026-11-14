/**
 * The flow runs that changed an object, read from the object's own audit
 * trail rows (flow-object-attribution): every row a run wrote names the run,
 * the node and the step. A run that has since been pruned still has these
 * identifiers on the rows, so it is listed from them rather than looked up.
 *
 * @spec openspec/changes/flow-object-attribution/tasks.md#7-frontend
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The runs among audit rows, newest first, each with the nodes it changed the
 * object in (in step order) and when it last did.
 *
 * @param {Array<object>|undefined} rows The audit trail rows.
 * @return {Array<{run: string, nodes: Array<string>, lastAt: string, changes: number}>} The runs.
 */
export function flowRunsOf(rows) {
	const runs = new Map()
	for (const row of Array.isArray(rows) ? rows : []) {
		const run = typeof row?.flowRun === 'string' ? row.flowRun.trim() : ''
		if (run === '') {
			continue
		}
		const entry = runs.get(run) || { run, steps: new Map(), lastAt: '', changes: 0 }
		entry.changes += 1
		const node = typeof row.flowNode === 'string' ? row.flowNode : ''
		if (node !== '' && !entry.steps.has(node)) {
			entry.steps.set(node, Number.isInteger(row.flowStep) ? row.flowStep : Number.MAX_SAFE_INTEGER)
		}
		const created = typeof row.created === 'string' ? row.created : ''
		if (created > entry.lastAt) {
			entry.lastAt = created
		}
		runs.set(run, entry)
	}

	return [...runs.values()]
		.map(({ run, steps, lastAt, changes }) => ({
			run,
			nodes: [...steps.entries()].sort((a, b) => a[1] - b[1]).map(([node]) => node),
			lastAt,
			changes,
		}))
		.sort((a, b) => (a.lastAt < b.lastAt ? 1 : a.lastAt > b.lastAt ? -1 : 0))
}
