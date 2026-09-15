<?php

/**
 * OpenRegister RegistrySubscriptionService
 *
 * Implements `registry-subscriptions` (finding B22): a user with `update`
 * on an object of a schema declaring `x-openregister-registry` can request
 * or end a subscription; a connector app (integriq) turns a request into a
 * live subscription and posts changes back through
 * {@see applyInboundUpdate()}. OpenRegister holds no copy of the
 * subscribed person or company beyond the object it already stores.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Registry;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\RegistrySubscriptionMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Coordinates the registry subscription lifecycle and the inbound update.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
 */
class RegistrySubscriptionService {

	/**
	 * Constructor.
	 *
	 * @param RegistrySubscriptionMapper $subscriptionMapper Reads/writes the state table.
	 * @param ObjectService $objectService Applies inbound updates through the ordinary save path.
	 * @param RegistrySubscriptionNotifier $notifier Dispatches events and writes audit rows.
	 * @param RegistryUpdateTargetGuard $updateTargetGuard Resolves a row's schema and checks owned properties.
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		private readonly RegistrySubscriptionMapper $subscriptionMapper,
		private readonly ObjectService $objectService,
		private readonly RegistrySubscriptionNotifier $notifier,
		private readonly RegistryUpdateTargetGuard $updateTargetGuard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read a schema's `x-openregister-registry` annotation, or null when the
	 * schema does not declare one.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array{registry: string, identity: string, owned: array<int, string>}|null
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-a-schema-declares-which-registry-owns-which-properties
	 */
	public function annotationFor(Schema $schema): ?array {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-registry'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$owned = $annotation['owned'] ?? [];
		if (is_array($owned) === false) {
			$owned = [];
		}

		return [
			'registry' => (string)($annotation['registry'] ?? ''),
			'identity' => (string)($annotation['identity'] ?? ''),
			'owned' => array_values(array_map('strval', $owned)),
		];
	}//end annotationFor()

