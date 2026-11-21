<?php

/**
 * OpenRegister auth denial warning
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The warning an authorisation lookup logs when it fails and so denies.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final readonly class AuthDenialWarning {
	/**
	 * Bundle the message with the subject keys logged next to it.
	 *
	 * @param string               $message The warning message.
	 * @param array<string, mixed> $context The subject keys logged with it.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public string $message,
		public array $context,
	) {
	}//end __construct()
}//end class
