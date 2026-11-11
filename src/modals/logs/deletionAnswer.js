/**
 * How the audit trail delete and clear modals read the server's answer.
 *
 * @spec exclude Client-side reading of the audit trail delete answer.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The answer to an audit trail deletion; a refusal is thrown as an error
 * carrying the server's reason.
 *
 * @param {Response} response The server's response
 * @return {Promise<object>} The answer
 */
export async function deletionAnswer(response) {
	const result = await response.json()
	if (!result.success) {
		throw new Error(result.error || 'Deletion failed')
	}
	return result
}
