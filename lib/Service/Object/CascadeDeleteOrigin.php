<?php

/**
 * Who and what started a cascade delete.
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

namespace OCA\OpenRegister\Service\Object;

/**
 * The deleting user, the root object, its schema slug and the organisation of one cascade delete.
 */
final readonly class CascadeDeleteOrigin {

	/**
	 * Constructor.
	 *
	 * @param string                  $userId            The user performing the deletion.
	 * @param string                  $cascadeSource     The uuid of the root object triggering the cascade.
	 * @param string|null             $triggerSchemaSlug The schema slug of the root object, for the audit row.
	 * @param string|null             $organisationId    The active organisation, for the deletion attribution.
	 * @param \DateTimeImmutable|null $evidenceUntil     The end of the root's recovery window: the evidence
	 *                                                   of a reference this cascade clears lasts that long.
	 */
	public function __construct(
		public string $userId,
		public string $cascadeSource,
		public ?string $triggerSchemaSlug,
		public ?string $organisationId = null,
		public ?\DateTimeImmutable $evidenceUntil = null,
	) {
	}//end __construct()

	/**
	 * The cascade context an audit row of a target deleted through the given property carries.
	 *
	 * @param array $target The cascade target.
	 *
	 * @return array<string, mixed> The context.
	 */
	public function auditContext(array $target): array {
		return [
			'triggerObject' => $this->cascadeSource,
			'triggerSchema' => $this->triggerSchemaSlug,
			'action_type'   => 'referential_integrity.cascade_delete',
			'property'      => ($target['property'] ?? null),
		];
	}//end auditContext()
}//end class
