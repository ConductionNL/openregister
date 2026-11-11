<?php

/**
 * The caller a draft guard judges: who is signed in, whether they administer, and where PHP runs.
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

/**
 * The caller DraftCallerRule::keptFromCaller() decides for.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
 */
final class DraftCaller {

	/**
	 * Constructor.
	 *
	 * @param string|null $userId      The caller, null when nobody is signed in.
	 * @param bool        $isAdmin     Whether the caller is an administrator.
	 * @param bool|null   $commandLine Whether PHP runs on the command line; null reads PHP_SAPI.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly bool $isAdmin,
		public readonly ?bool $commandLine = null,
	) {
	}//end __construct()
}//end class
