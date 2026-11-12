<?php

/**
 * Metadata fields every generated GraphQL object type carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use OCP\IUserSession;

/**
 * The `_` fields of an object type: identity, owner, audit trail and reverse relations.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) webonyx exposes its built-in types statically
 */
class ObjectMetadataFields {

	/**
	 * Constructor.
	 *
	 * @param array<string, Type> $scalars The custom scalars by name
	 * @param IUserSession $userSession The session that says who is asking
	 */
	public function __construct(
		private readonly array $scalars,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The metadata fields that open an object type.
	 *
	 * @param ObjectType $auditTrailType The type of an audit trail entry
	 *
	 * @return array<string, array<string, mixed>> The fields by name
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function leading(ObjectType $auditTrailType): array {
		return [
			'_uuid' => ['type' => $this->scalars['UUID'], 'description' => 'Object UUID'],
			'_register' => ['type' => Type::int(), 'description' => 'Register ID'],
			'_schema' => ['type' => Type::int(), 'description' => 'Schema ID'],
			'_created' => ['type' => $this->scalars['DateTime'], 'description' => 'Creation timestamp'],
			'_updated' => ['type' => $this->scalars['DateTime'], 'description' => 'Last update timestamp'],
			'_owner' => $this->ownerField(),
			'_auditTrail' => $this->auditTrailField(auditTrailType: $auditTrailType),
		];
	}//end leading()

	/**
	 * The field for reverse relationship traversal.
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function usedBy(): array {
		return [
			'type' => $this->scalars['JSON'],
			'description' => 'Objects that reference this object (reverse relationships)',
		];
	}//end usedBy()

	/**
	 * The `_owner` field.
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function ownerField(): array {
		return [
			'type' => Type::string(),
			'description' => 'Object owner user ID, null for an anonymous caller',
			'resolve' => fn (array $row): ?string => $this->ownerForCaller(row: $row),
		];
	}//end ownerField()

	/**
	 * The `_auditTrail` field, which answers null.
	 *
	 * An object's entries may only be given out through ObjectHistoryForReaders, and
	 * the explicit resolver keeps the default one from reading an `_auditTrail` key
	 * off the object.
	 *
	 * @param ObjectType $auditTrailType The type of an audit trail entry
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function auditTrailField(ObjectType $auditTrailType): array {
		return [
			'type' => Type::listOf($auditTrailType),
			'description' => 'Audit trail entries for this object',
			'args' => [
				'last' => [
					'type' => Type::int(),
					'defaultValue' => 10,
					'description' => 'Number of most recent entries to return',
				],
			],
			'resolve' => static fn (): ?array => null,
		];
	}//end auditTrailField()

	/**
	 * The `_owner` field: answered at the field, so an alias cannot get round it.
	 *
	 * The row keeps `_owner`, because a property rule on a related row reads it there.
	 *
	 * @param array<string, mixed> $row The resolved row.
	 *
	 * @return string|null The owner, or null for an anonymous caller.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function ownerForCaller(array $row): ?string {
		if ($this->userSession->getUser() === null) {
			return null;
		}

		return $row['_owner'] ?? null;
	}//end ownerForCaller()
}//end class
