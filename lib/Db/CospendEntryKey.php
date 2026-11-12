<?php

/**
 * CospendEntryKey: the entry type, project and bill that identify a Cospend link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * A Cospend project entry, or a bill entry under a project when a bill id is set.
 *
 * @spec exclude ADR-019 Tier-2 integration link key; the link contract is owned by the integration-cospend capability.
 */
final class CospendEntryKey {

	/**
	 * The bill id, or null for a project entry.
	 *
	 * @var integer|null
	 */
	private ?int $billId = null;

	/**
	 * Constructor.
	 *
	 * @param string $entryType The entry type (`project`|`bill`).
	 * @param string $projectId The Cospend project id.
	 */
	public function __construct(
		public readonly string $entryType,
		public readonly string $projectId,
	) {
	}//end __construct()

	/**
	 * The same entry for one bill.
	 *
	 * @param int $billId The Cospend bill id.
	 *
	 * @return CospendEntryKey The bill entry.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link key; the link contract is owned by the integration-cospend capability.
	 */
	public function withBill(int $billId): CospendEntryKey {
		$copy         = clone $this;
		$copy->billId = $billId;

		return $copy;
	}//end withBill()

	/**
	 * The bill id, or null for a project entry.
	 *
	 * @return integer|null The bill id.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link key; the link contract is owned by the integration-cospend capability.
	 */
	public function billId(): ?int {
		return $this->billId;
	}//end billId()
}//end class
