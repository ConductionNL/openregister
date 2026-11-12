<?php

/**
 * The property a reference cites its target by, when it is not the uuid.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * One reading of `referenceKey`, for the validator that accepts a schema and the service that acts on it.
 *
 * An empty `referenceKey` and the literal `uuid` both mean a plain uuid
 * reference, to which every `onDelete` action applies. A schema save and the
 * delete that later honours it read the same declaration, and two readings of
 * it (the validator refusing `referenceKey: uuid` with `CASCADE`, the service
 * honouring it) is a schema that is refused for something that would have
 * worked, or accepted for something that does not.
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */
final class ReferenceKey {

	/**
	 * The target's key property, or null for a uuid reference.
	 *
	 * Read from the property, or from its `items` for an array of references.
	 *
	 * @param array<string, mixed> $property The property configuration array.
	 *
	 * @return string|null The target's key property, or null for a uuid reference.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	public function keyOf(array $property): ?string {
		$key = ($property['referenceKey'] ?? ($property['items']['referenceKey'] ?? null));
		if (is_string($key) === false || trim($key) === '' || trim($key) === 'uuid') {
			return null;
		}

		return trim($key);
	}//end keyOf()
}//end class
