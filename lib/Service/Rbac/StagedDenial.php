<?php

/**
 * OpenRegister staged denial
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * A denial that was resolved but not applied, as the staging log records it.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class StagedDenial {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $denial  The denial as the resolver returned it.
	 * @param string               $action  The verb that would have been removed.
	 * @param string|null          $userId  The caller it would have been removed from.
	 * @param array<string, mixed> $context Where it happened: schema, object, path.
	 */
	public function __construct(
		public readonly array $denial,
		public readonly string $action,
		public readonly ?string $userId,
		public readonly array $context = [],
	) {
	}//end __construct()
}//end class
