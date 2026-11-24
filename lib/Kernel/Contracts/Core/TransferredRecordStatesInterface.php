<?php
/**
 * TransferredRecordStatesInterface: the spellings that mean a record was handed to an e-Depot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The values of a record's `archiefstatus` that mean it was transferred, as the delete path reads them.
 *
 * The delete path (Core) refuses to remove a transferred row; the Archiefwet
 * lifecycle vocabulary (Compliance, RecordState) extends this contract, so
 * `RecordState::TRANSFERRED_ALIASES` stays the one home of the value.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
interface TransferredRecordStatesInterface {

	/**
	 * Every spelling that means TRANSFERRED: the English name and the Dutch one it replaces.
	 *
	 * @var string[]
	 */
	public const TRANSFERRED_ALIASES = ['transferred', 'overgebracht'];
}//end interface
