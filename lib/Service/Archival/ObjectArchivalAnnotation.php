<?php

/**
 * OpenRegister ObjectArchivalAnnotation
 *
 * Evaluates a schema's `x-openregister-archival` annotation for one object,
 * wherever the answer is needed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The evaluated archival annotation for an object, derived not stored.
 *
 * ## Why this exists
 *
 * The annotation block is a DERIVED value: the schema declares it, and it is
 * evaluated against the row. `RenderObject` computes it while rendering a
 * response, and nothing persists it. So a code path that loads an object
 * straight from the mapper, which is exactly what the e-Depot transfer does,
 * saw no annotation at all, and every fact a schema declared was silently
 * missing from the SIP while the same object's GET carried all of them.
 *
 * Deriving it here, from the schema, keeps one source of truth. The
 * alternatives were worse: persisting a copy would go stale the moment a
 * schema's annotation changed, and calling the render path from the transfer
 * path would couple the two for a value that is a schema fact, not a
 * presentation concern.
 *
 * A stored block is honoured when the caller already has one, so a rendered
 * object costs no schema lookup and cannot disagree with itself mid-request.
 *
 * @psalm-suppress UnusedClass
 */
class ObjectArchivalAnnotation {

	/**
	 * The uuid the memo below belongs to.
	 */
	private ?string $memoUuid = null;

	/**
	 * The last evaluation, so resolving several facts for one object costs one lookup.
	 *
	 * @var array<string, mixed>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Source of the object's schema.
	 * @param RetentionEvaluator $evaluator The one evaluator both paths use.
	 * @param LoggerInterface $logger Logger for a lookup that fails.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RetentionEvaluator $evaluator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The evaluated annotation for this object, or an empty array.
	 *
	 * Never throws. An export that cannot read a schema should lose the facts
	 * that schema declared, not the whole transfer, and the absence is logged.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The evaluation, empty when there is none.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	public function forObject(ObjectEntity $object): array {
		$retention = ($object->getRetention() ?? []);
		if (is_array($retention) === true && is_array(($retention['annotation'] ?? null)) === true) {
			return $retention['annotation'];
		}

		$uuid = (string)$object->getUuid();
		if ($uuid !== '' && $this->memoUuid === $uuid) {
			return $this->memo;
		}

		$evaluated = $this->evaluateFor(object: $object);

		$this->memoUuid = $uuid;
		$this->memo = $evaluated;

		return $evaluated;
	}//end forObject()

	/**
	 * Load the object's schema and evaluate its annotation against the row.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The evaluation, empty when there is none.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-a-schema-may-declare-the-archival-facts-mdto-asks-for
	 */
	private function evaluateFor(ObjectEntity $object): array {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '' || $schemaId === 0) {
			return [];
		}

		try {
			$configuration = $this->schemaMapper->find($schemaId)->getConfiguration();
			$annotation = ($configuration['x-openregister-archival'] ?? null);
			if (is_array($annotation) === false) {
				return [];
			}

			$row = ($object->getObject() ?? []);
			if (is_array($row) === false) {
				$row = [];
			}

			return $this->evaluator->evaluate(
				annotation: $annotation,
				row: $row,
				createdAt: ($object->getCreated() ?? new DateTimeImmutable())
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				sprintf(
					'[ObjectArchivalAnnotation] could not evaluate the archival annotation for %s: %s',
					(string)$object->getUuid(),
					$e->getMessage()
				)
			);

			return [];
		}//end try
	}//end evaluateFor()
}//end class
