<?php

/**
 * OpenRegister Repair: seed the case-plan fixtures (flow-cmmn-case-semantics, ADR-001).
 *
 * Installs the six seed groups from the change's design.md, Seed Data: the
 * two-stage municipal permit case (nesting, a milestone that satisfies a
 * sentry, required-child completion); the discretionary advice item under
 * `assessment` (enableable query, non-member denial); the ad-hoc item on a
 * run-less task (the proof that the ad-hoc path needs no flow definition);
 * the terminated stage with its cascade audit rows; the repeating item with
 * two realisations, one completed and one active (the live counter-example
 * to "terminal iff all realisations are"); and the zaaktype fixture the
 * mapper tests read from {@see zaaktypeFixture()}.
 *
 * All uuids are nil placeholders and all uids obviously fake. Idempotent on
 * uuid: a fixture that exists is left exactly as it is.
 *
 * OFF BY DEFAULT. The step runs only when the app config `openregister` /
 * `seed_demo_cases` is true, which a demo instance and the test environment
 * set and nothing else does. The permit case anchors on the same demo object
 * as SeedTaskFixtures' first task, so the two demos agree on what they are
 * about when both flags are on.
 *
 * WRITES THROUGH THE MAPPERS, NOT THE SERVICE, deliberately: repair steps run
 * without a user session, and every case verb correctly refuses an actor-less
 * call. Fixtures are not user actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the case-plan fixture groups, idempotent on uuid.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The fixtures ARE the class;
 * six groups of literal rows, same as SeedTaskFixtures.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class SeedCaseFixtures implements IRepairStep {

	/**
	 * The app config key that switches the demo seed on.
	 */
	public const FLAG = 'seed_demo_cases';

	/**
	 * The demo permit object, shared with SeedTaskFixtures' first task.
	 */
	public const PERMIT_OBJECT = '00000000-0000-0000-0000-0000000000aa';

	/**
	 * The demo object carrying the terminated stage.
	 */
	public const TERMINATED_OBJECT = '00000000-0000-0000-0000-0000000000ab';

	/**
	 * The demo object carrying the repeating item.
	 */
	public const REPEATING_OBJECT = '00000000-0000-0000-0000-0000000000ac';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the opt-in flag.
	 * @param CaseItemMapper $items The plan-item table.
	 * @param CaseItemAuditMapper $audits The append-only audit.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly CaseItemMapper $items,
		private readonly CaseItemAuditMapper $audits,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name in the repair log.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function getName(): string {
		return 'Seed the case-plan fixtures (flow-cmmn-case-semantics)';
	}//end getName()

	/**
	 * Install every fixture row that does not exist yet.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool('openregister', self::FLAG, false) === false) {
			$output->info('Case-plan fixtures: skipped (openregister/' . self::FLAG . ' is not enabled).');
			return;
		}

		$seeded = 0;
		$present = 0;
		foreach ($this->fixtures() as $fixture) {
			try {
				$result = $this->seedTree(fixture: $fixture, parentId: null);
				$seeded += $result['seeded'];
				$present += $result['present'];
			} catch (Throwable $failure) {
				// A fixture must never fail an upgrade, and silence would be the
				// repair-step defect this fleet already paid for once.
				$output->warning(sprintf('Case-plan fixture %s failed: %s', (string)($fixture['uuid'] ?? '?'), $failure->getMessage()));
				$this->logger->warning('[SeedCaseFixtures] Fixture failed: ' . $failure->getMessage(), ['uuid' => ($fixture['uuid'] ?? null)]);
			}
		}

		$output->info(sprintf('Case-plan fixtures: %d rows seeded, %d already present.', $seeded, $present));
	}//end run()

	/**
	 * The zaaktype fixture for the mapper: four statustypen with sequence
	 * numbers deliberately out of document order, three roltypen, two
	 * resultaattypen, a doorlooptijd, a servicenorm, and two elements the
	 * mapping does not cover.
	 *
	 * @return array<string, mixed> The zaaktype document.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public static function zaaktypeFixture(): array {
		return [
			'url' => 'https://ztc.demo.invalid/api/v1/zaaktypen/00000000-0000-0000-0000-0000000000d1',
			'identificatie' => 'DEMO-OMGEVINGSVERGUNNING',
			'omschrijving' => 'Omgevingsvergunning (demo)',
			'doorlooptijd' => 'P8W',
			'servicenorm' => 'P6W',
			'statustypen' => [
				['volgnummer' => 3, 'omschrijving' => 'In behandeling'],
				['volgnummer' => 1, 'omschrijving' => 'Ontvangen'],
				['volgnummer' => 4, 'omschrijving' => 'Afgehandeld'],
				['volgnummer' => 2, 'omschrijving' => 'Volledig'],
			],
			'roltypen' => [
				['omschrijving' => 'Aanvrager', 'omschrijvingGeneriek' => 'initiator'],
				['omschrijving' => 'Vergunningverlener', 'omschrijvingGeneriek' => 'behandelaar'],
				['omschrijving' => 'Welstandscommissie', 'omschrijvingGeneriek' => 'adviseur'],
			],
			'resultaattypen' => [
				['omschrijving' => 'Verleend', 'archiefnominatie' => 'blijvend_bewaren'],
				['omschrijving' => 'Geweigerd', 'archiefnominatie' => 'vernietigen', 'archiefactietermijn' => 'P10Y'],
			],
			// Two elements the mapping does not cover.
			'publicatieIndicatie' => true,
			'verlengingMogelijk' => true,
		];
	}//end zaaktypeFixture()

	/**
	 * Seed one fixture node and its children; returns counts.
	 *
	 * @param array<string, mixed> $fixture The node: `uuid`, `item`, optional `audit`, `children`.
	 * @param int|null $parentId The parent's row id.
	 *
	 * @return array{seeded: int, present: int} Counts.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function seedTree(array $fixture, ?int $parentId): array {
		$counts = ['seeded' => 0, 'present' => 0];
		try {
			$row = $this->items->findByUuid(uuid: (string)$fixture['uuid']);
			$counts['present']++;
		} catch (DoesNotExistException) {
			$row = new CaseItem();
			$row->hydrate($fixture['item']);
			$row->setUuid((string)$fixture['uuid']);
			$row->setParentItemId($parentId);
			$row = $this->items->insert($row);
			foreach (($fixture['audit'] ?? []) as $auditFixture) {
				$entry = new CaseItemAudit();
				$entry->setCaseItemId((int)$row->getId());
				$entry->setFromState($auditFixture['from'] ?? null);
				$entry->setToState($auditFixture['to'] ?? null);
				$entry->setCause((string)$auditFixture['cause']);
				$entry->setCauseRef($auditFixture['causeRef'] ?? null);
				$entry->setActor($auditFixture['actor'] ?? null);
				$entry->setReason($auditFixture['reason'] ?? null);
				$entry->setAuthorized((bool)($auditFixture['authorized'] ?? true));
				$this->audits->insert($entry);
			}

			$counts['seeded']++;
		}//end try

		foreach (($fixture['children'] ?? []) as $child) {
			$childCounts = $this->seedTree(fixture: $child, parentId: (int)$row->getId());
			$counts['seeded'] += $childCounts['seeded'];
			$counts['present'] += $childCounts['present'];
		}

		return $counts;
	}//end seedTree()

	/**
	 * The fixture trees.
	 *
	 * @return array<int, array<string, mixed>> The root fixtures.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function fixtures(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		$permitSettings = [
			'authorization' => ['demo-behandelaars'],
			'results' => ['verleend', 'geweigerd'],
			'writeThrough' => ['statusField' => 'status', 'statusAtField' => 'statusReachedAt', 'resultField' => 'resultaat', 'resultAtField' => 'resultaatReachedAt'],
		];
		$permit = static fn (array $fields): array => array_merge(
			[
				'objectUuid' => self::PERMIT_OBJECT,
				'registerId' => 1,
				'schemaId' => 1,
				'origin' => CaseItem::ORIGIN_DEFINED,
				'required' => true,
				'discretionary' => false,
				'realisationCount' => 1,
				'planSettings' => $permitSettings,
				'createdBy' => 'demo-seed',
			],
			$fields
		);
		$created = static fn (string $to): array => ['from' => '', 'to' => $to, 'cause' => CaseItemAudit::CAUSE_IMPORT, 'actor' => 'demo-seed'];

		return [
			// 1 + 2 + 3: the two-stage permit case with its discretionary and ad-hoc items.
			[
				'uuid' => '00000000-0000-0000-0000-0000000000c1',
				'item' => $permit(['itemKey' => 'intake', 'name' => 'Intake', 'planItemType' => CaseItem::TYPE_STAGE, 'position' => 0, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'definitionItemKey' => 'intake', 'realisationKind' => CaseItem::REALISATION_NONE]),
				'audit' => [$created(CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_ACTIVE, 'cause' => CaseItemAudit::CAUSE_SENTRY, 'causeRef' => 'entry:default', 'actor' => 'case-plan']],
				'children' => [
					[
						'uuid' => '00000000-0000-0000-0000-0000000000c2',
						'item' => $permit(['itemKey' => 'completeness-check', 'name' => 'Controleer volledigheid', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'position' => 0, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'definitionItemKey' => 'completeness-check', 'candidateGroups' => ['demo-behandelaars'], 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-000000000001']),
						'audit' => [$created(CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_ACTIVE, 'cause' => CaseItemAudit::CAUSE_SENTRY, 'causeRef' => 'entry:default', 'actor' => 'case-plan']],
					],
					[
						'uuid' => '00000000-0000-0000-0000-0000000000c3',
						'item' => $permit(['itemKey' => 'application-complete', 'name' => 'Aanvraag volledig', 'planItemType' => CaseItem::TYPE_MILESTONE, 'position' => 1, 'state' => CaseItem::STATE_AVAILABLE, 'isTerminal' => false, 'definitionItemKey' => 'application-complete', 'entryCriteria' => [['id' => 'complete:entry', 'on' => ['event' => 'case.item.completed', 'item' => 'completeness-check']]]]),
						'audit' => [$created(CaseItem::STATE_AVAILABLE)],
					],
					[
						// 3: ad-hoc, in no definition, realised by a task with run_uuid null.
						'uuid' => '00000000-0000-0000-0000-0000000000c6',
						'item' => $permit(['itemKey' => 'adhoc-site-visit', 'name' => 'Locatiebezoek (ad hoc)', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'position' => 2, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'origin' => CaseItem::ORIGIN_ADHOC, 'required' => false, 'candidateUsers' => ['demo-behandelaar-1'], 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-0000000000c7']),
						'audit' => [['from' => '', 'to' => CaseItem::STATE_AVAILABLE, 'cause' => CaseItemAudit::CAUSE_USER, 'causeRef' => 'attach', 'actor' => 'demo-behandelaar-1'], ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_ACTIVE, 'cause' => CaseItemAudit::CAUSE_SENTRY, 'causeRef' => 'entry:default', 'actor' => 'case-plan']],
					],
				],
			],
			[
				'uuid' => '00000000-0000-0000-0000-0000000000c4',
				'item' => $permit(['itemKey' => 'assessment', 'name' => 'Beoordeling', 'planItemType' => CaseItem::TYPE_STAGE, 'position' => 1, 'state' => CaseItem::STATE_AVAILABLE, 'isTerminal' => false, 'definitionItemKey' => 'assessment', 'entryCriteria' => [['id' => 'assessment:entry', 'on' => ['event' => 'case.item.completed', 'item' => 'application-complete']]]]),
				'audit' => [$created(CaseItem::STATE_AVAILABLE)],
				'children' => [
					[
						// 2: the discretionary advice item.
						'uuid' => '00000000-0000-0000-0000-0000000000c5',
						'item' => $permit(['itemKey' => 'external-advice', 'name' => 'Extern advies', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'position' => 0, 'state' => CaseItem::STATE_AVAILABLE, 'isTerminal' => false, 'definitionItemKey' => 'external-advice', 'origin' => CaseItem::ORIGIN_DISCRETIONARY, 'discretionary' => true, 'required' => false, 'authorizationRules' => ['demo-beslissers'], 'candidateRole' => 'demo-adviseurs']),
						'audit' => [$created(CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_ENABLED, 'cause' => CaseItemAudit::CAUSE_USER, 'actor' => 'demo-stranger', 'reason' => "Verb 'enable' denied: the caller holds none of the item's authorizations.", 'authorized' => false]],
					],
				],
			],
			// 4: a terminated stage with its cascade.
			[
				'uuid' => '00000000-0000-0000-0000-0000000000c8',
				'item' => ['objectUuid' => self::TERMINATED_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'hearing', 'name' => 'Hoorzitting', 'planItemType' => CaseItem::TYPE_STAGE, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'hearing', 'position' => 0, 'state' => CaseItem::STATE_TERMINATED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'realisationCount' => 1, 'terminatedReason' => 'Bezwaar ingetrokken.', 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
				'audit' => [$created(CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_ACTIVE, 'cause' => CaseItemAudit::CAUSE_SENTRY, 'causeRef' => 'entry:default', 'actor' => 'case-plan'], ['from' => CaseItem::STATE_ACTIVE, 'to' => CaseItem::STATE_TERMINATED, 'cause' => CaseItemAudit::CAUSE_USER, 'actor' => 'demo-behandelaar-1', 'reason' => 'Bezwaar ingetrokken.']],
				'children' => [
					[
						'uuid' => '00000000-0000-0000-0000-0000000000c9',
						'item' => ['objectUuid' => self::TERMINATED_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'invite-parties', 'name' => 'Partijen uitnodigen', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'invite-parties', 'position' => 0, 'state' => CaseItem::STATE_TERMINATED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'realisationCount' => 1, 'terminatedReason' => "Stage 'hearing' exited to 'terminated'.", 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
						'audit' => [$created(CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_ACTIVE, 'to' => CaseItem::STATE_TERMINATED, 'cause' => CaseItemAudit::CAUSE_CASCADE, 'causeRef' => '00000000-0000-0000-0000-0000000000c8', 'actor' => 'case-plan', 'reason' => "Stage 'hearing' exited to 'terminated'."]],
					],
					[
						'uuid' => '00000000-0000-0000-0000-0000000000ca',
						'item' => ['objectUuid' => self::TERMINATED_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'hearing-report', 'name' => 'Verslag hoorzitting', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'hearing-report', 'position' => 1, 'state' => CaseItem::STATE_DISABLED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'realisationCount' => 1, 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
						'audit' => [$created(CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_DISABLED, 'cause' => CaseItemAudit::CAUSE_CASCADE, 'causeRef' => '00000000-0000-0000-0000-0000000000c8', 'actor' => 'case-plan', 'reason' => "Stage 'hearing' exited to 'terminated'."]],
					],
				],
			],
			// 5: a repeating item, two realisations: one completed, one active.
			[
				'uuid' => '00000000-0000-0000-0000-0000000000cb',
				'item' => ['objectUuid' => self::REPEATING_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'request-documents', 'name' => 'Stukken opvragen', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'request-documents', 'position' => 0, 'state' => CaseItem::STATE_COMPLETED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'repetition' => ['max' => 3], 'realisationCount' => 1, 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-0000000000cd', 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
				'audit' => [$created(CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_ACTIVE, 'to' => CaseItem::STATE_COMPLETED, 'cause' => CaseItemAudit::CAUSE_REALISATION, 'causeRef' => '00000000-0000-0000-0000-0000000000cd', 'actor' => 'case-plan']],
			],
			[
				'uuid' => '00000000-0000-0000-0000-0000000000cc',
				'item' => ['objectUuid' => self::REPEATING_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'request-documents', 'name' => 'Stukken opvragen', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'request-documents', 'position' => 0, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'required' => true, 'discretionary' => false, 'repetition' => ['max' => 3], 'realisationCount' => 2, 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-0000000000ce', 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
				'audit' => [['from' => '', 'to' => CaseItem::STATE_AVAILABLE, 'cause' => CaseItemAudit::CAUSE_REALISATION, 'causeRef' => '00000000-0000-0000-0000-0000000000cb', 'actor' => 'case-plan'], ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_ACTIVE, 'cause' => CaseItemAudit::CAUSE_SENTRY, 'causeRef' => 'entry:default', 'actor' => 'case-plan']],
			],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end fixtures()
}//end class
