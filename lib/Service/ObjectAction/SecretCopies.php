<?php

/**
 * The held secrets a whole value may be a copy of.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The secrets a string may equal and the numeric secrets a number may equal.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */
final class SecretCopies {

	/**
	 * Hold the secrets.
	 *
	 * @param array<int, string> $secrets The secrets a string may equal.
	 * @param array<int, string> $numbers The numeric secrets a number may equal.
	 */
	public function __construct(
		public readonly array $secrets,
		public readonly array $numbers,
	) {
	}//end __construct()
}//end class
