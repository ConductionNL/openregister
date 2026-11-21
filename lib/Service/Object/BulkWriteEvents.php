<?php

/**
 * BulkWriteEvents
 *
 * This file is part of the OpenRegister app for Nextcloud.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Symfony\Component\Uid\Uuid;

/**
 * Decides whether a bulk write fires the save events, and runs a row's pre-save event.
 *
 * The setting is `fireEventsOnBulkWrites`. The first explicit boolean wins, read from
 * the schema's configuration, the register's configuration, then the app config value
 * `fire_events_on_bulk_writes`. Without one, a schema that declares
 * `x-openregister-lifecycle` fires the events: its transition guard only holds when
 * every write runs it.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkWriteEvents {

	/**
	 * The schema and register configuration key.
	 */
	public const CONFIG_KEY = 'fireEventsOnBulkWrites';

	/**
	 * The instance-wide app config key.
	 */
	public const APP_CONFIG_KEY = 'fire_events_on_bulk_writes';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $events    Nextcloud's dispatcher.
	 * @param IAppConfig       $appConfig The app config holding the instance default.
	 */
	public function __construct(
		private readonly IEventDispatcher $events,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether a bulk write to this schema fires the save events.
	 *
	 * @param Schema        $schema   The row's schema.
	 * @param Register|null $register The row's register, when known.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-whether-a-bulk-write-fires-the-save-events-is-configured-per-schema
	 */
	public function appliesTo(Schema $schema, ?Register $register): bool {
		$schemaConfig = ($schema->getConfiguration() ?? []);
		$explicit     = ($this->declared(configuration: $schemaConfig)
			?? $this->declared(configuration: ($register?->getConfiguration() ?? []))
			?? $this->instanceValue());
		if ($explicit !== null) {
			return $explicit;
		}

		return is_array($schemaConfig['x-openregister-lifecycle'] ?? null) === true;
	}//end appliesTo()

	/**
	 * Run a row's pre-save event: the row as it is to be written, and the refusal or null.
	 *
	 * A create without an identity gets its uuid here. The returned row carries it as
	 * `id`, because ObjectEntity::getObject() puts the uuid there, so the event and the
	 * stored row name the same object. Data a listener sets on the object or through
	 * setModifiedData() is carried into the row.
	 *
	 * @param array            $row The row: business fields with `@self` beside them.
	 * @param BulkSafeguardRun $run The safeguard pass, resolved for this row.
	 *
	 * @return array{0: array, 1: string|null}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function screen(array $row, BulkSafeguardRun $run): array {
		if ($run->rowUuid === null) {
			$run->rowUuid = Uuid::v4()->toRfc4122();
		}

		$entity = $this->entityFor(row: $row, run: $run);
		$event  = self::guardEvent(entity: $entity, run: $run);

		$this->events->dispatchTyped($event);
		if ($event->isPropagationStopped() === true) {
			return [$row, (string)($event->getErrors()['message'] ?? self::denial(run: $run))];
		}

		$business = array_merge(($entity->getObject() ?? []), $event->getModifiedData());

		return [self::withSelf(business: $business, row: $row), null];
	}//end screen()

	/**
	 * The event a hook may stop: creating for a new row, updating for an existing one.
	 *
	 * @param ObjectEntity     $entity The row as an entity.
	 * @param BulkSafeguardRun $run    The row being screened.
	 *
	 * @return ObjectCreatingEvent|ObjectUpdatingEvent
	 */
	private static function guardEvent(ObjectEntity $entity, BulkSafeguardRun $run): ObjectCreatingEvent|ObjectUpdatingEvent {
		if ($run->rowExisting !== null) {
			return new ObjectUpdatingEvent(newObject: $entity, oldObject: $run->rowExisting);
		}

		return new ObjectCreatingEvent(object: $entity);
	}//end guardEvent()

	/**
	 * The refusal a stopped event gives when its hook names none.
	 *
	 * @param BulkSafeguardRun $run The row being screened.
	 *
	 * @return string
	 */
	private static function denial(BulkSafeguardRun $run): string {
		if ($run->rowExisting !== null) {
			return 'Object update rejected by hook';
		}

		return 'Object creation rejected by hook';
	}//end denial()

	/**
	 * The screened business data, with the row's `@self` block carried over when it has one.
	 *
	 * @param array<string, mixed> $business The business data after the hooks.
	 * @param array<string, mixed> $row      The row as given.
	 *
	 * @return array<string, mixed>
	 */
	private static function withSelf(array $business, array $row): array {
		if (array_key_exists('@self', $row) === true) {
			$business['@self'] = $row['@self'];
		}

		return $business;
	}//end withSelf()

	/**
	 * The object the event carries: the stored one with the row's data, or a new one.
	 *
	 * @param array            $row The row.
	 * @param BulkSafeguardRun $run The safeguard pass.
	 *
	 * @return ObjectEntity
	 */
	private function entityFor(array $row, BulkSafeguardRun $run): ObjectEntity {
		$entity = new ObjectEntity();
		if ($run->rowExisting !== null) {
			$entity = clone $run->rowExisting;
		}

		$business = $row;
		unset($business['@self']);
		$entity->setObject($business);
		$entity->setUuid((string)$run->rowUuid);
		$entity->setSchema((string)$run->rowSchema?->getId());
		self::stampRegisterAndOwner(entity: $entity, run: $run);

		return $entity;
	}//end entityFor()

	/**
	 * Set the row's register, and the acting user as owner of a new row.
	 *
	 * @param ObjectEntity     $entity The row as an entity.
	 * @param BulkSafeguardRun $run    The row being screened.
	 *
	 * @return void
	 */
	private static function stampRegisterAndOwner(ObjectEntity $entity, BulkSafeguardRun $run): void {
		if ($run->rowRegister !== null) {
			$entity->setRegister((string)$run->rowRegister->getId());
		}

		if ($run->rowExisting === null && $run->currentUser !== null) {
			$entity->setOwner($run->userId());
		}
	}//end stampRegisterAndOwner()

	/**
	 * The boolean a configuration block declares, or null when it declares none.
	 *
	 * @param array $configuration A schema or register configuration block.
	 *
	 * @return bool|null
	 */
	private function declared(array $configuration): ?bool {
		$value = ($configuration[self::CONFIG_KEY] ?? null);
		if (is_bool($value) === true) {
			return $value;
		}

		return null;
	}//end declared()

	/**
	 * The instance-wide value, or null when it is not set to `true` or `false`.
	 *
	 * @return bool|null
	 */
	private function instanceValue(): ?bool {
		return match ($this->appConfig->getValueString('openregister', self::APP_CONFIG_KEY, '')) {
			'true' => true,
			'false' => false,
			default => null,
		};
	}//end instanceValue()
}//end class
