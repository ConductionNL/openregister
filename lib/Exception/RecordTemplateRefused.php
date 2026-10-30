<?php

/**
 * A record template request refused, with the HTTP status that says why.
 *
 * A missing template, one the caller may not use and one they may use but not
 * change are three different answers (404, 404, 403); the status travels with
 * the refusal so the controller maps it without re-deciding.
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
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Raised when a record template request is refused.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class RecordTemplateRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int                                          $status   The HTTP status: 400, 403 or 404.
	 * @param string                                       $message  The sentence the caller reads.
	 * @param array<int, array{code: string, message: string}> $findings What was wrong with the input, for a 400.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function __construct(
		int $status,
		string $message,
		private readonly array $findings=[],
	) {
		parent::__construct(message: $message, code: $status);
	}//end __construct()

	/**
	 * What was wrong with the input.
	 *
	 * @return array<int, array{code: string, message: string}> The findings, empty unless a 400.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function getFindings(): array {
		return $this->findings;
	}//end getFindings()
}//end class
