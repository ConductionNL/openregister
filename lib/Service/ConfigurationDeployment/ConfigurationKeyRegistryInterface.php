<?php

/**
 * ConfigurationKeyRegistryInterface: what callers outside the component use of ConfigurationKeyRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * The draftable configuration vocabulary.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ConfigurationKeyRegistryInterface {

	/**
	 * The vocabulary a draft may address.
	 *
	 * @return array<string, mixed> The declared instance keys, the open
	 *                              prefixes, and the keys no deployment may
	 *                              carry, each with the reason.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function vocabulary(): array;
}//end interface
