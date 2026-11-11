<?php

/**
 * OpenRegister Activity Filter
 *
 * The filters an activity-stream query for one object applies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The object marker, type, actor, lower time bound and paging cursor of an activity query.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class ActivityFilter {

	/**
	 * Exclusive upper timestamp bound (the paging cursor), or null for none.
	 *
	 * @var integer|null
	 */
	private ?int $cursor = null;

	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid The object whose `[or:{uuid}]` marker the rows carry.
	 * @param string|null $type       Activity type filter; null or '' for none.
	 * @param string|null $actor      Affected-user filter; null or '' for none.
	 * @param int|null    $after      Inclusive lower timestamp bound, or null for none.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly ?string $type,
		public readonly ?string $actor,
		public readonly ?int $after,
	) {
	}//end __construct()

	/**
	 * The same filter paged to rows strictly older than the cursor.
	 *
	 * @param int|null $cursor Exclusive upper timestamp bound, or null for none.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withCursor(?int $cursor): self {
		$copy         = clone $this;
		$copy->cursor = $cursor;
		return $copy;
	}//end withCursor()

	/**
	 * The paging cursor, or null for none.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function cursor(): ?int {
		return $this->cursor;
	}//end cursor()
}//end class
