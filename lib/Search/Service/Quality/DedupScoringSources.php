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
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * What the duplicate scorer builds its rule resolver and payload comparator from.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
 */
final class DedupScoringSources {
	/**
	 * Schema lookup for the dedup annotation.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register lookup, the boundary the schema resolves inside.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface $catalog    The register and schema mappers.
	 * @param SimilarityCalculator   $similarity Pure field-similarity primitives, for the comparator.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-6
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		public readonly SimilarityCalculator $similarity,
	) {
		$this->schemaMapper   = $catalog->schemas();
		$this->registerMapper = $catalog->registers();
	}//end __construct()
}//end class
