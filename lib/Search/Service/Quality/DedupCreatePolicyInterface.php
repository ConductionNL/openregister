<?php

/**
 * DedupCreatePolicyInterface: what callers outside the Search component use of DedupCreatePolicy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

use OCA\OpenRegister\Kernel\Contracts\CreateGuardInterface;

/**
 * The part of DedupCreatePolicy that code outside the Search component uses.
 *
 * The create guard itself is the Core-owned {@see CreateGuardInterface}, which
 * this interface extends: `OVERRIDE_KEY`, `guardCreate()` and `recordOverride()`
 * are inherited from it, so the two spellings of the key cannot drift apart.
 */
interface DedupCreatePolicyInterface extends CreateGuardInterface {

	/**
	 * Audit action recorded when an override is exercised.
	 *
	 * @var string
	 */
	public const OVERRIDE_ACTION = 'dedup.overridden';
}//end interface
