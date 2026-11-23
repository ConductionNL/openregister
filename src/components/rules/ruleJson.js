/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the rule panels word JSON a person typed that does not parse.
 *
 * @spec exclude Client-side wording of a JSON parse failure in the rule panels.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * What a rule panel says about typed JSON that does not parse.
 *
 * @param {Error} failure The parse failure.
 *
 * @return {string} The message, naming the reason.
 */
export function invalidJsonText(failure) {
	return t('openregister', 'That is not valid JSON: {reason}', {
		reason: failure.message,
	})
}
