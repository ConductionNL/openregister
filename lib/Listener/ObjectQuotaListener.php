<?php

/**
 * OpenRegister ObjectQuotaListener
 *
 * Refuses a create that would take an organisation past its schema quota.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\Quota\ObjectQuotaServiceInterface;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Enforces `x-openregister-quota.perOrganisation` on create.
 *
 * Only a create counts. An update never adds an object, so it is never
 * refused here. An object that belongs to no organisation is not subject to
 * a per-organisation quota.
 *
 * Refuses through the event (`setErrors` plus `stopPropagation`), the idiom
 * UniqueConstraintListener uses, which the save path turns into a refused
 * write with the structured error.
 *
 * A count that cannot be made REFUSES the create (`object-quota-unchecked`)
 * and logs an error. It allowed it before, and that is how a count that never
 * worked (live pass O10: every count failed) left the quota silently off for
 * every schema. Only a schema that declares a quota is affected, and the
 * refusal says the quota could not be checked rather than that it was reached.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
 */
class ObjectQuotaListener implements IEventListener {

	/**
	 * The error code a refused create carries.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'object-quota-exceeded';

	/**
	 * The error code of a create refused because the quota could not be checked.
	 *
	 * @var string
	 */
	public const ERROR_CODE_UNCHECKED = 'object-quota-unchecked';

	/**
	 * Constructor.
	 *
	 * @param ObjectQuotaServiceInterface $quotas  Reads the cap and counts.
	 * @param EventSchemaLookup $lookup Resolves the object's schema and logs a failure.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function __construct(
		private readonly ObjectQuotaServiceInterface $quotas,
		private readonly EventSchemaLookup $lookup,
	) {
	}//end __construct()

	/**
	 * Refuse a create past the quota.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$refusal = $this->refusalFor(object: $event->getObject());
		if ($refusal !== null) {
			$event->setErrors($refusal);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * The refusal this create earns under its schema's quota, or null when it may go ahead.
	 *
	 * @param ObjectEntity $object The object being created.
	 *
	 * @return array<string, mixed>|null The refusal.
	 */
	private function refusalFor(ObjectEntity $object): ?array {
		$scope = $this->quotaScopeOf(object: $object);
		if ($scope === null) {
			return null;
		}

		$schema = $this->schemaOf(reference: $scope['schema']);
		if ($schema === null) {
			return null;
		}

		$scope['limit'] = $this->quotas->limitFor(schema: $schema);
		if ($scope['limit'] === null) {
			return null;
		}

		return $this->countRefusal(scope: $scope, schema: $schema);
	}//end refusalFor()

	/**
	 * The schema a create names, or null when there is none to read a quota from.
	 *
	 * @param string $reference The schema reference.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOf(string $reference): ?Schema {
		try {
			return $this->lookup->catalog->schemas()->find(id: $reference, _rbac: false, _multitenancy: false);
		} catch (Throwable $failure) {
			// No schema, no quota to read: the save path refuses an unknown schema itself.
			return null;
		}
	}//end schemaOf()

	/**
	 * The organisation, schema and register a create is counted in, or null when it is not counted.
	 *
	 * @param ObjectEntity $object The object being created.
	 *
	 * @return array{organisation: string, schema: string, register: string}|null The scope.
	 */
	private function quotaScopeOf(ObjectEntity $object): ?array {
		$scope = [
			'organisation' => (string)($object->getOrganisation() ?? ''),
			'schema' => (string)($object->getSchema() ?? ''),
			'register' => (string)($object->getRegister() ?? ''),
		];
		if ($scope['organisation'] === '' || $scope['schema'] === '' || is_numeric($scope['register']) === false) {
			return null;
		}

		return $scope;
	}//end quotaScopeOf()

	/**
	 * Count the organisation's objects of the schema and refuse at the limit.
	 *
	 * A count that fails refuses the create: a quota that cannot be checked is
	 * not a quota that holds.
	 *
	 * @param array{organisation: string, schema: string, register: string, limit: int} $scope  The scope and its limit.
	 * @param Schema                                                                      $schema The object's schema.
	 *
	 * @return array<string, mixed>|null The refusal.
	 */
	private function countRefusal(array $scope, Schema $schema): ?array {
		try {
			$count = $this->quotas->count(registerId: (int)$scope['register'], schema: $schema, organisationUuid: $scope['organisation']);
		} catch (Throwable $failure) {
			return $this->uncheckedRefusal(scope: $scope, failure: $failure);
		}

		if ($count < $scope['limit']) {
			return null;
		}

		$scope['count'] = $count;
		return self::exceededRefusal(scope: $scope, schema: $schema);
	}//end countRefusal()

	/**
	 * Log a failed count, and the refusal it earns.
	 *
	 * @param array{organisation: string, schema: string} $scope   The scope.
	 * @param Throwable                                   $failure Why the count failed.
	 *
	 * @return array<string, string> The refusal.
	 */
	private function uncheckedRefusal(array $scope, Throwable $failure): array {
		$this->lookup->logger->error(
			message: '[ObjectQuotaListener] The quota count failed, refusing the create: ' . $failure->getMessage(),
			context: ['app' => 'openregister', 'schema' => $scope['schema'], 'organisation' => $scope['organisation']]
		);

		return [
			'code' => self::ERROR_CODE_UNCHECKED,
			'message' => self::uncheckedMessage(schema: $scope['schema']),
		];
	}//end uncheckedRefusal()

	/**
	 * The refusal of a create at the limit.
	 *
	 * @param array{organisation: string, limit: int, count: int} $scope  The scope, its limit and how many objects the organisation holds.
	 * @param Schema                                              $schema The object's schema.
	 *
	 * @return array<string, mixed> The refusal.
	 */
	private static function exceededRefusal(array $scope, Schema $schema): array {
		return [
			'code' => self::ERROR_CODE,
			'message' => self::exceededMessage(count: $scope['count'], limit: $scope['limit'], schema: (string)$schema->getSlug()),
			'schema' => (string)$schema->getSlug(),
			'organisation' => $scope['organisation'],
			'count' => $scope['count'],
			'limit' => $scope['limit'],
		];
	}//end exceededRefusal()

	/**
	 * The refusal message of a create past the cap, shared with the bulk path.
	 *
	 * @param int    $count  How many the organisation holds.
	 * @param int    $limit  The cap.
	 * @param string $schema The schema slug.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
	 */
	public static function exceededMessage(int $count, int $limit, string $schema): string {
		return sprintf('This organisation already holds %d of at most %d objects of schema "%s".', $count, $limit, $schema);
	}//end exceededMessage()

	/**
	 * The refusal message of a create whose quota could not be checked, shared with the bulk path.
	 *
	 * @param string $schema The schema reference.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
	 */
	public static function uncheckedMessage(string $schema): string {
		return sprintf(
			'Schema "%s" limits how many objects one organisation holds, and that limit could not be checked just now, so nothing was created. Try again.',
			$schema
		);
	}//end uncheckedMessage()
}//end class
