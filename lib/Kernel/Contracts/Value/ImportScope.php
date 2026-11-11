<?php

/**
 * OpenRegister ImportScope
 *
 * The register and schema an import is asked to write into, and the user asking.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCP\IUser;

/**
 * Where an import writes and on whose behalf, as the caller asked (ADR-011 Rule 2).
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportScope {
	/**
	 * Build the scope.
	 *
	 * @param RegisterInterface|null $register     The register; null when it could not be resolved.
	 * @param SchemaInterface|null   $schema       The schema; null for a multi-sheet or bundle import.
	 * @param IUser|null             $currentUser  The user performing the import.
	 * @param string|null            $registerSlug The register slug originally asked for, to name it when it is missing.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?RegisterInterface $register = null,
		public readonly ?SchemaInterface $schema = null,
		public readonly ?IUser $currentUser = null,
		public readonly ?string $registerSlug = null,
	) {
	}//end __construct()
}//end class
