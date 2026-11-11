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

use OCA\OpenRegister\Service\Dmn\DecisionTableBulkGuard;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
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
 * @spec openspec/changes/bulk-writes-fire-save-events/specs/object-lifecycle/spec.md
 */
final class BulkRowRules {

	/**
	 * Constructor.
	 *
	 * @param DecisionTableBulkGuard|null $decisionTables  Applies the decision-table save rules to a bulk save.
	 * @param PropertyRbacHandlerInterface|null    $propertyRbac    Applies property authorization per row, as SaveObject does.
	 * @param BulkWriteEvents|null        $bulkWriteEvents Runs the save events per row where they are switched on.
	 * @param FlowRunContext|null         $runContext      The ambient flow-run stack, so a lock a run took admits that run.
	 */
	public function __construct(
		public readonly ?DecisionTableBulkGuard $decisionTables = null,
		public readonly ?PropertyRbacHandlerInterface $propertyRbac = null,
		public readonly ?BulkWriteEvents $bulkWriteEvents = null,
		public readonly ?FlowRunContext $runContext = null,
	) {
	}//end __construct()
}//end class
