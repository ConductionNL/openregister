<?php

/**
 * OpenRegister PREMIS Identifier
 *
 * The type and value of one PREMIS identifier (an event or an object identifier).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * A PREMIS identifier: what kind of identifier it is (`UUID`, `filepath`) and its value.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final class PremisIdentifier {

	/**
	 * Constructor.
	 *
	 * @param string $type  The identifier type.
	 * @param string $value The identifier value.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $value,
	) {
	}//end __construct()
}//end class
