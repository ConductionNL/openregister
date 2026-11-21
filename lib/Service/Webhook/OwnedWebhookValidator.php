<?php

/**
 * OpenRegister OwnedWebhookValidator
 *
 * What a webhook owned by a person may be (webhooks-for-owners, D-4).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Webhook
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Webhook;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\WebhookMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\OwnedWebhookView;
use OCP\IAppConfig;
use Throwable;

/**
 * Checks the subscription of an owned webhook and names the field it refuses.
 *
 * An owned webhook:
 *
 * - subscribes to a non-empty subset of the object created, updated and
 *   deleted events; an empty list, which means every event for an
 *   administrator's webhook, is refused;
 * - names one register in `filters.register` and one or more schemas in
 *   `filters.schema`, which its owner can read;
 * - sets neither `configuration.interceptRequests` nor
 *   `configuration.allowPrivateTargets`;
 * - is one of at most `webhookOwnerMax` (default 20) webhooks of its owner.
 *
 * Every lookup that fails refuses: a scope that cannot be checked is not
 * accepted.
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
 */
class OwnedWebhookValidator {

	/**
	 * The app config key holding how many webhooks one person may own.
	 *
	 * @var string
	 */
	public const MAX_KEY = 'webhookOwnerMax';

	/**
	 * How many webhooks one person may own when nothing is configured.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX = 20;

	/**
	 * The configuration keys that stay an administrator's.
	 *
	 * @var array<int, string>
	 */
	private const ADMIN_ONLY_CONFIGURATION = ['interceptRequests', 'allowPrivateTargets'];

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface  $registers   Resolves the register in scope.
	 * @param SchemaMapperInterface    $schemas     Resolves the schemas in scope.
	 * @param PermissionCheckInterface $permissions The owner's read right on each schema.
	 * @param WebhookMapperInterface   $webhooks    Counts the webhooks the owner has.
	 * @param IAppConfig               $appConfig   Holds the per-owner cap.
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly PermissionCheckInterface $permissions,
		private readonly WebhookMapperInterface $webhooks,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Check an owned webhook's subscription.
	 *
	 * @param array<string, mixed> $data     The webhook as it will be stored: for an update, the stored
	 *                                       webhook with the request's fields over it.
	 * @param string               $owner    The owner's user id.
	 * @param bool                 $creating Whether this is a new webhook, which the cap counts.
	 *
	 * @return array{refusal: array{field: string, message: string}|null, data: array<string, mixed>}
	 *   The first refusal, or null, and the data with its scope normalised to stored ids.
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
	 */
	public function check(array $data, string $owner, bool $creating): array {
		$refusal = $this->eventsRefusal(events: $data['events'] ?? null)
			?? $this->configurationRefusal(configuration: $data['configuration'] ?? null)
			?? $this->capRefusal(owner: $owner, creating: $creating);
		if ($refusal !== null) {
			return ['refusal' => $refusal, 'data' => $data];
		}

		$scope = $this->scope(filters: $data['filters'] ?? null, owner: $owner);
		if (isset($scope['refusal']) === true) {
			return ['refusal' => $scope['refusal'], 'data' => $data];
		}

		$data['filters'] = $scope['filters'];

		return ['refusal' => null, 'data' => $data];
	}//end check()

