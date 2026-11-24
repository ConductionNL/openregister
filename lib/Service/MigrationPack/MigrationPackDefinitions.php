<?php

/**
 * MigrationPackDefinitions: a stored migration pack's definition by slug, for the register import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use OCA\OpenRegister\Kernel\Contracts\Core\MigrationPackDefinitionsInterface;
use OCA\OpenRegister\Service\MigrationPackServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Answers the register import's pack lookup with the migration pack service.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class MigrationPackDefinitions implements MigrationPackDefinitionsInterface {

	/**
	 * Constructor.
	 *
	 * @param MigrationPackServiceInterface $packs The stored migration packs.
	 */
	public function __construct(
		private readonly MigrationPackServiceInterface $packs,
	) {
	}//end __construct()

	/**
	 * The definition of the pack with this slug.
	 *
	 * @param string $packSlug The pack's slug.
	 *
	 * @return array<string, mixed> The pack definition.
	 *
	 * @throws DoesNotExistException When no pack has this slug.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function definitionBySlug(string $packSlug): array {
		return $this->packs->findByPackSlug(packSlug: $packSlug)->getDefinitionArray();
	}//end definitionBySlug()
}//end class
