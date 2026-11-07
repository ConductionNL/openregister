<?php

/**
 * ApplyRuleActionInterface: what callers outside the BulkAction component use of ApplyRuleAction.
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
 * The built-in bulk action that applies one declared rule; its id is what callers queue it by.
 */
interface ApplyRuleActionInterface extends BulkActionInterface {

	/**
	 * The action id.
	 *
	 * @var string
	 */
	public const ID = 'openregister:apply-rule';
}//end interface