	/**
	 * Request a subscription on an object. Caller MUST have already checked
	 * the requesting user has `update` on `$object` — this service does not
	 * re-check permission (per this codebase's Controller -> Service ->
	 * Mapper layering; the per-object IDOR guard belongs in the controller).
	 *
	 * @param ObjectEntity $object The object to subscribe.
	 * @param Schema $schema The object's schema (must declare `x-openregister-registry`).
	 *
	 * @return RegistrySubscription The persisted row.
	 *
	 * @throws InvalidArgumentException When the schema declares no registry annotation,
	 *                                  or the object has no value for the identity property.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function requestSubscription(ObjectEntity $object, Schema $schema): RegistrySubscription {
		$annotation = $this->annotationFor(schema: $schema);
		if ($annotation === null) {
			throw new InvalidArgumentException(
				'Schema "' . ((string)$schema->getSlug()) . '" does not declare x-openregister-registry.'
			);
		}

		$identityValue = (string)($object->getObject()[$annotation['identity']] ?? '');
		if ($identityValue === '') {
			throw new InvalidArgumentException(
				'Object has no value for identity property "' . $annotation['identity'] . '".'
			);
		}

		$uuid = (string)$object->getUuid();
		$row = $this->subscriptionMapper->findForObject(objectUuid: $uuid);
		$now = new DateTime();
		if ($row === null) {
			$row = new RegistrySubscription();
			$row->setObjectUuid($uuid);
			$row->setCreatedAt($now);
		}

		$row->setRegister((string)$object->getRegister());
		$row->setSchema((string)$object->getSchema());
		$row->setRegistry($annotation['registry']);
		$row->setIdentityValue($identityValue);
		$row->setState(RegistrySubscription::STATE_REQUESTED);
		$row->setRefusalReason(null);
		$row->setUpdatedAt($now);
		$row = $this->subscriptionMapper->save(subscription: $row);

		$this->notifier->requested(
			object: $object,
			register: (string)$object->getRegister(),
			schema: (string)$object->getSchema(),
			registry: $annotation['registry'],
			identityValue: $identityValue,
		);

		return $row;
	}//end requestSubscription()

	/**
	 * End a subscription on an object. Caller MUST have already checked the
	 * requesting user has `update` on `$object`.
	 *
	 * @param ObjectEntity $object The object to unsubscribe.
	 *
	 * @return RegistrySubscription The persisted row.
	 *
	 * @throws DoesNotExistException When the object never had a subscription row.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function endSubscription(ObjectEntity $object): RegistrySubscription {
		$uuid = (string)$object->getUuid();
		$row = $this->subscriptionMapper->findForObject(objectUuid: $uuid);
		if ($row === null) {
			throw new DoesNotExistException('Object "' . $uuid . '" has no registry subscription.');
		}

		$row->setState(RegistrySubscription::STATE_ENDED);
		$row->setUpdatedAt(new DateTime());
		$row = $this->subscriptionMapper->save(subscription: $row);

		$this->notifier->ended(
			object: $object,
			registry: (string)$row->getRegistry(),
			identityValue: (string)$row->getIdentityValue(),
		);

		return $row;
	}//end endSubscription()

	/**
	 * Apply an inbound registry update (`registry-subscriptions` REQ 3) to
	 * every object actively subscribed to `$registry`/`$identityValue`.
	 *
	 * @param string $registry The registry id posting the update (`brp`, `kvk`, ...).
	 * @param string $identityValue The identity value the update is for.
	 * @param array<string, mixed> $properties The changed properties the registry is offering.
	 * @param string $eventReference The registry's own event reference for this change.
	 *
	 * @return array{matched: int, applied: array<int, string>, rejected: array<int, array{objectUuid: string, properties: array<int, string>}>}
	 *         `matched` is how many active subscriptions exist for this
	 *         registry/identity — 0 means "no such subscription", which the
	 *         controller answers 404, distinct from a match whose payload
	 *         changed nothing (`matched` > 0, `applied` and `rejected` both
	 *         empty), which is a 200 no-op per REQ 3 ("an unchanged payload
	 *         announces nothing"). `applied` lists the object uuids that
	 *         were updated; `rejected` names, per object, which supplied
	 *         properties it does not own (that object's update was skipped
	 *         entirely — a partial write of only the owned subset is never
	 *         applied, so a caller cannot misread a rejected update as a
	 *         smaller accepted one).
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
	 */
	public function applyInboundUpdate(
		string $registry,
		string $identityValue,
		array $properties,
		string $eventReference,
	): array {
		$rows = $this->subscriptionMapper->findActiveByIdentity(registry: $registry, identityValue: $identityValue);
		$matched = count($rows);

		$applied = [];
		$rejected = [];

		foreach ($rows as $row) {
			$guardResult = $this->updateTargetGuard->evaluate(row: $row, properties: $properties);
			if ($guardResult['allowed'] === false) {
				$rejected[] = ['objectUuid' => (string)$row->getObjectUuid(), 'properties' => $guardResult['rejected']];
				continue;
			}

			if (count($properties) === 0) {
				// An unchanged payload announces nothing (REQ 3): no write,
				// no audit, and the row's freshness is left untouched.
				continue;
			}

			try {
				$updated = $this->objectService->saveObject(
					object: $properties,
					register: $row->getRegister(),
					schema: $row->getSchema(),
					uuid: $row->getObjectUuid(),
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'[OpenRegister.RegistrySubscriptionService] Inbound update failed to save for object '
					. $row->getObjectUuid() . ': ' . $e->getMessage()
				);
				$rejected[] = ['objectUuid' => (string)$row->getObjectUuid(), 'properties' => array_keys($properties)];
				continue;
			}

			$now = new DateTime();
			$row->setLastUpdateAt($now);
			$row->setLastUpdateSource($eventReference);
			$row->setState(RegistrySubscription::STATE_ACTIVE);
			$row->setUpdatedAt($now);
			$this->subscriptionMapper->save(subscription: $row);

			// A second, explicit audit row naming the REGISTRY as actor.
			// saveObject()'s own audit trail already recorded this write
			// with whichever Nextcloud account the connector's app password
			// belongs to (the ordinary save path's session-derived actor);
			// this row is the one `registry-subscriptions` REQ 3 asks for:
			// "the audit entry names registry `brp` as actor", independent
			// of which account backs the connector's credential.
			$this->notifier->auditInboundUpdate(
				object: $updated,
				registry: $registry,
				eventReference: $eventReference,
				properties: array_keys($properties),
			);

			$applied[] = (string)$row->getObjectUuid();
		}//end foreach

		return ['matched' => $matched, 'applied' => $applied, 'rejected' => $rejected];
	}//end applyInboundUpdate()

	/**
	 * The `@self.registry` render mirror for one object, or null when it has
	 * no subscription row.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function stateFor(string $objectUuid): ?array {
		$row = $this->subscriptionMapper->findForObject(objectUuid: $objectUuid);
		if ($row === null) {
			return null;
		}

		return $row->toSelfMirror();
	}//end stateFor()

	/**
	 * Batch form of {@see stateFor()} for the render-mirror choke point,
	 * avoiding one query per listed row.
	 *
	 * @param array<int, string> $objectUuids The object uuids to look up.
	 *
	 * @return array<string, array<string, mixed>> Mirrors, keyed by object uuid.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function statesFor(array $objectUuids): array {
		$rows = $this->subscriptionMapper->findForObjects(objectUuids: $objectUuids);

		$states = [];
		foreach ($rows as $uuid => $row) {
			$states[$uuid] = $row->toSelfMirror();
		}

		return $states;
	}//end statesFor()
}//end class
