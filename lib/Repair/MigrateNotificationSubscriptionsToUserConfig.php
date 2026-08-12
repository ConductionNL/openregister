<?php

/**
 * MigrateNotificationSubscriptionsToUserConfig — one-shot, idempotent
 * migration of the DEPRECATED per-user notification subscription rows into
 * the override-only Nextcloud user-config model.
 *
 * The legacy `openregister_notification_subscriptions` table stored a
 * per-user opt-in to a (register, schema). The notification engine now
 * sources rules ONLY from the schema annotation `x-openregister-notifications`
 * and stores per-user preferences as override-only user-config values. To
 * preserve a subscriber's intent ("I want this schema's notifications"), this
 * step writes an explicit `enabled: true` override for every notification key
 * the subscribed schema declares — for the subscribing user only.
 *
 * Idempotent: writing the same user-config value repeatedly is a no-op, so the
 * step can run on every upgrade during the deprecation window. The original
 * subscription rows are LEFT IN PLACE (the controller still responds while
 * deprecated); a later change drops the table.
 *
 * Strictly defensive — never throws, never deletes. On a fresh install (table
 * absent, mappers unresolvable) it logs "skipped" and returns.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notification-schema-rules-and-userconfig-prefs/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step: migrate legacy notification subscriptions to user-config overrides.
 *
 * @SuppressWarnings(PHPMD.LongClassName) Class name mirrors the IRepairStep convention of describing
 * exactly what the one-shot migration does; abbreviating it would break the self-documenting intent
 * that makes repair steps auditable in occ and the admin UI.
 */
class MigrateNotificationSubscriptionsToUserConfig implements IRepairStep {
	/**
	 * Legacy subscription table (without the oc_ prefix).
	 */
	private const TABLE = 'openregister_notification_subscriptions';

	/**
	 * Appconfig marker key. Once set, the migration has already run and must not
	 * run again — re-running would force enabled:true and resurrect overrides a
	 * user has since manually disabled (OPS-8).
	 */
	private const DONE_KEY = 'notification_subscription_migration_done';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — used to lazily resolve
	 *                                      SchemaMapper / NotificationPreferenceService /
	 *                                      IDBConnection so the step never blocks app boot.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ + admin UI.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Migrate deprecated notification subscriptions to user-config overrides';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) run() validates table availability, iterates
	 * subscription rows, and per row: guards on null userId/schemaId, finds the schema, validates
	 * the notification annotation, and loops keys — each check is a defensive guard required for
	 * the idempotent migration contract.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple independent early-returns (table absent, no
	 * rows, service unavailable, null userId, null schemaId, schema not found, missing annotation)
	 * represent distinct migration-skip conditions, not extractable sub-paths.
	 */
	public function run(IOutput $output): void {
		$output->info('[OpenRegister] Migrating legacy notification subscriptions to user-config...');

		// Run-once guard: if the marker is set the migration already executed on a
		// previous upgrade. Re-running would force enabled:true and re-enable
		// overrides the user has since manually disabled (OPS-8).
		$appConfig = $this->resolveAppConfig();
		if ($appConfig !== null
			&& $appConfig->getValueBool('openregister', self::DONE_KEY, false) === true
		) {
			$output->info('[OpenRegister] Notification subscription migration already completed — skipping.');
			return;
		}

		$rows = $this->loadSubscriptionRows();
		if ($rows === null) {
			$output->info('[OpenRegister] Subscription table unavailable — migration skipped (normal on first install).');
			return;
		}

		if ($rows === []) {
			$output->info('[OpenRegister] No notification subscriptions to migrate.');
			$this->markDone(appConfig: $appConfig);
			return;
		}

		$prefs = $this->resolvePreferenceService();
		$schemaMapper = $this->resolveSchemaMapper();
		if ($prefs === null || $schemaMapper === null) {
			$output->warning('[OpenRegister] Preference service / schema mapper unavailable — migration skipped.');
			return;
		}

		$migrated = 0;
		foreach ($rows as $row) {
			$userId = (string)($row['user_id'] ?? '');
			$schemaId = ($row['schema_id'] ?? null);
			if ($userId === '' || $schemaId === null) {
				// Register-wide (schema_id NULL) subscriptions don't map to a
				// single schema's keys; leave them for manual review.
				continue;
			}

			try {
				$schema = $schemaMapper->find((int)$schemaId);
			} catch (\Throwable $e) {
				continue;
			}

			if (($schema instanceof Schema) === false) {
				continue;
			}

			$config = ($schema->getConfiguration() ?? []);
			$notifications = ($config['x-openregister-notifications'] ?? null);
			if (is_array($notifications) === false) {
				continue;
			}

			$slug = (string)($schema->getSlug() ?? $schema->getId());
			foreach (array_keys($notifications) as $key) {
				$prefs->setOverride(
					userId: $userId,
					schemaSlug: $slug,
					notificationKey: (string)$key,
					override: ['enabled' => true]
				);
				$migrated++;
			}
		}//end foreach

		$output->info(sprintf('[OpenRegister] Migrated %d notification subscription override(s).', $migrated));
		$this->markDone(appConfig: $appConfig);
	}//end run()

	/**
	 * Persist the run-once marker so subsequent upgrades skip the migration (OPS-8).
	 *
	 * @param \OCP\IAppConfig|null $appConfig The app-config service, or null when unresolvable.
	 *
	 * @return void
	 */
	private function markDone(?\OCP\IAppConfig $appConfig): void {
		if ($appConfig === null) {
			return;
		}

		try {
			$appConfig->setValueBool('openregister', self::DONE_KEY, true);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[OpenRegister] Could not persist notification-migration marker',
				['exception' => $e]
			);
		}
	}//end markDone()

	/**
	 * Lazily resolve the app-config service.
	 *
	 * @return \OCP\IAppConfig|null The service, or null when unresolvable.
	 */
	private function resolveAppConfig(): ?\OCP\IAppConfig {
		try {
			$appConfig = $this->container->get(\OCP\IAppConfig::class);
			if ($appConfig instanceof \OCP\IAppConfig) {
				return $appConfig;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveAppConfig()

	/**
	 * Read the legacy subscription rows directly. Returns null when the
	 * table cannot be queried (e.g. fresh install before schema creation).
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function loadSubscriptionRows(): ?array {
		try {
			$db = $this->container->get(IDBConnection::class);
		} catch (\Throwable $e) {
			return null;
		}

		try {
			$qb = $db->getQueryBuilder();
			$qb->select('user_id', 'schema_id')->from(self::TABLE);
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			if (is_array($rows) === false) {
				return [];
			}

			return $rows;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[OpenRegister] MigrateNotificationSubscriptions could not read table — skipping',
				['exception' => $e]
			);
			return null;
		}
	}//end loadSubscriptionRows()

	/**
	 * Lazily resolve the preference service.
	 *
	 * @return NotificationPreferenceService|null
	 */
	private function resolvePreferenceService(): ?NotificationPreferenceService {
		try {
			$service = $this->container->get(NotificationPreferenceService::class);
			if ($service instanceof NotificationPreferenceService) {
				return $service;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolvePreferenceService()

	/**
	 * Lazily resolve the schema mapper.
	 *
	 * @return mixed The SchemaMapper, or null when unresolvable.
	 */
	private function resolveSchemaMapper(): mixed {
		try {
			$mapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			if (method_exists($mapper, 'find') === true) {
				return $mapper;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveSchemaMapper()
}//end class
