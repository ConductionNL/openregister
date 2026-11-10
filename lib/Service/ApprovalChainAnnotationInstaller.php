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
 * `separationOfDuties`, `onApprove`, `amountField`, `approvingOutcomes`). The
 * template is a pure function of the schema, so provisioning is idempotent
 * by construction: a re-save produces the same template, no second one and
 * no new version.
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
 * @spec openspec/specs/approval-workflow/spec.md#req-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Task\ApprovingOutcomeSet;
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
	 * Tiers mode: only the tier with the highest minAmount at or below the amount.
	 *
	 * @var string
	 */
	public const TIERS_HIGHEST = 'highest';

	/**
	 * Tiers mode: every tier at or below the amount, lowest first.
	 *
	 * @var string
	 */
	public const TIERS_CUMULATIVE = 'cumulative';

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
	 * @spec openspec/specs/approval-workflow/spec.md#req-006
	 */
	public function handle(Event $event): void {
		$schema = $this->savedSchema(event: $event);
		if ($schema === null) {
			return;
		}

		$chains = $this->declaredChains(schema: $schema);
		if ($chains === null) {
			return;
		}

		foreach (array_keys($chains) as $chainKey) {
			if (is_string($chainKey) === true && $this->compile(schema: $schema, chainKey: $chainKey) === null) {
				$this->warnUnusableChain(schema: $schema, chainKey: $chainKey);
			}
		}
	}//end handle()


	/**
	 * The schema a create or update event saved.
	 *
	 * @param Event $event The event carrying the saved schema.
	 *
	 * @return Schema|null The schema, or null when the event carries none.
	 */
	private function savedSchema(Event $event): ?Schema {
		$schema = null;
		if (method_exists($event, 'getSchema') === true) {
			$schema = $event->getSchema();
		} elseif (method_exists($event, 'getNewSchema') === true) {
			$schema = $event->getNewSchema();
		}

		if (($schema instanceof Schema) === false) {
			return null;
		}

		return $schema;
	}//end savedSchema()

	/**
	 * The chains a schema declares under `x-openregister-approval-chains`.
	 *
	 * @param Schema $schema The schema carrying the declaration.
	 *
	 * @return array<mixed>|null The declared chains by key, or null when none are declared.
	 */
	private function declaredChains(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false) {
			return null;
		}

		return $chains;
	}//end declaredChains()

	/**
	 * Warn that a declared chain will fail its gate closed.
	 *
	 * @param Schema $schema   The schema carrying the declaration.
	 * @param string $chainKey The declarative chain key.
	 *
	 * @return void
	 */
	private function warnUnusableChain(Schema $schema, string $chainKey): void {
		$this->logger->warning(
			sprintf(
				'[ApprovalChainAnnotationInstaller] chain "%s" on schema %s declares no usable approver; its gate will refuse the transition as misconfigured.',
				$chainKey,
				(string)$schema->getId()
			)
		);
	}//end warnUnusableChain()

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
	 * @spec openspec/specs/approval-workflow/spec.md#req-006
	 */
	public function compile(Schema $schema, string $chainKey): ?array {
		$spec = $this->declaredChain(schema: $schema, chainKey: $chainKey);
		if ($spec === null) {
			return null;
		}

		$body = $this->templateBody(spec: $spec, chainKey: $chainKey);
		if ($body === null) {
			return null;
		}

		$template = $this->templateHead(schemaId: (int)$schema->getId(), chainKey: $chainKey) + $body;

		return $this->withApprovingOutcomes(template: $template, spec: $spec);
	}//end compile()


	/**
	 * One declared chain of a saved schema.
	 *
	 * @param Schema $schema   The schema carrying the declaration.
	 * @param string $chainKey The declarative chain key.
	 *
	 * @return array<string, mixed>|null The chain, or null when the schema has no id or the chain is undeclared or not an array.
	 */
	private function declaredChain(Schema $schema, string $chainKey): ?array {
		if ($schema->getId() === null) {
			return null;
		}

		$spec = ($this->declaredChains(schema: $schema)[$chainKey] ?? null);
		if (is_array($spec) === false) {
			return null;
		}

		return $spec;
	}//end declaredChain()

	/**
	 * The rules and positions of a chain's template.
	 *
	 * @param array<string, mixed> $spec     The declared chain.
	 * @param string               $chainKey The declarative chain key.
	 *
	 * @return array<string, mixed>|null The rules and positions, or null when the chain has no usable approver or an unknown tiers mode.
	 */
	private function templateBody(array $spec, string $chainKey): ?array {
		$positions = $this->compilePositions(approvers: (array)($spec['approvers'] ?? []));
		if ($positions === []) {
			return null;
		}

		$tiers = $this->tiersMode(spec: $spec, chainKey: $chainKey);
		if ($tiers === null) {
			return null;
		}

		return $this->templateRules(spec: $spec, tiers: $tiers) + ['positions' => $positions];
	}//end templateBody()

	/**
	 * How a chain's amount tiers combine.
	 *
	 * `highest` (the one tier with the highest minAmount at or below the
	 * amount) or `cumulative` (every tier at or below it). An unknown mode is
	 * a misconfiguration: fail closed.
	 *
	 * @param array<string, mixed> $spec     The declared chain.
	 * @param string               $chainKey The declarative chain key.
	 *
	 * @return string|null The mode, or null when it is unknown (logged).
	 */
	private function tiersMode(array $spec, string $chainKey): ?string {
		$tiers = (string)($spec['tiers'] ?? self::TIERS_HIGHEST);
		if (in_array($tiers, [self::TIERS_HIGHEST, self::TIERS_CUMULATIVE], true) === false) {
			$this->logger->error(
				message: '[ApprovalChainAnnotationInstaller] Unknown tiers mode; the chain is not compiled.',
				context: ['chain' => $chainKey, 'tiers' => $tiers]
			);
			return null;
		}

		return $tiers;
	}//end tiersMode()

	/**
	 * The identity of a chain's template.
	 *
	 * @param int    $schemaId The owning schema id.
	 * @param string $chainKey The declarative chain key.
	 *
	 * @return array<string, mixed> The template id, version, name and schema id.
	 */
	private function templateHead(int $schemaId, string $chainKey): array {
		return [
			'templateId' => $this->templateIdFor(schemaId: $schemaId, chainKey: $chainKey),
			'templateVersion' => self::TEMPLATE_VERSION,
			'name' => $chainKey,
			'schemaId' => $schemaId,
		];
	}//end templateHead()

	/**
	 * The transition rules a chain declares.
	 *
	 * @param array<string, mixed> $spec  The declared chain.
	 * @param string               $tiers The tiers mode.
	 *
	 * @return array<string, mixed> The rules.
	 */
	private function templateRules(array $spec, string $tiers): array {
		return [
			'transition' => (string)($spec['transition'] ?? ''),
			'separationOfDuties' => (($spec['separationOfDuties'] ?? true) !== false),
			'onApprove' => (string)($spec['onApprove'] ?? ''),
			'amountField' => (string)($spec['amountField'] ?? ''),
			'tiers' => $tiers,
		];
	}//end templateRules()

	/**
	 * Add the declared approving outcomes, when there are any.
	 *
	 * @param array<string, mixed> $template The compiled template.
	 * @param array<string, mixed> $spec     The declared chain.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function withApprovingOutcomes(array $template, array $spec): array {
		$approving = (new ApprovingOutcomeSet(declared: ($spec['approvingOutcomes'] ?? null)))->entries();
		if ($approving !== []) {
			$template['approvingOutcomes'] = $approving;
		}

		return $template;
	}//end withApprovingOutcomes()

	/**
	 * One ordered position per usable `approvers` entry.
	 *
	 * @param array<int, mixed> $approvers The declared approver tiers.
	 *
	 * @return array<int, array<string, mixed>> The ordered positions.
	 */
	private function compilePositions(array $approvers): array {
		$positions = [];
		foreach ($approvers as $tier) {
			$position = $this->position(tier: $tier, order: (count($positions) + 1));
			if ($position !== null) {
				$positions[] = $position;
			}
		}

		return $positions;
	}//end compilePositions()


	/**
	 * One position from a declared approver tier.
	 *
	 * @param mixed $tier  The declared tier.
	 * @param int   $order The position's place in the chain.
	 *
	 * @return array<string, mixed>|null The position, or null when the tier is not an array or names no role.
	 */
	private function position(mixed $tier, int $order): ?array {
		if (is_array($tier) === false) {
			return null;
		}

		$role = trim((string)($tier['role'] ?? ''));
		if ($role === '') {
			return null;
		}

		$position = [
			'order' => $order,
			'role' => $role,
		];

		return $this->withCarriedFields(position: $position, tier: $tier);
	}//end position()


	/**
	 * Carry the tier's threshold and status fields onto its position, when declared.
	 *
	 * @param array<string, mixed> $position The position.
	 * @param array<string, mixed> $tier     The declared tier.
	 *
	 * @return array<string, mixed> The position.
	 */
	private function withCarriedFields(array $position, array $tier): array {
		foreach (['min', 'minAmount', 'statusOnApprove', 'statusOnReject'] as $carry) {
			if (array_key_exists($carry, $tier) === true) {
				$position[$carry] = $tier[$carry];
			}
		}

		return $position;
	}//end withCarriedFields()

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
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
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
