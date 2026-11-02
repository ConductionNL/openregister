<?php

/**
 * A view alert naming recipients the caller may not tell about the view.
 *
 * A refusal of access rather than of form: it answers 403 with the refused
 * recipients instead of the 422 a malformed alert gets.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * A view alert naming recipients the caller may not tell about the view.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
class ViewAlertRecipientException extends InvalidViewAlertException {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $recipients The refused recipients.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function __construct(private readonly array $recipients) {
		parent::__construct(
			field: 'alert.recipients',
			message: 'alert.recipients: every recipient must be able to read this view, '
				.'and only the owner or an administrator may add someone other than themselves. '
				.'Refused: '.implode(', ', $recipients)
		);
	}//end __construct()

	/**
	 * The refused recipients.
	 *
	 * @return array<int, string> The recipients.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getRecipients(): array {
		return $this->recipients;
	}//end getRecipients()

	/**
	 * A refusal of access: 403.
	 *
	 * @return int 403.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getStatus(): int {
		return 403;
	}//end getStatus()

	/**
	 * The refused recipients, for the response body.
	 *
	 * @return array<string, mixed> `recipients`.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getDetails(): array {
		return ['recipients' => $this->recipients];
	}//end getDetails()
}//end class
