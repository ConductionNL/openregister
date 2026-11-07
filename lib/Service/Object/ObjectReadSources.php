<?php

/**
 * OpenRegister object read sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;

/**
 * Where GetObject reads objects from: the magic tables, or the object-source
 * provider a virtual schema declares.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectReadSources {
	/**
	 * Bundle the read sources.
	 *
	 * @param MagicObjectFinderInterface $objectMapper         Object entity data mapper.
	 * @param ObjectSourceRegistry       $objectSourceRegistry Registry of object-source providers (virtual schemas).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly MagicObjectFinderInterface $objectMapper,
		public readonly ObjectSourceRegistry $objectSourceRegistry,
	) {
	}//end __construct()
}//end class
