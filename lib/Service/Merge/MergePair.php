<?php

/**
 * OpenRegister MergePair
 *
 * The two objects of one merge, the survivor's schema and its merge annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The losing and surviving object of one merge, with the schema and the
 * `x-openregister-merge` annotation the merge is driven by.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
 */
final readonly class MergePair {

	/**
	 * Bundle the pair.
	 *
	 * @param ObjectEntity         $fromObject The object being merged away.
	 * @param ObjectEntity         $intoObject The surviving object.
	 * @param Schema|null          $schema     The survivor's schema (survivorship config + trust rows).
	 * @param array<string, mixed> $config     The `x-openregister-merge` annotation.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
	 */
	public function __construct(
		public ObjectEntity $fromObject,
		public ObjectEntity $intoObject,
		public ?Schema $schema,
		public array $config,
	) {
	}//end __construct()
}//end class
