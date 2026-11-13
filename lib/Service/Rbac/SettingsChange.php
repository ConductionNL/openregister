<?php

/**
 * A settings write: the stored state, the written state and which keys are secret.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Two settings states and the keys whose values are masked in the trail.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
final class SettingsChange {
	/**
	 * Describe the write.
	 *
	 * @param array<string, mixed> $before     The stored settings.
	 * @param array<string, mixed> $after      The settings being written.
	 * @param array<int, string>   $secretKeys Keys declared secret.
	 */
	public function __construct(
		public readonly array $before,
		public readonly array $after,
		public readonly array $secretKeys = [],
	) {
	}//end __construct()
}//end class
