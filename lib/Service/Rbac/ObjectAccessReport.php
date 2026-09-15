<?php

/**
 * The per-object access reads, assembled in one place.
 *
 * The controller owns the HTTP request and the question of who may ask. This
 * owns the answer: which blocks are in play, which role definitions the register
 * declares, which trail entries to read, and which mode the deny is in. Keeping
 * those out of the controller is what stops an endpoint from quietly becoming
 * the place the rules are interpreted.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Assembles an object's access set and its history.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class ObjectAccessReport {

	/**
	 * How many trail entries the history reads at most.
	 *
	 * Reported with every answer, so a caller can tell a short history from a
	 * truncated one. An object with more authorization changes than this has
	 * older ones the report cannot see, and saying so is the difference between
	 * a limit and a lie.
	 *
	 * @var integer
	 */
	public const HISTORY_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper            $registerMapper   Register lookup.
	 * @param SchemaMapper              $schemaMapper     Schema lookup.
	 * @param AuditTrailMapper          $auditTrailMapper The trail the history is read from.
	 * @param ObjectPermissionsResolver $holders          Reads the access set out of the rules.
	 * @param ObjectAccessHistory       $history          Reconstructs the set at a past moment.
	 * @param DenyEnforcementMode       $enforcement      The staging switch.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly ObjectPermissionsResolver $holders,
		private readonly ObjectAccessHistory $history,
		private readonly DenyEnforcementMode $enforcement,
	) {
	}//end __construct()

	/**
	 * Who holds which right on one object, and which rule put them there.
	 *
	 * @param ObjectEntity $object      The object.
	 * @param mixed        $registerRef The register it belongs to, as the request named it.
	 * @param mixed        $schemaRef   The schema it belongs to, as the request named it.
	 *
	 * @return array<string, mixed> The access set.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function setFor(ObjectEntity $object, mixed $registerRef, mixed $schemaRef): array {
		$register = $this->register(reference: $registerRef);
		$schema = $this->schema(reference: $schemaRef);

		$set = $this->holders->holders(
			blocks: [
				'object' => $object->getAuthorization(),
				'schema' => $schema?->getAuthorization(),
				'register' => $register?->getAuthorization(),
			],
			roleDefinitions: $this->roleDefinitionsOf(register: $register)
		);

		return [
			'object' => $object->getUuid(),
			'register' => $register?->getSlug(),
			'schema' => $schema?->getSlug(),
			'owner' => $object->getOwner(),
			'holders' => $set['holders'],
			'denied' => $set['denied'],
			'denyEnforcement' => $this->enforcement->current(),
		];
	}//end setFor()

	/**
	 * How that set changed, and what it was at a named moment.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string|null  $moment An ISO-8601 moment to report the set as of.
	 *
	 * @return array<string, mixed> The history.
	 *
	 * @throws \Throwable When the trail cannot be read.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function historyFor(ObjectEntity $object, ?string $moment): array {
		$entries = $this->auditTrailMapper->findForObjectByAction(
			objectUuid: (string)$object->getUuid(),
			actions: [],
			limit: self::HISTORY_LIMIT
		);

		$history = $this->history->forEntries(entries: $entries, moment: $moment);

		return [
			'object' => $object->getUuid(),
			'at' => $moment,
			'changes' => $history['changes'],
			'asOf' => $history['asOf'],
			'entriesRead' => count($entries),
			'limit' => self::HISTORY_LIMIT,
		];
	}//end historyFor()

	/**
	 * The schema one reference names, or null when it cannot be resolved.
	 *
	 * @param mixed $reference The id, uuid or slug.
	 *
	 * @return Schema|null The schema.
	 */
	public function schema(mixed $reference): ?Schema {
		try {
			return $this->schemaMapper->find($reference);
		} catch (\Throwable $e) {
			return null;
		}
	}//end schema()

	/**
	 * The register one reference names, or null when it cannot be resolved.
	 *
	 * @param mixed $reference The id, uuid or slug.
	 *
	 * @return Register|null The register.
	 */
	private function register(mixed $reference): ?Register {
		try {
			return $this->registerMapper->find($reference);
		} catch (\Throwable $e) {
			return null;
		}
	}//end register()

	/**
	 * The role definitions a register declares.
	 *
	 * @param Register|null $register The register.
	 *
	 * @return mixed The definitions, or null when there are none.
	 */
	private function roleDefinitionsOf(?Register $register): mixed {
		if ($register === null) {
			return null;
		}

		$configuration = $register->getConfiguration();
		if (is_array($configuration) === false) {
			return null;
		}

		return ($configuration['roles'] ?? null);
	}//end roleDefinitionsOf()
}//end class
