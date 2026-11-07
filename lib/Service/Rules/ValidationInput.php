<?php

/**
 * What one evaluation of a schema's administered validations reads.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The document, the named conditions in scope and the caller's language.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
final class ValidationInput {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $document The object as it would be saved.
	 * @param array<string, mixed> $library  Named conditions in scope.
	 * @param string               $language The caller's language.
	 */
	public function __construct(
		public readonly array $document,
		public readonly array $library,
		public readonly string $language,
	) {
	}//end __construct()
}//end class
