<?php

/**
 * Object state transition
 *
 * One archive or freeze transition as it is persisted and recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The action, the object as it was before, and the reason the log line carries.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */
final class ObjectStateTransition {

	/**
	 * Constructor.
	 *
	 * @param string       $action The audit action (archive, unarchive, freeze, unfreeze).
	 * @param ObjectEntity $before The object before the transition.
	 * @param string|null  $reason The reason the log line carries.
	 */
	public function __construct(
		public readonly string $action,
		public readonly ObjectEntity $before,
		public readonly ?string $reason=null,
	) {
	}//end __construct()
}//end class
