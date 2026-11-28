/**
 * The form values of the source edit modal.
 *
 * @spec exclude UI plumbing of the source edit modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

const CONNECTION_FIELDS = ['host', 'port', 'dbname', 'user', 'credential', 'path']

/**
 * The connection form values held in a stored auth config.
 *
 * @param {object} authConfig The stored auth config
 * @return {object} The connection form values
 */
export function connectionOf(authConfig) {
	const fields = CONNECTION_FIELDS.map((key) => [key, authConfig[key] || ''])
	return {
		...Object.fromEntries(fields),
		writable: authConfig.writable === true,
	}
}

/**
 * The auth config of a database source. It holds only non-secret connection
 * parts: the password stays behind the credential store.
 *
 * @param {object} connection The connection form values
 * @param {string} driver The database driver id
 * @return {object} The auth config
 */
export function authConfigOf(connection, driver) {
	const fields = CONNECTION_FIELDS.map((key) => [key, connection[key]])
	return {
		driver,
		...Object.fromEntries(fields),
		writable: connection.writable === true,
	}
}

/**
 * The option with an id.
 *
 * @param {object} select The select state, with its options
 * @param {string} id The option id
 * @return {object|undefined} The option
 */
export function optionOf(select, id) {
	return select.options.find((option) => option.id === id)
}

/**
 * The form state of a closed modal.
 *
 * @return {object} The state
 */
export function closedForm() {
	return {
		success: null,
		loading: false,
		actionLoading: false,
		actionMessage: '',
		error: false,
		hasUpdated: false,
		sourceItem: { id: '', title: '', description: '', databaseUrl: '', schedule: '', runAs: '' },
		connection: connectionOf({}),
	}
}
