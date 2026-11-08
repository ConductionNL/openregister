<?php

/**
 * OpenRegister lens sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
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

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Where a lens reads its objects and their schemas.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class LensSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicObjectFinderInterface $magicMapper  Object storage, to read the referenced record.
	 * @param SchemaMapperInterface      $schemaMapper Schema lookup for the referenced record.
	 */
	public function __construct(
		public readonly MagicObjectFinderInterface $magicMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class
