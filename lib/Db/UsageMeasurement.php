<?php

/**
 * UsageMeasurement: the traffic and storage one organisation used in one hour.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * One reading of an organisation's usage: requests and bandwidth are traffic
 * that adds up over the hour, storage is the current figure that replaces the
 * previous one.
 */
final readonly class UsageMeasurement {

	/**
	 * Constructor.
	 *
	 * @param int $requestCount   Requests made.
	 * @param int $bandwidthBytes Bytes transferred.
	 * @param int $storageBytes   Bytes stored now.
	 */
	public function __construct(
		public int $requestCount,
		public int $bandwidthBytes,
		public int $storageBytes,
	) {
	}//end __construct()

	/**
	 * Write this reading as the first one of an hour's row.
	 *
	 * @param TenantUsage $row The new row.
	 *
	 * @return void
	 */
	public function writeTo(TenantUsage $row): void {
		$row->setRequestCount($this->requestCount);
		$row->setBandwidthBytes($this->bandwidthBytes);
		$row->setStorageBytes($this->storageBytes);
	}//end writeTo()

	/**
	 * Add this reading to an hour's existing row: traffic adds up, storage is replaced.
	 *
	 * @param TenantUsage $row The existing row.
	 *
	 * @return void
	 */
	public function addTo(TenantUsage $row): void {
		$row->setRequestCount($row->getRequestCount() + $this->requestCount);
		$row->setBandwidthBytes($row->getBandwidthBytes() + $this->bandwidthBytes);
		$row->setStorageBytes($this->storageBytes);
	}//end addTo()
}//end class
