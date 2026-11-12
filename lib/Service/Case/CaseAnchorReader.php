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
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The case layer's view of its anchor.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class CaseAnchorReader {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The ordinary object read path.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The anchor's data for sentry evaluation. Empty when unreadable.
	 *
	 * @param CaseAnchor $anchor The anchor uuid, and its register and schema when known.
	 *
	 * @return array<string, mixed> The object's data, or [] when it cannot be read.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function read(CaseAnchor $anchor): array {
		try {
			$entity = $this->objects->find(
				id: $anchor->objectUuid,
				options: new ObjectFindOptions(register: $anchor->registerId, schema: $anchor->schemaId, render: false, audit: false),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[CaseAnchorReader] The anchoring object could not be read; every if-part over it is false: ' . $failure->getMessage(),
				['object' => $anchor->objectUuid]
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
	 * @param CaseAnchor $anchor The anchor uuid, and its register and schema when known.
	 *
	 * @return boolean True only when the RBAC-checked read succeeds.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function mayRead(CaseAnchor $anchor): bool {
		try {
			return $this->objects->find(
				id: $anchor->objectUuid,
				options: new ObjectFindOptions(register: $anchor->registerId, schema: $anchor->schemaId, render: false, audit: false)
			) !== null;
		} catch (Throwable) {
			return false;
		}
	}//end mayRead()
}//end class