	/**
	 * The refusal of the event list, or null.
	 *
	 * @param mixed $events The `events` field.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function eventsRefusal(mixed $events): ?array {
		$events = $this->decoded(value: $events);
		if (is_array($events) === false || $events === []) {
			return self::refuse(field: 'events', message: 'an owned webhook must name one or more of the object created, updated and deleted events');
		}

		foreach ($events as $event) {
			if (is_string($event) === false || in_array($event, OwnedWebhookView::EVENTS, true) === false) {
				return self::refuse(field: 'events', message: 'an owned webhook may only subscribe to the object created, updated and deleted events');
			}
		}

		return null;
	}//end eventsRefusal()

	/**
	 * The refusal of an administrator-only configuration key, or null.
	 *
	 * @param mixed $configuration The `configuration` field.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function configurationRefusal(mixed $configuration): ?array {
		$configuration = $this->decoded(value: $configuration);
		if ($configuration === null || $configuration === '') {
			return null;
		}

		if (is_array($configuration) === false) {
			return self::refuse(field: 'configuration', message: 'configuration must be an object');
		}

		foreach (self::ADMIN_ONLY_CONFIGURATION as $key) {
			$value = $configuration[$key] ?? null;
			if ($value !== null && $value !== false) {
				return self::refuse(field: 'configuration.' . $key, message: 'an owned webhook may not set ' . $key);
			}
		}

		return null;
	}//end configurationRefusal()

	/**
	 * The refusal of one webhook more than the owner may have, or null.
	 *
	 * @param string $owner    The owner's user id.
	 * @param bool   $creating Whether a webhook is being added.
	 *
	 * @return array{field: string, message: string}|null
	 */
	private function capRefusal(string $owner, bool $creating): ?array {
		if ($creating === false) {
			return null;
		}

		$max = $this->appConfig->getValueInt('openregister', self::MAX_KEY, self::DEFAULT_MAX);
		try {
			$owned = count($this->webhooks->findOwnedBy(uid: $owner));
		} catch (Throwable) {
			return self::refuse(field: 'owner', message: 'the webhooks you own could not be counted');
		}

		if ($owned >= $max) {
			return self::refuse(field: 'owner', message: 'a person may own at most ' . $max . ' webhooks');
		}

		return null;
	}//end capRefusal()

	/**
	 * The scope as stored ids, or the refusal naming the field.
	 *
	 * @param mixed  $filters The `filters` field.
	 * @param string $owner   The owner's user id.
	 *
	 * @return array{filters?: array<string, mixed>, refusal?: array{field: string, message: string}}
	 */
	private function scope(mixed $filters, string $owner): array {
		$filters = $this->decoded(value: $filters);
		if (is_array($filters) === false) {
			return ['refusal' => self::refuse(field: 'filters.register', message: 'an owned webhook must name a register')];
		}

		$register = $filters['register'] ?? null;
		if ((is_string($register) === false && is_int($register) === false) || trim((string)$register) === '') {
			return ['refusal' => self::refuse(field: 'filters.register', message: 'an owned webhook must name exactly one register')];
		}

		$schemas = $filters['schema'] ?? null;
		if (is_string($schemas) === true || is_int($schemas) === true) {
			$schemas = [$schemas];
		}

		if (is_array($schemas) === false || $schemas === []) {
			return ['refusal' => self::refuse(field: 'filters.schema', message: 'an owned webhook must name one or more schemas')];
		}

		try {
			$registerId = (string)$this->registers->find(id: $register)->getId();
		} catch (Throwable) {
			return ['refusal' => self::refuse(field: 'filters.register', message: 'the register does not exist or you cannot read it')];
		}

		$schemaIds = [];
		foreach (array_values($schemas) as $schema) {
			$schemaId = $this->readableSchemaId(schema: $schema, owner: $owner, registerId: $registerId);
			if ($schemaId === null) {
				return ['refusal' => self::refuse(field: 'filters.schema', message: 'a schema does not exist or you cannot read it')];
			}

			$schemaIds[] = $schemaId;
		}

		$filters['register'] = $registerId;
		$filters['schema']   = array_values(array_unique($schemaIds));

		return ['filters' => $filters];
	}//end scope()

	/**
	 * The schema's id when it exists and the owner may read it in the register, else null.
	 *
	 * @param mixed  $schema     The schema id or slug.
	 * @param string $owner      The owner's user id.
	 * @param string $registerId The register's id.
	 *
	 * @return string|null
	 */
	private function readableSchemaId(mixed $schema, string $owner, string $registerId): ?string {
		if ((is_string($schema) === false && is_int($schema) === false) || trim((string)$schema) === '') {
			return null;
		}

		try {
			$entity = $this->schemas->find(id: $schema);
			$may    = $this->permissions->hasPermission(schema: $entity, action: 'read', userId: $owner, register: $registerId);
		} catch (Throwable) {
			return null;
		}

		if ($may !== true) {
			return null;
		}

		return (string)$entity->getId();
	}//end readableSchemaId()

	/**
	 * A JSON string decoded; anything else as given.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function decoded(mixed $value): mixed {
		if (is_string($value) === true && $value !== '') {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return $value;
	}//end decoded()

	/**
	 * A refusal naming its field.
	 *
	 * @param string $field   The field.
	 * @param string $message Why.
	 *
	 * @return array{field: string, message: string}
	 */
	private static function refuse(string $field, string $message): array {
		return ['field' => $field, 'message' => $field . ': ' . $message];
	}//end refuse()
}//end class
