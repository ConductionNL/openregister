<?php

/**
 * Raised when a schema declares a bulk action reversible that cannot be.
 *
 * A destruction, a dispatched message and an e-depot transfer leave nothing
 * to go back to. Storing "reversible" beside one of them would put an undo
 * button in front of an operator that cannot work, and they would find out
 * on the day they needed it. The refusal is HTTP 422: the request was
 * understood, the declaration inside it contradicts itself, and the message
 * names the action so the author knows which line to change (D-4).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use Exception;

/**
 * Raised when a reversibility declaration is refused at schema save time.
 *
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */
final class ReversibilityDeclarationException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The refusals.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(private readonly array $errors) {
		$messages = array_map(
			static fn (array $error): string => (string)($error['message'] ?? ''),
			$errors
		);

		parent::__construct(message: 'Invalid reversibility declaration: '.implode(' ', $messages));
	}//end __construct()

	/**
	 * The individual refusals, so the response names each action.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
