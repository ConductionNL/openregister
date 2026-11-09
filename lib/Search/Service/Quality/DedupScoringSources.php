<?php

/**
 * OpenRegister dedup scoring sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * What the duplicate scorer builds its rule resolver and payload comparator from.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
 */
final class DedupScoringSources {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface   $schemaMapper   Schema lookup for the dedup annotation.
	 * @param RegisterMapperInterface $registerMapper Register lookup, the boundary the schema resolves inside.
	 * @param SimilarityCalculator    $similarity     Pure field-similarity primitives, for the comparator.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SimilarityCalculator $similarity,
	) {
	}//end __construct()
}//end class
