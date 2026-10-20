<?php

/**
 * The one rule every explicit draft guard asks: is this stored draft kept from THIS caller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\SystemOperationContext;

/**
 * Who a draft is kept from: everybody but its owner, an administrator and a system caller.
 *
 * DraftStatusPolicy::keptFrom() answers for a named owner; this adds who the
 * caller is, the same way the read path does
 * (MagicRbacHandler::isTrustedSystemCaller()): an administrator is not kept
 * from a draft, and neither is a system caller without a user (the command
 * line, or code inside a SystemOperationContext) unless it asked to be judged
 * as nobody (AnonymousEvaluationContext). An anonymous web caller is kept.
 * Every guard that looks a draft up past the read rule (single save, bulk
 * save, single and bulk delete, permission check, related-objects list) asks
 * here, so none of them can answer differently.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
 */
class DraftCallerRule {

	/**
	 * Whether a stored draft is kept from this caller.
	 *
	 * @param ObjectEntity $object      The stored object.
	 * @param Schema       $schema      Its schema.
	 * @param string|null  $userId      The caller, null when nobody is signed in.
	 * @param bool         $isAdmin     Whether the caller is an administrator.
	 * @param bool|null    $commandLine Whether PHP runs on the command line; null reads PHP_SAPI.
	 *
	 * @return bool True when the caller may not touch it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Both contexts are ambient static markers by design.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The caller's two facts, resolved by each caller from the services it holds.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
	 */
	public function keptFromCaller(ObjectEntity $object, Schema $schema, ?string $userId, bool $isAdmin, ?bool $commandLine = null): bool {
		if ((new DraftStatusPolicy())->keptFrom(object: $object, schema: $schema, userId: $userId) === false || $isAdmin === true) {
			return false;
		}

		if ($userId === null && AnonymousEvaluationContext::isActive() === false) {
			$cli = ($commandLine ?? (PHP_SAPI === 'cli'));

			return $cli === false && SystemOperationContext::isActive() === false;
		}

		return true;
	}//end keptFromCaller()
}//end class
