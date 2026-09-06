<?php

/**
 * OpenRegister ApprovalChainAnnotationInstaller
 *
 * The compiler for `x-openregister-approval-chains`. The annotation's
 * declared shape is unchanged and stays registered in the schema annotation
 * vocabulary; what changed is what a declaration provisions
 * (flow-approval-consolidation design D-2). Where this class used to upsert
 * an `ApprovalChain` row, it now compiles the declaration into a task
 * TEMPLATE: a deterministic template id, one ordered position per
 * `approvers` entry, and the chain-level policy (`transition`,
 * `separationOfDuties`, `onApprove`, `amountField`). The template is a pure
 * function of the schema, so provisioning is idempotent by construction: a
 * re-save produces the same template, no second one and no new version.
 *
 * The class keeps its name and its schema-save subscription on purpose: the
 * refusal path and its callers stay recognisable, which is what makes the
 * behaviour-preservation argument reviewable (design D-2). At save time it
 * only validates and reports; the gate compiles on demand.
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
 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Compiles `x-openregister-approval-chains` declarations into task templates.
 *
 * @template-implements IEventListener<Event>
 */
class ApprovalChainAnnotationInstaller implements IEventListener {

	/**
	 * The compiled template's version. The template is a pure function of
	 * the declaration, so there is exactly one version; the load-bearing
	 * freeze is the SNAPSHOT written onto each sequence at provisioning.
	 *
	 * @var integer
	 */
	public const TEMPLATE_VERSION = 1;

	/**
	 * Namespace prefix for the deterministic template id.
	 *
	 * @var string
	 */
	private const TEMPLATE_ID_NS = 'or-approval-template';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for compile diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Listener entry point: validates declared chains at schema-save time.
	 *
	 * Nothing is written. The template is derived, so there is no row to
	 * upsert; what a save CAN surface early is a declaration that will fail
	 * the gate closed (`approval-chain-misconfigured`), and that is reported
	 * here where the schema author still has the save in front of them.
	 *
	 * @param Event $event The event carrying the saved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-006
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

		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false) {
			return;
		}

		foreach (array_keys($chains) as $chainKey) {
			if (is_string($chainKey) === false) {
				continue;
			}

			if ($this->compile(schema: $schema, chainKey: $chainKey) === null) {
				$this->logger->warning(
					sprintf(
						'[ApprovalChainAnnotationInstaller] chain "%s" on schema %s declares no usable approver; its gate will refuse the transition as misconfigured.',
						$chainKey,
						(string)$schema->getId()
					)
				);
			}
		}
	}//end handle()

	/**
	 * Compile ONE declared chain into a task template.
	 *
	 * Null when the chain cannot be compiled: undeclared, not an array, or
	 * without a single usable `{role}` entry. The gate treats null as
	 * fail-closed misconfiguration.
	 *
	 * @param Schema $schema The schema carrying the declaration.
	 * @param string $chainKey The declarative chain key.
	 *
	 * @return array<string, mixed>|null The compiled template, or null.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-006
	 */
	public function compile(Schema $schema, string $chainKey): ?array {
		$schemaId = $schema->getId();
		if ($schemaId === null) {
			return null;
		}

		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false) {
			return null;
		}

		$spec = ($chains[$chainKey] ?? null);
		if (is_array($spec) === false) {
			return null;
		}

		$positions = $this->compilePositions(approvers: (array)($spec['approvers'] ?? []));
		if ($positions === []) {
			return null;
		}

		return [
			'templateId' => $this->templateIdFor(schemaId: (int)$schemaId, chainKey: $chainKey),
			'templateVersion' => self::TEMPLATE_VERSION,
			'name' => $chainKey,
			'schemaId' => (int)$schemaId,
			'transition' => (string)($spec['transition'] ?? ''),
			'separationOfDuties' => (($spec['separationOfDuties'] ?? true) !== false),
			'onApprove' => (string)($spec['onApprove'] ?? ''),
			'amountField' => (string)($spec['amountField'] ?? ''),
			'positions' => $positions,
		];
	}//end compile()

	/**
	 * One ordered position per usable `approvers` entry.
	 *
	 * @param array<int, mixed> $approvers The declared approver tiers.
	 *
	 * @return array<int, array<string, mixed>> The ordered positions.
	 */
	private function compilePositions(array $approvers): array {
		$positions = [];
		$order = 1;
		foreach ($approvers as $tier) {
			if (is_array($tier) === false) {
				continue;
			}

			$role = trim((string)($tier['role'] ?? ''));
			if ($role === '') {
				continue;
			}

			$position = [
				'order' => $order,
				'role' => $role,
			];
			foreach (['min', 'minAmount', 'statusOnApprove', 'statusOnReject'] as $carry) {
				if (array_key_exists($carry, $tier) === true) {
					$position[$carry] = $tier[$carry];
				}
			}

			$positions[] = $position;
			$order++;
		}//end foreach

		return $positions;
	}//end compilePositions()

	/**
	 * The deterministic template id for a (schema, chain key) pair.
	 *
	 * Uuid-shaped so it fits the 36-character `template_id` columns, and a
	 * pure function of its inputs so every compile, every gate evaluation
	 * and every migration run derives the SAME id: "exactly one template per
	 * chain" holds by construction rather than by a guarded insert.
	 *
	 * @param int $schemaId The owning schema id.
	 * @param string $chainKey The declarative chain key.
	 *
	 * @return string The template id, RFC-4122 shaped.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function templateIdFor(int $schemaId, string $chainKey): string {
		$hash = md5(self::TEMPLATE_ID_NS . ':' . $schemaId . ':' . $chainKey);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 12, 4),
			substr($hash, 16, 4),
			substr($hash, 20, 12)
		);
	}//end templateIdFor()
}//end class
