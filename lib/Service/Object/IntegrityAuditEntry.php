<?php

/**
 * What one referential integrity action did, for the audit trail.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * An integrity action on one object: its name, target, change and actor.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class IntegrityAuditEntry {

	/**
	 * The object's schema id.
	 *
	 * @var string|null
	 */
	public ?string $schemaId = null;

	/**
	 * The object's register id.
	 *
	 * @var string|null
	 */
	public ?string $registerId = null;

	/**
	 * The earliest moment the entry may expire, when its evidence must outlive a recovery window.
	 *
	 * @var \DateTimeImmutable|null
	 */
	public ?\DateTimeImmutable $expiresNoEarlierThan = null;

	/**
	 * The object, when already at hand.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * Constructor.
	 *
	 * @param string $action     The action name.
	 * @param string $objectUuid The object acted on.
	 * @param array  $changed    What changed.
	 * @param string $userId     Who acted.
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $objectUuid,
		public readonly array $changed,
		public readonly string $userId,
	) {
	}//end __construct()

	/**
	 * Name where the object lives.
	 *
	 * @param string|null $schemaId   The schema id.
	 * @param string|null $registerId The register id.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function within(?string $schemaId, ?string $registerId): self {
		$this->schemaId = $schemaId;
		$this->registerId = $registerId;

		return $this;
	}//end within()

	/**
	 * Name the object itself, when the caller holds it.
	 *
	 * @param ObjectEntity|null $object The object.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function about(?ObjectEntity $object): self {
		$this->object = $object;

		return $this;
	}//end about()

	/**
	 * Keep the entry at least until the given moment.
	 *
	 * @param \DateTimeImmutable|null $until The earliest expiry, or null for no floor.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md#requirement-the-cascade-evidence-is-findable-and-lasts-as-long-as-the-recovery-window
	 */
	public function lastingUntil(?\DateTimeImmutable $until): self {
		$this->expiresNoEarlierThan = $until;

		return $this;
	}//end lastingUntil()
}//end class
