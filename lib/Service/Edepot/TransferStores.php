<?php

/**
 * OpenRegister e-Depot Transfer Stores
 *
 * The stores an e-Depot transfer step reads and writes: the objects, the
 * durable transfer records, the app settings and the log.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Where the transfer list, gatherer and outcome recorder keep what a transfer did.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferStores {
	/**
	 * Bundle the stores.
	 *
	 * @param MagicMapperInterface  $objects  The objects a transfer reads and updates.
	 * @param TransferRecordService $records  Durable transfer list and proof persistence.
	 * @param IAppConfig            $settings The e-Depot app settings (package format).
	 * @param LoggerInterface       $logger   The log every transfer step writes to.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objects,
		public readonly TransferRecordService $records,
		public readonly IAppConfig $settings,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
