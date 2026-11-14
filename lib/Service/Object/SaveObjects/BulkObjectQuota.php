<?php

/**
 * Enforces a schema's per-organisation object cap on a bulk save.
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
 *
 * @spec openspec/changes/object-quota-per-organisation/tasks.md#4-follow-ups
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\ObjectQuotaListener;
use OCA\OpenRegister\Service\Quota\ObjectQuotaServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bulk counterpart of ObjectQuotaListener (task 4.1).
 *
 * A bulk save does not dispatch `ObjectCreatingEvent` per row, and the rows of
 * one batch are not stored yet while the batch is screened, so the listener's
 * one-count-per-create cannot hold the cap here. This counts each register,
 * schema and organisation once, then admits the batch's creates against that
 * count in order: the first rows up to the cap go through, the rest are
 * refused with `object-quota-exceeded`. A row that updates a stored object is
 * never counted, and a row in no organisation is not subject to the cap. A
 * count that cannot be made refuses that group's creates with
 * `object-quota-unchecked`, as the listener does.
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
 */
final class BulkObjectQuota {

	/**
	 * Constructor.
	 *
	 * @param ObjectQuotaServiceInterface $quotas Reads the cap and counts the stored objects.
	 * @param LoggerInterface             $logger Logs a count that failed.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
	 */
	public function __construct(
		private readonly ObjectQuotaServiceInterface $quotas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Split prepared rows into those within the cap and those refused.
	 *
	 * @param array<int, array<string, mixed>> $rows        The prepared rows, `@self` stamped.
	 * @param array<string, bool>              $storedUuids Uuids of rows that update a stored object.
	 * @param Closure                          $schemaOf    Resolves a schema reference to a Schema.
	 * @param Closure                          $registerOf  Resolves a register reference to its integer id.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{object: array<string, mixed>, error: string, code: string}>}
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
	 */
	public function screen(array $rows, array $storedUuids, Closure $schemaOf, Closure $registerOf): array {
		$passed  = [];
		$refused = [];
		$tallies = [];
		foreach ($rows as $row) {
			$scope = self::scopeOf(row: $row, storedUuids: $storedUuids);
			if ($scope === null) {
				$passed[] = $row;
				continue;
			}

			$key = implode('|', $scope);
			if (array_key_exists($key, $tallies) === false) {
				$tallies[$key] = $this->open(scope: $scope, schemaOf: $schemaOf, registerOf: $registerOf);
			}

			$tally = $tallies[$key];
			if ($tally === null || $tally->admit() === true) {
				$passed[] = $row;
				continue;
			}

			$refused[] = self::refusal(row: $row, tally: $tally);
		}//end foreach

		return [$passed, $refused];
	}//end screen()

	/**
	 * The register, schema and organisation a new row counts against; null when it is not counted.
	 *
	 * @param array<string, mixed> $row         The prepared row.
	 * @param array<string, bool>  $storedUuids Uuids of rows that update a stored object.
	 *
	 * @return array{register: string, schema: string, organisation: string}|null The scope.
	 */
	private static function scopeOf(array $row, array $storedUuids): ?array {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			return null;
		}

		$uuid = (string)($self['uuid'] ?? $self['id'] ?? '');
		if ($uuid !== '' && isset($storedUuids[$uuid]) === true) {
			return null;
		}

		$scope = [
			'register'     => self::reference(value: ($self['register'] ?? null)),
			'schema'       => self::reference(value: ($self['schema'] ?? null)),
			'organisation' => self::reference(value: ($self['organisation'] ?? null)),
		];
		if (in_array('', $scope, true) === true) {
			return null;
		}

		return $scope;
	}//end scopeOf()

	/**
	 * Read the cap and count one scope once; null when its schema declares no cap.
	 *
	 * @param array{register: string, schema: string, organisation: string} $scope      The scope.
	 * @param Closure                                                       $schemaOf   Resolves the schema.
	 * @param Closure                                                       $registerOf Resolves the register id.
	 *
	 * @return BulkQuotaTally|null The tally.
	 */
	private function open(array $scope, Closure $schemaOf, Closure $registerOf): ?BulkQuotaTally {
		try {
			$schema = $schemaOf($scope['schema']);
		} catch (Throwable $unknown) {
			// No schema, no quota to read: the save refuses an unknown schema itself.
			unset($unknown);
			return null;
		}

		if (($schema instanceof Schema) === false) {
			return null;
		}

		$limit = $this->quotas->limitFor(schema: $schema);
		if ($limit === null) {
			return null;
		}

		return new BulkQuotaTally(
			schemaRef: $scope['schema'],
			schemaSlug: (string)$schema->getSlug(),
			limit: $limit,
			stored: $this->stored(scope: $scope, schema: $schema, registerOf: $registerOf)
		);
	}//end open()

	/**
	 * The stored count of one scope; null when it could not be made.
	 *
	 * @param array{register: string, schema: string, organisation: string} $scope      The scope.
	 * @param Schema                                                        $schema     The schema.
	 * @param Closure                                                       $registerOf Resolves the register id.
	 *
	 * @return int|null The count.
	 */
	private function stored(array $scope, Schema $schema, Closure $registerOf): ?int {
		try {
			return $this->quotas->count(registerId: (int)$registerOf($scope['register']), schema: $schema, organisationUuid: $scope['organisation']);
		} catch (Throwable $failure) {
			$this->logger->error(
				message: '[BulkObjectQuota] The quota count failed, refusing the bulk creates: ' . $failure->getMessage(),
				context: ['app' => 'openregister', 'schema' => $scope['schema'], 'organisation' => $scope['organisation']]
			);
			return null;
		}
	}//end stored()

	/**
	 * The refusal of one row.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param BulkQuotaTally       $tally Its scope's tally.
	 *
	 * @return array{object: array<string, mixed>, error: string, code: string}
	 */
	private static function refusal(array $row, BulkQuotaTally $tally): array {
		if ($tally->stored === null) {
			return [
				'object' => $row,
				'error'  => ObjectQuotaListener::uncheckedMessage(schema: $tally->schemaRef),
				'code'   => ObjectQuotaListener::ERROR_CODE_UNCHECKED,
			];
		}

		return [
			'object' => $row,
			'error'  => ObjectQuotaListener::exceededMessage(count: $tally->held(), limit: $tally->limit, schema: $tally->schemaSlug),
			'code'   => ObjectQuotaListener::ERROR_CODE,
		];
	}//end refusal()

	/**
	 * A row's register, schema or organisation reference as text; '' when absent or not scalar.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The text.
	 */
	private static function reference(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end reference()
}//end class
