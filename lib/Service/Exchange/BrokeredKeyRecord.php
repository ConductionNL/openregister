<?php

/**
 * What a key is stored as in the credential broker.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

/**
 * The provider, name, organisation and owner of a brokered key.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */
final class BrokeredKeyRecord {
	/**
	 * Constructor.
	 *
	 * @param string $provider     The catalogue provider of the credential.
	 * @param string $name         The credential name; never the key.
	 * @param string $organisation The owning organisation UUID.
	 * @param string $owner        The user who created it.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $name,
		public readonly string $organisation,
		public readonly string $owner,
	) {
	}//end __construct()
}//end class
