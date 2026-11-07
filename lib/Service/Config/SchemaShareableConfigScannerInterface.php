<?php

/**
 * SchemaShareableConfigScannerInterface: what callers outside the component use of SchemaShareableConfigScanner.
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

use OCA\OpenRegister\Service\Config\Types\GenericObjectShareableConfigType;

/**
 * Find the shareable configuration types that schemas declare.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface SchemaShareableConfigScannerInterface {

	/**
	 * The shareable types every marked schema declares.
	 *
	 * @return array<int, GenericObjectShareableConfigType> The generated types.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function scan(): array;
}//end interface
