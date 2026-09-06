<?php

/**
 * Reads the anchoring object for the case layer.
 *
 * Two readers, two postures. {@see read()} is the SYSTEM read the evaluator
 * uses for an if-part: no RBAC, because a sentry over the object's state is
 * a fact about the case, not a question a user asked; an unreadable anchor
 * yields an empty document, which makes every if-part FALSE (fail-closed).
 * {@see mayRead()} is the USER read the API uses to decide whether a caller
 * may see a plan at all: whoever may read the object may read its plan, and
 * anything indeterminate is a no.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The case layer's view of its anchor.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class CaseAnchorReader {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects The ordinary object read path.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The anchor's data for sentry evaluation. Empty when unreadable.
	 *
	 * @param string $objectUuid The anchor uuid.
	 * @param int|null $registerId Its register, when known.
	 * @param int|null $schemaId Its schema, when known.
	 *
	 * @return array<string, mixed> The object's data, or [] when it cannot be read.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function read(string $objectUuid, ?int $registerId, ?int $schemaId): array {
		try {
			$entity = $this->objects->find(
				id: $objectUuid,
				register: $registerId,
				schema: $schemaId,
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[CaseAnchorReader] The anchoring object could not be read; every if-part over it is false: ' . $failure->getMessage(),
				['object' => $objectUuid]
			);

			return [];
		}

		if ($entity === null) {
			return [];
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end read()

	/**
	 * Whether a caller may read the anchor (and therefore its plan).
	 *
	 * @param string $objectUuid The anchor uuid.
	 * @param int|null $registerId Its register, when known.
	 * @param int|null $schemaId Its schema, when known.
	 *
	 * @return boolean True only when the RBAC-checked read succeeds.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function mayRead(string $objectUuid, ?int $registerId, ?int $schemaId): bool {
		try {
			return $this->objects->find(
				id: $objectUuid,
				register: $registerId,
				schema: $schemaId,
				_render: false,
				_audit: false
			) !== null;
		} catch (Throwable) {
			return false;
		}
	}//end mayRead()
}//end class
