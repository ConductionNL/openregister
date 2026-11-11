<?php

/**
 * OpenRegister source record change services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeInterface;

/**
 * The schemas a source-record change is matched against, the deferral it is queued through and the master recompute it ends in.
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
 */
final readonly class SourceRecordChangeServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface     $schemaMapper Schema lookup for the reverse foreign-key index.
	 * @param ListenerDeferralInterface $deferral     Queues the recompute after the request.
	 * @param MasterRecomputeInterface  $recompute    Recomputes a master inline.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public ListenerDeferralInterface $deferral,
		public MasterRecomputeInterface $recompute,
	) {
	}//end __construct()
}//end class
