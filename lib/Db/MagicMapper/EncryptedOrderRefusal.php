<?php

/**
 * EncryptedOrderRefusal: an order may not name an encrypted property.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldSortException;
use OCA\OpenRegister\Support\OrderParameter;

/**
 * Refuses an order on a property that any of the searched schemas encrypts.
 *
 * Names compare exactly: a search leaves out a field that matches a property
 * under another spelling, so that field never reaches the envelope column.
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
 */
final class EncryptedOrderRefusal {
	/**
	 * Constructor.
	 *
	 * @param mixed $order The `_order` value, in any of its forms.
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
	 */
	public function __construct(private readonly mixed $order) {
	}//end __construct()

	/**
	 * Refuse the order when a schema encrypts a field it names.
	 *
	 * @param array<int, Schema> $schemas The schemas searched.
	 *
	 * @return void
	 *
	 * @throws EncryptedFieldSortException On the first such field.
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
	 */
	public function check(array $schemas): void {
		$fields = array_map('strval', array_keys((new OrderParameter(order: $this->order))->fields()));
		foreach ($schemas as $schema) {
			$refused = array_intersect($fields, $schema->getEncryptedProperties());
			if ($refused !== []) {
				throw new EncryptedFieldSortException(property: reset($refused));
			}
		}
	}//end check()
}//end class
