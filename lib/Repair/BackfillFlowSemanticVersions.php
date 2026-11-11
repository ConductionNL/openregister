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

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCA\OpenRegister\Repair\Support\EveryFlow;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersionInterface;
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
			$flows = (new EveryFlow(flows: $this->container->get(FlowMapperInterface::class)))->read();
			$numbering = new FlowVersionNumbering(
				versions: $this->container->get(FlowVersionMapperInterface::class),
				semver: $this->container->get(FlowSemanticVersionInterface::class)
			);
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
				$stamped += $this->stampOneFlow(flow: $flow, numbering: $numbering);
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
	 * Number one flow's published versions in ordinal order.
	 *
	 * Only rows that HAVE no semantic version are touched: a derived version
	 * is a stronger fact than anything this step can produce, and overwriting
	 * one would replace evidence with a guess.
	 *
	 * @param Flow                 $flow      The flow.
	 * @param FlowVersionNumbering $numbering The version rows and their numbering.
	 *
	 * @return int How many rows were stamped.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
	 */
	private function stampOneFlow(Flow $flow, FlowVersionNumbering $numbering): int {
		$rows = $numbering->versions->findAllForFlow(flowUuid: (string)$flow->getUuid());

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

			$row->setSemver($numbering->semver->backfilled(ordinalPosition: $position));
			$row->setSemverSource(FlowSemanticVersionInterface::SOURCE_BACKFILL);
			$numbering->versions->update($row);
			$stamped++;
		}

		$this->mirrorOntoFlow(flow: $flow, published: $published);

		return $stamped;
	}//end stampOneFlow()

	/**
	 * Copy the live version's number onto the flow row, marked as back-filled.
	 *
	 * 🔴 THE VERSION ROWS ALONE ARE NOT ENOUGH. `Flow` mirrors `semver` so a
	 * list of flows can show it without a join, and a repair that stamped only
	 * the version rows would leave every historic flow showing its ORDINAL in
	 * the pill for ever — the rows would be right and the screen would be
	 * wrong, which is the worst of both.
	 *
	 * Only a flow with no semantic version is touched: a derived one is a
	 * stronger fact than anything this step can produce.
	 *
	 * @param Flow                     $flow      The flow to mirror onto.
	 * @param array<int, FlowVersion>  $published Its published versions, oldest first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
	 */
	private function mirrorOntoFlow(Flow $flow, array $published): void {
		if (trim((string)$flow->getSemver()) !== '') {
			return;
		}

		$live = $this->liveVersionOf(published: $published);
		if ($live === null || trim((string)$live->getSemver()) === '') {
			return;
		}

		$flow->setSemver($live->getSemver());
		$flow->setSemverSource(FlowSemanticVersionInterface::SOURCE_BACKFILL);
		$this->container->get(FlowMapperInterface::class)->update($flow);

	}//end mirrorOntoFlow()

	/**
	 * The last version in the list that is still published, i.e. the live one.
	 *
	 * @param array<int, FlowVersion> $published The flow's versions, oldest first.
	 *
	 * @return FlowVersion|null The live version, or null when none is published.
	 */
	private function liveVersionOf(array $published): ?FlowVersion {
		$live = null;
		foreach ($published as $row) {
			if ($row->getStatus() === FlowVersion::STATUS_PUBLISHED) {
				$live = $row;
			}
		}

		return $live;
	}//end liveVersionOf()
}//end class
