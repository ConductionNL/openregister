<?php

/**
 * ExportKeySealersInterface: the sealer of an organisation's current export key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Finds the export key an unattended copy is encrypted with.
 *
 * An unattended writer (the copy before a destruction) has no passphrase to
 * ask for, so it can only use an export key of the organisation it works
 * for (design D-6). The newest usable key is the current one: a rotation
 * creates a new key and keeps the old one only for loading old sets.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */
interface ExportKeySealersInterface {

	/**
	 * The sealer of the organisation's newest usable export key.
	 *
	 * @param string $organisation The organisation UUID the copy is written for.
	 *
	 * @return CopySealerInterface|null The sealer, or null when the organisation has no export key the broker admits.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function sealerFor(string $organisation): ?CopySealerInterface;
}//end interface
