<?php
/**
 * RecordArchivalReadingInterface: what archival says about a record being rendered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The archival reading of a rendered record: its annotation retention and its archival decision.
 *
 * Rendering (Core) attaches both to the record it renders, in that order, each
 * guarded on its own; Compliance computes them and binds this in its own wiring.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
interface RecordArchivalReadingInterface {

	/**
	 * Evaluate a schema's `x-openregister-archival` annotation against the record.
	 *
	 * @param array        $annotation The annotation.
	 * @param ObjectEntity $entity     The record, with a creation date.
	 *
	 * @return array The `_retention.annotation` block.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function retentionAnnotation(array $annotation, ObjectEntity $entity): array;

	/**
	 * The record's resolved archival decision, or null when it has nothing to say about its archiving.
	 *
	 * @param ObjectEntity $entity The record, with its retention block already attached.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function archivalDecision(ObjectEntity $entity): ?array;
}//end interface
