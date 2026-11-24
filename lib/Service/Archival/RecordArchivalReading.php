<?php
/**
 * RecordArchivalReading: the archival reading of a rendered record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Core\RecordArchivalReadingInterface;
use Psr\Log\LoggerInterface;

/**
 * Answers rendering's archival questions with the retention evaluator and the decision resolver.
 *
 * Each call builds them afresh, as rendering did before it asked through the port.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class RecordArchivalReading implements RecordArchivalReadingInterface {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Handed to the retention evaluator.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate a schema's `x-openregister-archival` annotation against the record.
	 *
	 * @param array        $annotation The annotation.
	 * @param ObjectEntity $entity     The record, with a creation date.
	 *
	 * @return array The `_retention.annotation` block; empty for a record without a creation date.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function retentionAnnotation(array $annotation, ObjectEntity $entity): array {
		$createdAt = $entity->getCreated();
		if ($createdAt === null) {
			return [];
		}

		$row = $entity->getObject();
		if (is_array($row) === false) {
			$row = [];
		}

		return (new RetentionEvaluator(logger: $this->logger))->evaluate(
			subject: new AnnotatedRow(annotation: $annotation, row: $row, createdAt: $createdAt)
		);
	}//end retentionAnnotation()

	/**
	 * The record's resolved archival decision, or null when it has nothing to say about its archiving.
	 *
	 * @param ObjectEntity $entity The record, with its retention block already attached.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function archivalDecision(ObjectEntity $entity): ?array {
		return (new ArchivalDecisionResolver())->resolve(entity: $entity);
	}//end archivalDecision()
}//end class
