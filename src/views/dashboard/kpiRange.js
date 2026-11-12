/**
 * Date-range presets for the range-driven KPI cards (Searches, Events) and
 * their storage across reloads.
 *
 * @spec exclude UI plumbing: local range state for the dashboard KPI cards.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * `days: null` means "all time" (no date filter). Mirrors the shared
 * finance-dashboard range pill used across the fleet.
 */
export const RANGE_PRESETS = [
	{ id: 'all', label: 'All time', days: null },
	{ id: '7d', label: 'Last 7 days', days: 7 },
	{ id: '30d', label: 'Last 30 days', days: 30 },
	{ id: '90d', label: 'Last 3 months', days: 90 },
	{ id: '12m', label: 'Last 12 months', days: 365 },
]

/** localStorage key persisting the chosen KPI range across reloads. */
const RANGE_PERSIST_KEY = 'openregister:dashboard:kpi-range'

/**
 * The preset with an id, or "all time" for an unknown id.
 *
 * @param {string} presetId The preset id (all, 7d, 30d, 90d, 12m).
 * @return {object} The preset
 */
export function presetOf(presetId) {
	return RANGE_PRESETS.find((p) => p.id === presetId) || RANGE_PRESETS[0]
}

/**
 * Resolve a preset id to an ISO date window. Returns { from, till } as
 * 'YYYY-MM-DD' strings, or { from: null, till: null } for "all time".
 *
 * @param {string} presetId The preset id.
 * @return {{from: (string|null), till: (string|null)}} The resolved window.
 */
function presetWindow(presetId) {
	const preset = presetOf(presetId)
	if (preset.days === null) return { from: null, till: null }
	const iso = (d) => d.toISOString().slice(0, 10)
	const till = new Date()
	const from = new Date()
	from.setDate(from.getDate() - preset.days)
	return { from: iso(from), till: iso(till) }
}

/**
 * The KPI range of a preset.
 *
 * @param {string} presetId The preset id.
 * @return {{from: (string|null), till: (string|null), preset: string}} The range
 */
export function rangeFor(presetId) {
	return { ...presetWindow(presetId), preset: presetId }
}

/**
 * The range stored by an earlier visit. A private window, a corrupt value or
 * an unknown preset gives no range.
 *
 * @return {object|null} The range, or null
 */
export function storedRange() {
	try {
		const stored = JSON.parse(localStorage.getItem(RANGE_PERSIST_KEY))
		const known = RANGE_PRESETS.some((p) => p.id === stored?.preset)
		return known ? rangeFor(stored.preset) : null
	} catch {
		return null
	}
}

/**
 * Remember the chosen preset. A failure leaves the in-memory range applied.
 *
 * @param {string} presetId The preset id.
 * @return {void}
 */
export function storeRange(presetId) {
	try {
		localStorage.setItem(RANGE_PERSIST_KEY, JSON.stringify({ preset: presetId }))
	} catch {
		// Non-fatal: private window or quota.
	}
}
