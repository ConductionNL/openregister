<?php

/**
 * ShareableConfigTypeRegistryInterface: what callers outside the component use of ShareableConfigTypeRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * Look up a registered shareable configuration type.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ShareableConfigTypeRegistryInterface {

	/**
	 * One type by id, or null when nothing owns it.
	 *
	 * @param string $id The type id.
	 *
	 * @return IShareableConfigType|null The type, or null.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function get(string $id): ?IShareableConfigType;
}//end interface
