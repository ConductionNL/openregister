/**
 * Predicates for "managed by a configuration" state, shared by the list views
 * that badge and accent rows imported through a configuration.
 *
 * Each takes the managing configuration (or null) — callers own the lookup,
 * because registers and schemas are linked to configurations differently.
 */

/**
 * Whether a configuration is externally sourced (git/url).
 *
 * @param {(object|null)} config The managing configuration.
 * @spec exclude UI plumbing — display predicate helper
 * @return {boolean}
 */
export function isExternalConfiguration(config) {
	if (!config) return false
	return (
		(config.sourceType
			&& ['github', 'gitlab', 'url'].includes(config.sourceType))
		|| config.isLocal === false
	)
}

/**
 * Whether a configuration is local/manual.
 *
 * @param {(object|null)} config The managing configuration.
 * @spec exclude UI plumbing — display predicate helper
 * @return {boolean}
 */
export function isLocalConfiguration(config) {
	if (!config) return false
	return (
		config.sourceType === 'local'
		|| config.sourceType === 'manual'
		|| config.isLocal === true
	)
}

/**
 * Row accent class for a managing configuration ('' when unmanaged).
 *
 * @param {(object|null)} config The managing configuration.
 * @spec exclude UI plumbing — derived row-styling helper
 * @return {string}
 */
export function managedRowClass(config) {
	if (isExternalConfiguration(config)) return 'viewTableRow--managed'
	if (isLocalConfiguration(config)) return 'viewTableRow--local'
	return ''
}
