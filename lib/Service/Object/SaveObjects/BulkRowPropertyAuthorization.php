<?php

/**
 * Property authorization of one bulk row, the way SaveObject applies it to a single save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Service\ProtectedCarryForward;
use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\PropertyWriteCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCA\OpenRegister\Service\Rbac\OmittedWriteOnly;

/**
 * A value the caller may not write is refused (unchanged values pass, as in a PATCH). On an
 * update, a stored property the row omits and the caller may not read or update is carried
 * forward, so leaving it out cannot clear it.
 */
final class BulkRowPropertyAuthorization {

	/**
	 * The property rules.
	 *
	 * @var PropertyRbacHandlerInterface
	 */
	private readonly PropertyRbacHandlerInterface $propertyRbac;

	/**
	 * The row's schema.
	 *
	 * @var Schema
	 */
	private readonly Schema $schema;

	/**
	 * Whether the row updates a stored row.
	 *
	 * @var boolean
	 */
	private readonly bool $updates;

	/**
	 * The stored row's business data, empty on a create.
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $stored;

	/**
	 * The stored row's match envelope (business data with `@self` from the record), empty on a create.
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $record;

	/**
	 * Constructor.
	 *
	 * @param PropertyRbacHandlerInterface $propertyRbac The property rules.
	 * @param Schema              $schema       The row's schema.
	 * @param ObjectEntity|null   $existing     The stored row on an update, null on a create.
	 */
	public function __construct(PropertyRbacHandlerInterface $propertyRbac, Schema $schema, ?ObjectEntity $existing) {
		$this->propertyRbac = $propertyRbac;
		$this->schema       = $schema;
		$this->updates      = ($existing !== null);
		$this->stored       = ($existing?->getObject() ?? []);
		$this->record       = ($existing?->getMatchEnvelope() ?? []);
	}//end __construct()

	/**
	 * The refusal of the properties the row may not write, or null.
	 *
	 * @param array<string, mixed> $object   The row, business fields at the top and `@self` beside them.
	 * @param bool                 $isCreate Whether the row creates.
	 *
	 * @return string|null
	 */
	public function refusal(array $object, bool $isCreate): ?string {
		$unauthorized = $this->propertyRbac->getUnauthorizedProperties(
			write: new PropertyWriteCheck(schema: $this->schema, request: new PropertyAccessRequest(object: $this->record, isCreate: $isCreate), incoming: self::incoming(object: $object))
		);
		if ($unauthorized === []) {
			return null;
		}

		return 'You are not authorized to modify: ' . implode(', ', $unauthorized);
	}//end refusal()

	/**
	 * The row with the protected stored values it omits carried forward; unchanged on a create.
	 *
	 * @param array<string, mixed> $object The row.
	 *
	 * @return array<string, mixed>
	 */
	public function carriedForward(array $object): array {
		if ($this->updates === false) {
			return $object;
		}

		$omitted = $this->propertyRbac->collectOmittedProtectedProperties(
			update: new ProtectedCarryForward(schema: $this->schema, incoming: self::incoming(object: $object), stored: $this->record)
		);
		if ($omitted === []) {
			return $object;
		}

		return $this->propertyRbac->restoreWriteOnlyValues(prepared: $object, omitted: new OmittedWriteOnly(stored: $this->stored, paths: $omitted));
	}//end carriedForward()

	/**
	 * The row's business data, without `@self`.
	 *
	 * @param array<string, mixed> $object The row.
	 *
	 * @return array<string, mixed>
	 */
	private static function incoming(array $object): array {
		unset($object['@self']);

		return $object;
	}//end incoming()
}//end class
