/**
 * How the access-link panel and the holder page word a refused request.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * The server's own refusal when it sends one, else the generic retry line.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 * @param {unknown} error What the request threw.
 * @return {string} The message to show.
 */
export function refusalMessage(error) {
	return error?.response?.data?.message
		|| t('openregister', 'That did not work. Try again later.')
}
