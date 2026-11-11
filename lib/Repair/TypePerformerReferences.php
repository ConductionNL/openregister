<?php

/**
 * Give every stored performer string a type — but only where it is certain.
 *
 * A stored `"bezwaar"` means a user OR a group, because the guard it was
 * written against tried both. This step resolves each string once and rewrites
 * it as `{type, id}` where exactly one kind of thing answers to that name.
 *
 * 🔴 THREE OUTCOMES, AND ONLY ONE OF THEM MAY BE ACTED ON.
 *
 *   resolves under exactly one type → rewrite; the string meant that
 *   resolves under more than one    → LEAVE IT, and report
 *   resolves under no type          → LEAVE IT, and report
 *
 * The ambiguous case is not hypothetical. An instance may hold a user AND a
 * group of one name, and today's guard accepts BOTH — so today's behaviour is
 * genuinely the union, and any rewrite NARROWS it. Narrowing who may answer an
 * approval is a decision for a person, not for a repair step running unattended
 * during an upgrade.
 *
 * 🔴 IT MUST NOT FAIL AN UPGRADE. On the measured instance 27 stored strings
 * resolve to nothing at all. Those flows were broken before this step existed,
 * and a repair that turns pre-existing breakage into a failed `occ upgrade`
 * makes it everybody's problem instead of their owner's. Every unresolvable
 * string is reported by name and left exactly as it is.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Repair\Support\EveryFlow;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKindsInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites unambiguous performer strings as typed references.
 *
 * The walk itself, and what it collects, is one {@see PerformerTyping} per run.
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */
class TypePerformerReferences implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * Resolved lazily from the container, like the other flow repair steps:
	 * this runs during install and upgrade, when the flow tables may not exist
	 * yet, and a constructor-injected mapper would make that a fatal rather
	 * than a skip.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface    $logger    Diagnostics.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function getName(): string {
		return 'Give stored flow performers an explicit type';
	}//end getName()

	/**
	 * Rewrite what is certain; report what is not.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function run(IOutput $output): void {
		try {
			$flows = $this->container->get(FlowMapperInterface::class);
			$kinds = $this->container->get(PrincipalKindsInterface::class);
		} catch (Throwable $e) {
			// The tables may not exist yet on a fresh install. Nothing to
			// rewrite is not a failure.
			$output->info('Flow performer typing skipped: ' . $e->getMessage());
			return;
		}

		$typing = new PerformerTyping(flows: $flows, kinds: $kinds);

		foreach ((new EveryFlow(flows: $flows))->read() as $flow) {
			try {
				$typing->typeFlow(flow: $flow);
			} catch (Throwable $e) {
				// One unreadable flow must not stop the rest, and must not
				// fail the upgrade.
				$this->logger->warning(
					message: '[TypePerformerReferences] Could not type flow "'
						. (string)$flow->getUuid() . '": ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		$output->info(sprintf('Typed %d stored performer(s).', $typing->rewritten()));
		$typing->report(output: $output);

	}//end run()
}//end class
