<?php

/**
 * OpenRegister ApprovalChainAnnotationInstaller
 *
 * Runs at schema-save time. For every chain declared in
 * `x-openregister-approval-chains`, upserts an `ApprovalChain` config row — the
 * same row shape `POST /api/approval-chains` already produces — so the
 * declarative annotation is a provisioning source for the existing approval
 * engine instead of a second, parallel one. Mirrors
 * `Service\Notification\NotificationsAnnotationInstaller` exactly (same
 * SchemaCreatedEvent/SchemaUpdatedEvent hook, same find-then-upsert shape).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that provisions `ApprovalChain` rows from a schema's declarative
 * `x-openregister-approval-chains` block.
 *
 * @template-implements IEventListener<Event>
 */
class ApprovalChainAnnotationInstaller implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ApprovalChainMapper $chainMapper Mapper used to upsert ApprovalChain rows.
	 * @param LoggerInterface $logger Logger for installer diagnostics.
	 */
	public function __construct(
		private readonly ApprovalChainMapper $chainMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Listener entry point: dispatches schema-saved events to the installer.
	 *
	 * @param Event $event The event carrying the saved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function handle(Event $event): void {
		$schema = null;
		if (method_exists($event, 'getSchema') === true) {
			$schema = $event->getSchema();
		} elseif (method_exists($event, 'getNewSchema') === true) {
			$schema = $event->getNewSchema();
		}

		if (($schema instanceof Schema) === false) {
			return;
		}

		$this->installSchema(schema: $schema);
	}//end handle()

	/**
	 * Upsert an `ApprovalChain` row for every declared chain.
	 *
	 * Idempotent: safe to call repeatedly (also called defensively by
	 * `ApprovalChainGateListener` immediately before resolving a chain, so a
	 * gate evaluation never depends on this listener having already run for the
	 * current schema revision).
	 *
	 * @param Schema $schema The schema whose annotations should be installed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function installSchema(Schema $schema): void {
		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false) {
			return;
		}

		$schemaId = $schema->getId();
		if ($schemaId === null) {
			return;
		}

		foreach ($chains as $chainKey => $spec) {
			if (is_string($chainKey) === false || is_array($spec) === false) {
				continue;
			}

			$approvers = ($spec['approvers'] ?? null);
			if (is_array($approvers) === false || $approvers === []) {
				continue;
			}

			$this->upsertChain(schemaId: (int)$schemaId, chainKey: $chainKey, approvers: $approvers);
		}
	}//end installSchema()

	/**
	 * Upsert a single `ApprovalChain` row from one chain's `approvers` list.
	 *
	 * @param int $schemaId Owning schema id.
	 * @param string $chainKey Declarative chain key (becomes `name`).
	 * @param array<int, array<string, mixed>> $approvers Declared `{role, min, minAmount?}` tiers.
	 *
	 * @return void
	 */
	private function upsertChain(int $schemaId, string $chainKey, array $approvers): void {
		try {
			$steps = [];
			$order = 1;
			foreach ($approvers as $tier) {
				if (is_array($tier) === false) {
					continue;
				}

				$role = (string)($tier['role'] ?? '');
				if ($role === '') {
					continue;
				}

				$steps[] = [
					'order' => $order,
					'role' => $role,
				];
				$order++;
			}

			if ($steps === []) {
				return;
			}

			$existing = $this->chainMapper->findBySchemaAndName(schemaId: $schemaId, name: $chainKey);

			$payload = [
				'name' => $chainKey,
				'schemaId' => $schemaId,
				'steps' => json_encode($steps),
				'enabled' => true,
			];

			if ($existing === null) {
				$this->chainMapper->createFromArray($payload);
				$this->logger->info(
					sprintf('[ApprovalChainAnnotationInstaller] provisioned chain "%s" for schema %d', $chainKey, $schemaId)
				);
				return;
			}

			$this->chainMapper->updateFromArray($existing->getId(), $payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[ApprovalChainAnnotationInstaller] upsert "%s" (schema %d) failed: %s',
					$chainKey,
					$schemaId,
					$e->getMessage()
				)
			);
		}//end try
	}//end upsertChain()
}//end class
