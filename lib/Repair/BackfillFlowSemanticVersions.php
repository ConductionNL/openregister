<?php

/**
 * Give every already-published flow version a semantic version, honestly.
 *
 * 🔴 IT DOES NOT INVENT HISTORY. The repair cannot know whether the third
 * publish of a flow was breaking: the graphs it would have to compare are
 * precisely the ones it is being run to describe, and older definition rows
 * may have been pruned. So it does not pretend to. It numbers a flow's
 * published versions in ordinal order — 1.0.0, 1.1.0, 1.2.0 — and stamps
 * `semverSource = backfill` beside each.
 *
 * That marker is the whole point. A version that says where it came from can
 * be distrusted correctly; one that silently claims to have been derived
 * cannot. The UI reads it and says so on hover.
 *
 * 🔴 IT MUST NOT FAIL AN UPGRADE. A flow whose history is incomplete is
 * already in that state, and a repair that turns a reporting gap into a failed
 * `occ upgrade` makes things worse for everybody on the instance. Every
 * failure is caught, counted and reported.
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
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps historic published versions with a back-filled semantic version.
 */
class BackfillFlowSemanticVersions implements IRepairStep {

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
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
	 */
	public function getName(): string {
		return 'Number existing flow versions semantically';
	}//end getName()

	/**
	 * Stamp every published version that has none.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
	 */
	public function run(IOutput $output): void {
		try {
			$flows = $this->everyFlow();
			$versions = $this->container->get(FlowVersionMapper::class);
			$semver = $this->container->get(FlowSemanticVersion::class);
		} catch (Throwable $e) {
			// The tables may not exist yet on a fresh install. Nothing to
			// back-fill is not a failure.
			$output->info('Flow semantic-version backfill skipped: ' . $e->getMessage());
			return;
		}

		$stamped = 0;
		$refused = 0;

		foreach ($flows as $flow) {
			try {
				$stamped += $this->stampOneFlow(
					flowUuid: (string)$flow->getUuid(),
					versions: $versions,
					semver: $semver
				);
			} catch (Throwable $e) {
				// One flow's history being unreadable must not stop the rest,
				// and must not fail the upgrade.
				$refused++;
				$this->logger->warning(
					message: '[BackfillFlowSemanticVersions] Could not stamp flow "'
						. (string)$flow->getUuid() . '": ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		$output->info(
			sprintf(
				'Numbered %d published flow version(s) as back-filled; %d flow(s) could not be read.',
				$stamped,
				$refused
			)
		);

	}//end run()

	/**
	 * Every flow, paged.
	 *
	 * 🔴 `findAllFlows()`, NOT `findAll()`, and it PAGES. The mapper's default
	 * limit is 100, so a single call would silently stamp the first hundred
	 * flows and leave the rest — a repair that reports success having done
	 * most of the job is worse than one that fails.
	 *
	 * I wrote `findAll()` first. It does not exist, the call threw, the step
	 * reported itself skipped, and the upgrade went green having stamped
	 * nothing. Only counting the rows afterwards found it.
	 *
	 * @return array<int, \OCA\OpenRegister\Db\Flow> Every flow.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
	 */
	private function everyFlow(): array {
		$mapper = $this->container->get(FlowMapper::class);
		$page = 500;
		$offset = 0;
		$all = [];

		while (true) {
			$batch = $mapper->findAllFlows(limit: $page, offset: $offset);
			$all = array_merge($all, $batch);

			if (count($batch) < $page) {
				return $all;
			}

			$offset += $page;
		}

	}//end everyFlow()

	/**
	 * Number one flow's published versions in ordinal order.
	 *
	 * Only rows that HAVE no semantic version are touched: a derived version
	 * is a stronger fact than anything this step can produce, and overwriting
	 * one would replace evidence with a guess.
	 *
	 * @param string             $flowUuid The flow.
	 * @param FlowVersionMapper  $versions The version rows.
	 * @param FlowSemanticVersion $semver  The numbering.
	 *
	 * @return int How many rows were stamped.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
	 */
	private function stampOneFlow(string $flowUuid, FlowVersionMapper $versions, FlowSemanticVersion $semver): int {
		$rows = $versions->findAllForFlow(flowUuid: $flowUuid);

		// Oldest first: the sequence is the flow's own history, and
		// `findAllForFlow` answers newest first for the UI.
		$published = array_values(
			array_filter(
				$rows,
				static fn (FlowVersion $row): bool => $row->getStatus() !== FlowVersion::STATUS_DRAFT
			)
		);

		usort(
			$published,
			static fn (FlowVersion $a, FlowVersion $b): int => ((int)$a->getVersion() <=> (int)$b->getVersion())
		);

		$stamped = 0;
		foreach ($published as $position => $row) {
			if (trim((string)$row->getSemver()) !== '') {
				continue;
			}

			$row->setSemver($semver->backfilled(ordinalPosition: $position));
			$row->setSemverSource(FlowSemanticVersion::SOURCE_BACKFILL);
			$versions->update($row);
			$stamped++;
		}

		return $stamped;
	}//end stampOneFlow()
}//end class
