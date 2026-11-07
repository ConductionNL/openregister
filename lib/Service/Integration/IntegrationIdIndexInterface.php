<?php

/**
 * IntegrationIdIndexInterface: the integration ids IntegrationRegistry knows, for callers that only check ids.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

/**
 * Answers which integration ids are registered on this instance.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
interface IntegrationIdIndexInterface {

	/**
	 * Return the ids of every registered provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function listIds(): array;

	/**
	 * Whether the given id is a registered integration.
	 *
	 * @param string $id Stable integration id to check.
	 *
	 * @return bool True when the id is registered.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function isValidIntegrationId(string $id): bool;
}//end interface
