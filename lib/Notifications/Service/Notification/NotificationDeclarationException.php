<?php

/**
 * NotificationDeclarationException: a notification declaration the schema save refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use Exception;

/**
 * A `url` action whose target the dispatcher would never link to.
 *
 * The rest of `x-openregister-notifications` degrades to a warning; this fault
 * refuses the save, so an author learns of it instead of finding a button gone.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class NotificationDeclarationException extends Exception {

	/**
	 * Build the exception from the validator's error rows.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The blocking validation errors.
	 *
	 * @return void
	 */
	public function __construct(private readonly array $errors) {
		$parts = array_map(
			static fn (array $error): string => '[' . $error['code'] . '] ' . $error['message'],
			$errors
		);

		parent::__construct(message: 'Invalid x-openregister-notifications declaration: ' . implode(separator: ' ', array: $parts));
	}//end __construct()

	/**
	 * The individual errors, so a client can tell which rule refused.
	 *
	 * @return array<int, array{code: string, message: string}> The blocking validation errors.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
