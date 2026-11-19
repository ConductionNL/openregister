<?php

/**
 * PurposeGuardInterface: the part of PurposeGuard that the object controllers use through their guard bundles.
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

use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeGuardInterface;

/**
 * The methods of PurposeGuard that callers outside its component use.
 * Callers depend on this interface, not on the class.
 *
 * The read checks enforce() and allows() come from the Core read port it extends.
 */
interface PurposeGuardInterface extends ReadPurposeGuardInterface {

	/**
	 * The audit action a refused read is recorded under.
	 *
	 * @var string
	 */
	public const ACTION_REFUSED = 'purpose.query-refused';

}//end interface
