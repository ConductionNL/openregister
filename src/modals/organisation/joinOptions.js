/**
 * The select options JoinOrganisation builds from what the organisation
 * store and the Nextcloud user API return.
 *
 * @spec exclude Client-side select-option shaping for the join modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * An organisation as an NcSelect option.
 *
 * @param {object} org The organisation as the store returns it
 * @return {object} The option
 */
export function organisationOption(org) {
	return {
		id: org.uuid || org.id,
		uuid: org.uuid || org.id,
		name: org.name,
		label: org.name,
		description: org.description,
		users: org.users || [],
		isDefault: org.isDefault,
	}
}

/**
 * The users an OCS users request returns, as NcSelect options.
 *
 * @param {string} url The OCS users URL
 * @param {string} failure The error message when the request fails
 * @return {Promise<Array<object>>} The options
 */
export async function fetchUserOptions(url, failure) {
	const response = await fetch(url, {
		headers: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})

	if (!response.ok) {
		throw new Error(failure)
	}

	const data = await response.json()
	const users = data?.ocs?.data?.users || []

	// Transform to NcSelect format with label property
	return users.map((userId) => ({
		id: userId,
		displayName: userId,
		label: userId, // Required by vue-select
	}))
}
