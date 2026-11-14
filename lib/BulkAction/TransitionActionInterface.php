<?php

/**
 * TransitionActionInterface: what callers outside the BulkAction component use of TransitionAction.
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

/**
 * The built-in bulk action that moves objects through one lifecycle action; its id is what callers queue it by.
 *
 * @spec openspec/changes/records-bulk-transition/specs/object-lifecycle/spec.md#requirement-a-bulk-job-can-move-selected-records-through-one-lifecycle-action
 */
interface TransitionActionInterface extends BulkActionInterface {

	/**
	 * The action id.
	 *
	 * @var string
	 */
	public const ID = 'openregister:transition';
}//end interface
