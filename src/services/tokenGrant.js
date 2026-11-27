/**
 * The token grant as the grant editor edits it: a form of strings and
 * booleans, and the grant object the API stores (scoped-api-tokens 3.1).
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The verbs a token may be granted. `manage` is never grantable (design D-3).
 *
 * @type {string[]}
 */
export const GRANTABLE_VERBS = ['read', 'create', 'update', 'delete', 'share']

/**
 * A comma-separated list of slugs, or null when empty (empty means "all").
 *
 * @param {string} text - the field value
 * @return {string[]|null} the slugs
 */
function slugs(text) {
	const list = String(text || '').split(',').map((slug) => slug.trim()).filter((slug) => slug !== '')
	return list.length === 0 ? null : [...new Set(list)]
}

/**
 * The editor form for a stored grant, or an empty form when there is none.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
 * @param {object|null} grant - the stored grant
 * @return {{verbs: string[], registers: string, schemas: string, match: string, expiresAt: string, rateLimit: string}} the form
 */
export function formFromGrant(grant) {
	const g = grant && typeof grant === 'object' ? grant : {}
	return {
		verbs: Array.isArray(g.verbs) ? g.verbs.filter((verb) => GRANTABLE_VERBS.includes(verb)) : ['read'],
		registers: Array.isArray(g.registers) ? g.registers.join(', ') : '',
		schemas: Array.isArray(g.schemas) ? g.schemas.join(', ') : '',
		match: g.match && typeof g.match === 'object' ? JSON.stringify(g.match, null, 2) : '',
		expiresAt: typeof g.expiresAt === 'string' ? g.expiresAt.slice(0, 10) : '',
		rateLimit: Number.isInteger(g.rateLimit) ? String(g.rateLimit) : '',
	}
}

/**
 * The row condition a form holds: null when empty, undefined when it is not a JSON object.
 *
 * @param {string} text - the field value
 * @return {object|null|undefined} the condition
 */
function matchOf(text) {
	if (String(text || '').trim() === '') return null
	try {
		const match = JSON.parse(text)
		return match !== null && typeof match === 'object' && !Array.isArray(match) ? match : undefined
	} catch {
		return undefined
	}
}

/**
 * The rate limit a form holds: null when empty, undefined when it is not a positive whole number.
 *
 * @param {string} text - the field value
 * @return {number|null|undefined} the limit
 */
function rateLimitOf(text) {
	if (String(text || '').trim() === '') return null
	const limit = Number(text)
	return Number.isInteger(limit) && limit >= 1 ? limit : undefined
}

/**
 * The grant a form describes, or the reasons it cannot be saved.
 *
 * The end date is required (REQ-SAT-003) and is taken as the end of that day
 * in UTC. The server validates again; these checks only spare a round trip.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
 * @param {object} form - the editor form
 * @return {{grant: object|null, errors: string[]}} the grant, or null with error codes (`verbs`, `expiresAt`, `match`, `rateLimit`)
 */
export function grantFromForm(form) {
	const verbs = GRANTABLE_VERBS.filter((verb) => (form.verbs || []).includes(verb))
	const date = String(form.expiresAt || '').trim()
	const match = matchOf(form.match)
	const rateLimit = rateLimitOf(form.rateLimit)

	const errors = [
		verbs.length === 0 ? 'verbs' : null,
		/^\d{4}-\d{2}-\d{2}$/.test(date) ? null : 'expiresAt',
		match === undefined ? 'match' : null,
		rateLimit === undefined ? 'rateLimit' : null,
	].filter((error) => error !== null)
	if (errors.length > 0) return { grant: null, errors }

	const grant = { verbs, expiresAt: `${date}T23:59:59+00:00` }
	const registers = slugs(form.registers)
	const schemas = slugs(form.schemas)
	if (registers !== null) grant.registers = registers
	if (schemas !== null) grant.schemas = schemas
	if (match !== null) grant.match = match
	if (rateLimit !== null) grant.rateLimit = rateLimit

	return { grant, errors: [] }
}
