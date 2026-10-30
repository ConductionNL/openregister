<?php

/**
 * OpenRegister InvalidPackDefinitionException
 *
 * Raised when a migration pack definition is refused: it fails the structural
 * validator, or its slug is already taken by another pack.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * A pack definition refused, with every reason as a separate entry.
 *
 * The controller answers it with 422 `invalid-pack` and hands the reasons to
 * the client as `details.errors`, so the client never has to split the
 * exception message. The message still joins the reasons, for logs and for
 * callers that only know InvalidArgumentException (an ancestor).
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
 */
class InvalidPackDefinitionException extends MigrationPackRefusedException {
	/**
	 * Constructor.
	 *
	 * @param string[] $errors Every reason the definition was refused.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function __construct(private readonly array $errors) {
		parent::__construct(message: 'Invalid migration pack definition: ' . implode('; ', $errors));
	}//end __construct()

	/**
	 * Every reason the definition was refused.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function getErrors(): array {
		return array_values($this->errors);
	}//end getErrors()
}//end class
