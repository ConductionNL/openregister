<?php

/**
 * BulkRowRules
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\Core\BulkRowRefusalsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\CurrentFlowRunInterface;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectQuota;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;

/**
 * The rules a bulk row goes through beside the safeguards' own checks.
 *
 * Each one is optional: a hand-built fixture leaves it out, and the container
 * always supplies it.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkRowRules {

	/**
	 * Constructor.
	 *
	 * @param BulkRowRefusalsInterface|null $decisionTables  Applies the decision-table save rules to a bulk save.
	 * @param PropertyRbacHandlerInterface|null    $propertyRbac    Applies property authorization per row, as SaveObject does.
	 * @param BulkWriteEvents|null        $bulkWriteEvents Runs the save events per row where they are switched on.
	 * @param CurrentFlowRunInterface|null $runContext     Which flow run is writing, so a lock a run took admits that run.
	 * @param BulkObjectQuota|null        $quota           Holds the bulk creates to a schema's per-organisation cap.
	 *
	 * @spec openspec/changes/functional-areas-depend-in-one-direction/specs/backend-modularity/spec.md#requirement-areas-reference-only-lower-areas
	 */
	public function __construct(
		public readonly ?BulkRowRefusalsInterface $decisionTables = null,
		public readonly ?PropertyRbacHandlerInterface $propertyRbac = null,
		public readonly ?BulkWriteEvents $bulkWriteEvents = null,
		public readonly ?CurrentFlowRunInterface $runContext = null,
		public readonly ?BulkObjectQuota $quota = null,
	) {
	}//end __construct()
}//end class
