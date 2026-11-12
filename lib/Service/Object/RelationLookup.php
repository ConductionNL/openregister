<?php

/**
 * OpenRegister RelationLookup
 *
 * One request for an object's outgoing or incoming relations.
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
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The object a relation lookup starts from, its paging query, the access filters it applies
 * (both on unless switched off) and the register and schema it is pinned to.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationLookup {

	/**
	 * Whether RBAC filters apply.
	 *
	 * @var bool
	 */
	private bool $rbac = true;

	/**
	 * Whether multitenancy filters apply.
	 *
	 * @var bool
	 */
	private bool $multitenancy = true;

	/**
	 * The pinned register id, or null.
	 *
	 * @var int|null
	 */
	private ?int $registerId = null;

	/**
	 * The pinned schema id, or null.
	 *
	 * @var int|null
	 */
	private ?int $schemaId = null;

	/**
	 * What the lookup lists, for log lines.
	 *
	 * @var string
	 */
	private string $listing = 'relations';

	/**
	 * Constructor.
	 *
	 * @param string               $objectId The object id or uuid.
	 * @param array<string, mixed> $query    The request's paging parameters.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly array $query = [],
	) {
	}//end __construct()

	/**
	 * A copy with the given access filters.
	 *
	 * @param bool $rbac         Apply RBAC filters.
	 * @param bool $multitenancy Apply multitenancy filters.
	 *
	 * @return self
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function filteredBy(bool $rbac, bool $multitenancy): self {
		$copy = clone $this;
		$copy->rbac = $rbac;
		$copy->multitenancy = $multitenancy;
		return $copy;
	}//end filteredBy()

	/**
	 * A copy pinned to a register and schema.
	 *
	 * @param int|null $registerId The register id.
	 * @param int|null $schemaId   The schema id.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function pinnedTo(?int $registerId, ?int $schemaId): self {
		$copy = clone $this;
		$copy->registerId = $registerId;
		$copy->schemaId = $schemaId;
		return $copy;
	}//end pinnedTo()

	/**
	 * A copy labelled with what it lists (`uses` or `used by`), for log lines.
	 *
	 * @param string $listing The label.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function listing(string $listing): self {
		$copy = clone $this;
		$copy->listing = $listing;
		return $copy;
	}//end listing()

	/**
	 * What the lookup lists.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function lists(): string {
		return $this->listing;
	}//end lists()

	/**
	 * Whether RBAC filters apply.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function appliesRbac(): bool {
		return $this->rbac;
	}//end appliesRbac()

	/**
	 * Whether multitenancy filters apply.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function appliesMultitenancy(): bool {
		return $this->multitenancy;
	}//end appliesMultitenancy()

	/**
	 * The pinned register id, or null.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function registerId(): ?int {
		return $this->registerId;
	}//end registerId()

	/**
	 * The pinned schema id, or null.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function schemaId(): ?int {
		return $this->schemaId;
	}//end schemaId()

	/**
	 * Whether both a register and a schema are pinned.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function isPinned(): bool {
		return $this->registerId !== null && $this->schemaId !== null;
	}//end isPinned()
}//end class
