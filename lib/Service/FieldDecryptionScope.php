<?php

/**
 * OpenRegister field decryption scope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/field-level-encryption/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;

/**
 * What one decryptInScope() pass decrypts: the schema, the property in
 * hand, and whether a failure is rethrown or replaced by an error marker.
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-authorized-reads-are-decrypted-unauthorized-reads-never-see-ciphertext
 */
final class FieldDecryptionScope {

	/**
	 * The property being decrypted, '' before the first one.
	 *
	 * @var string
	 */
	private string $property = '';

	/**
	 * Constructor.
	 *
	 * @param Schema $schema         The object's schema.
	 * @param bool   $throwOnFailure Whether a decryption failure is rethrown.
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-authorized-reads-are-decrypted-unauthorized-reads-never-see-ciphertext
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly bool $throwOnFailure,
	) {
	}//end __construct()

	/**
	 * The same scope, at one property.
	 *
	 * @param string $property The property name.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-authorized-reads-are-decrypted-unauthorized-reads-never-see-ciphertext
	 */
	public function forProperty(string $property): self {
		$copy           = clone $this;
		$copy->property = $property;
		return $copy;
	}//end forProperty()

	/**
	 * The property being decrypted.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-authorized-reads-are-decrypted-unauthorized-reads-never-see-ciphertext
	 */
	public function property(): string {
		return $this->property;
	}//end property()
}//end class
