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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Assembles an object's access set and its history.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class ObjectAccessReport implements ObjectAccessReportInterface {

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
	 * @param ObjectAccessReportSources $sources     The register, schema and trail lookups the report reads.
	 * @param ObjectPermissionsResolver $holders     Reads the access set out of the rules.
	 * @param ObjectAccessHistory       $history     Reconstructs the set at a past moment.
	 * @param DenyEnforcementMode       $enforcement The staging switch.
	 */
	public function __construct(
		private readonly ObjectAccessReportSources $sources,
		private readonly ObjectPermissionsResolver $holders,
		private readonly ObjectAccessHistory $history,
		private readonly DenyEnforcementMode $enforcement,
	) {
	}//end __construct()

	/**
	 * Who holds which right on one object, and which rule put them there.
	 *
	 * @param ObjectEntity      $object The object.
	 * @param RegisterSchemaRef $table  The register and schema it belongs to, as the request named them.
	 *
	 * @return array<string, mixed> The access set.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function setFor(ObjectEntity $object, RegisterSchemaRef $table): array {
		return $this->setIn(
			object: $object,
			place: new ReportedPlace(register: $this->register(reference: $table->register), schema: $this->schema(reference: $table->schema))
		);
	}//end setFor()

	/**
	 * Who holds which right on one object in its resolved register and schema.
	 *
	 * @param ObjectEntity  $object The object.
	 * @param ReportedPlace $place  Its register and schema, either possibly unresolved.
	 *
	 * @return array<string, mixed> The access set.
	 */
	private function setIn(ObjectEntity $object, ReportedPlace $place): array {
		$set = $this->holders->holders(
			blocks: [
				'object' => $object->getAuthorization(),
				'schema' => $place->schema?->getAuthorization(),
				'register' => $place->register?->getAuthorization(),
			],
			roleDefinitions: $this->roleDefinitionsOf(register: $place->register)
		);

		return [
			'object' => $object->getUuid(),
			'register' => $place->register?->getSlug(),
			'schema' => $place->schema?->getSlug(),
			'owner' => $object->getOwner(),
			'holders' => $set['holders'],
			'denied' => $set['denied'],
			'denyEnforcement' => $this->enforcement->current(),
		];
	}//end setIn()

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
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function historyFor(ObjectEntity $object, ?string $moment): array {
		$entries = $this->sources->auditTrailMapper->findForObjectByAction(
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
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function schema(mixed $reference): ?Schema {
		try {
			return $this->sources->catalog->schemas()->find($reference);
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
			return $this->sources->catalog->registers()->find($reference);
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
