<?php

/**
 * OpenRegister repeating-group declaration run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * One check of one property's repeating-group declaration.
 *
 * The property definition and its path travel together through every rule of
 * RepeatingGroupDeclarationValidator; the run is built by its `validate()` and
 * never leaves that class (ADR-011 Rule 3).
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class RepeatingGroupDeclarationRun {
	/**
	 * Hold the property under check and where it sits.
	 *
	 * @param array  $property The property definition to check.
	 * @param string $path     The current path in the schema, for the message.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly array $property,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * Refuse the declaration, in the vocabulary's own error shape.
	 *
	 * @param string $code    The machine-readable refusal code.
	 * @param string $message The sentence a schema author reads.
	 *
	 * @throws PropertyVocabularyException Always.
	 *
	 * @return never
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function refuse(string $code, string $message): never {
		throw new PropertyVocabularyException(
			$message,
			[
				[
					'code' => $code,
					'key' => 'repeatingGroup',
					'path' => $this->path,
					'message' => $message,
				],
			]
		);
	}//end refuse()
}//end class
