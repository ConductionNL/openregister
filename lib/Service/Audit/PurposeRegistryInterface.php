<?php

/**
 * PurposeRegistryInterface: the part of PurposeRegistry that the processing purpose controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;

/**
 * The methods of PurposeRegistry that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PurposeRegistryInterface {

	/**
	 * The processing activity a purpose is bound to, right now.
	 *
	 * @param ProcessingPurpose $purpose The purpose to resolve.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null when the purpose is unbound.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function boundActivity(ProcessingPurpose $purpose): ?Verwerkingsactiviteit;
}//end interface
