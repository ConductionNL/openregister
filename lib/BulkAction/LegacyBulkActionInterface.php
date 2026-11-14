<?php

/**
 * OpenRegister LegacyBulkActionInterface
 *
 * A bulk action in the released positional form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
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

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * A bulk action in the positional form of the released contract.
 *
 * @deprecated Implement StepBulkActionInterface.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
interface LegacyBulkActionInterface extends BulkActionInterface {
	/**
	 * What the action would do, or does, to one object.
	 *
	 * @param ObjectEntity         $object     The object.
	 * @param array<string, mixed> $parameters The job's parameters.
	 * @param bool                 $commit     False to rehearse, true to write.
	 * @param IUser|null           $actor      The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor=null): BulkActionResult;
}//end interface
