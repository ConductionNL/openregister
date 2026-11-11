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
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Service\Case\CasePlanStore;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Seeds the case-plan fixture groups, idempotent on uuid.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The fixtures ARE the class;
 * six groups of literal rows, same as SeedTaskFixtures.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
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
	 * The fields every permit-case item shares; an item's own fields overlay them.
	 */
	private const PERMIT_DEFAULTS = [
		'objectUuid' => self::PERMIT_OBJECT,
		'registerId' => 1,
		'schemaId' => 1,
		'origin' => CaseItem::ORIGIN_DEFINED,
		'required' => true,
		'discretionary' => false,
		'realisationCount' => 1,
		'planSettings' => [
			'authorization' => ['demo-behandelaars'],
			'results' => ['verleend', 'geweigerd'],
			'writeThrough' => [
				'statusField' => 'status',
				'statusAtField' => 'statusReachedAt',
				'resultField' => 'resultaat',
				'resultAtField' => 'resultaatReachedAt',
			],
		],
		'createdBy' => 'demo-seed',
	];

	/**
	 * The zaaktype fixture's statustypen, sequence numbers deliberately out of document order.
	 */
	private const ZAAKTYPE_STATUSTYPEN = [
		['volgnummer' => 3, 'omschrijving' => 'In behandeling'],
		['volgnummer' => 1, 'omschrijving' => 'Ontvangen'],
		['volgnummer' => 4, 'omschrijving' => 'Afgehandeld'],
		['volgnummer' => 2, 'omschrijving' => 'Volledig'],
	];

	/**
	 * The zaaktype fixture's three roltypen.
	 */
	private const ZAAKTYPE_ROLTYPEN = [
		['omschrijving' => 'Aanvrager', 'omschrijvingGeneriek' => 'initiator'],
		['omschrijving' => 'Vergunningverlener', 'omschrijvingGeneriek' => 'behandelaar'],
		['omschrijving' => 'Welstandscommissie', 'omschrijvingGeneriek' => 'adviseur'],
	];

	/**
	 * The zaaktype fixture's two resultaattypen.
	 */
	private const ZAAKTYPE_RESULTAATTYPEN = [
		['omschrijving' => 'Verleend', 'archiefnominatie' => 'blijvend_bewaren'],
		['omschrijving' => 'Geweigerd', 'archiefnominatie' => 'vernietigen', 'archiefactietermijn' => 'P10Y'],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig Holds the opt-in flag.
	 * @param CasePlanStore $store     The plan-item table, the append-only audit and failure reporting.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly CasePlanStore $store,
	) {

	}//end __construct()

	/**
	 * The step's name in the repair log.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool('openregister', self::FLAG, false) === false) {
			$output->info('Case-plan fixtures: skipped (openregister/' . self::FLAG . ' is not enabled).');
			return;
		}

		$counts = ['seeded' => 0, 'present' => 0];
		foreach ($this->fixtures() as $fixture) {
			$counts = $this->addCounts(counts: $counts, more: $this->seedRoot(fixture: $fixture, output: $output));
		}

		$output->info(sprintf('Case-plan fixtures: %d rows seeded, %d already present.', $counts['seeded'], $counts['present']));
	}//end run()

	/**
	 * Seed one root fixture tree; a failure is reported and counts nothing.
	 *
	 * @param array<string, mixed> $fixture The root node.
	 * @param IOutput              $output  The repair output.
	 *
	 * @return array{seeded: int, present: int} Counts.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function seedRoot(array $fixture, IOutput $output): array {
		try {
			return $this->seedTree(fixture: $fixture, parentId: null);
		} catch (Throwable $failure) {
			// A fixture must never fail an upgrade, and silence would be the
			// repair-step defect this fleet already paid for once.
			$output->warning(sprintf('Case-plan fixture %s failed: %s', (string)($fixture['uuid'] ?? '?'), $failure->getMessage()));
			$this->store->logger->warning('[SeedCaseFixtures] Fixture failed: ' . $failure->getMessage(), ['uuid' => ($fixture['uuid'] ?? null)]);
			return ['seeded' => 0, 'present' => 0];
		}
	}//end seedRoot()

	/**
	 * The zaaktype fixture for the mapper: four statustypen with sequence
	 * numbers deliberately out of document order, three roltypen, two
	 * resultaattypen, a doorlooptijd, a servicenorm, and two elements the
	 * mapping does not cover.
	 *
	 * @return array<string, mixed> The zaaktype document.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public static function zaaktypeFixture(): array {
		return [
			'url' => 'https://ztc.demo.invalid/api/v1/zaaktypen/00000000-0000-0000-0000-0000000000d1',
			'identificatie' => 'DEMO-OMGEVINGSVERGUNNING',
			'omschrijving' => 'Omgevingsvergunning (demo)',
			'doorlooptijd' => 'P8W',
			'servicenorm' => 'P6W',
			'statustypen' => self::ZAAKTYPE_STATUSTYPEN,
			'roltypen' => self::ZAAKTYPE_ROLTYPEN,
			'resultaattypen' => self::ZAAKTYPE_RESULTAATTYPEN,
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function seedTree(array $fixture, ?int $parentId): array {
		$counts = ['seeded' => 0, 'present' => 0];
		try {
			$row = $this->store->items->findByUuid(uuid: (string)$fixture['uuid']);
			$counts['present']++;
		} catch (DoesNotExistException) {
			$row = $this->insertNode(fixture: $fixture, parentId: $parentId);
			$counts['seeded']++;
		}

		foreach (($fixture['children'] ?? []) as $child) {
			$counts = $this->addCounts(counts: $counts, more: $this->seedTree(fixture: $child, parentId: (int)$row->getId()));
		}

		return $counts;
	}//end seedTree()

	/**
	 * Two count tallies summed.
	 *
	 * @param array{seeded: int, present: int} $counts The tally so far.
	 * @param array{seeded: int, present: int} $more   The tally to add.
	 *
	 * @return array{seeded: int, present: int} The sum.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function addCounts(array $counts, array $more): array {
		return ['seeded' => $counts['seeded'] + $more['seeded'], 'present' => $counts['present'] + $more['present']];
	}//end addCounts()

	/**
	 * Insert one fixture node and its audit rows.
	 *
	 * @param array<string, mixed> $fixture The node: `uuid`, `item`, optional `audit`.
	 * @param int|null $parentId The parent's row id.
	 *
	 * @return CaseItem The inserted row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function insertNode(array $fixture, ?int $parentId): CaseItem {
		$row = new CaseItem();
		$row->hydrate($fixture['item']);
		$row->setUuid((string)$fixture['uuid']);
		$row->setParentItemId($parentId);
		$row = $this->store->items->insert($row);
		foreach (($fixture['audit'] ?? []) as $auditFixture) {
			$this->insertAudit(caseItemId: (int)$row->getId(), auditFixture: $auditFixture);
		}

		return $row;
	}//end insertNode()

	/**
	 * Insert one audit row for a seeded node.
	 *
	 * @param int $caseItemId The seeded row's id.
	 * @param array<string, mixed> $auditFixture The audit fixture.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function insertAudit(int $caseItemId, array $auditFixture): void {
		$entry = new CaseItemAudit();
		$entry->setCaseItemId($caseItemId);
		$entry->setFromState($auditFixture['from'] ?? null);
		$entry->setToState($auditFixture['to'] ?? null);
		$entry->setCause((string)$auditFixture['cause']);
		$entry->setCauseRef($auditFixture['causeRef'] ?? null);
		$entry->setActor($auditFixture['actor'] ?? null);
		$entry->setReason($auditFixture['reason'] ?? null);
		$entry->setAuthorized((bool)($auditFixture['authorized'] ?? true));
		$this->store->audits->insert($entry);
	}//end insertAudit()

	/**
	 * The fixture trees.
	 *
	 * @return array<int, array<string, mixed>> The root fixtures.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function fixtures(): array {
		return [
			// 1 + 2 + 3: the two-stage permit case with its discretionary and ad-hoc items.
			$this->intakeStage(),
			$this->assessmentStage(),
			// 4: a terminated stage with its cascade.
			$this->terminatedHearing(),
			// 5: a repeating item, two realisations: one completed, one active.
			$this->completedDocumentRequest(),
			$this->activeDocumentRequest(),
		];
	}//end fixtures()

	/**
	 * A permit-case item: the shared permit defaults overlaid with the item's own fields.
	 *
	 * @param array<string, mixed> $fields The item's own fields.
	 *
	 * @return array<string, mixed> The item row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private static function permitItem(array $fields): array {
		return array_merge(self::PERMIT_DEFAULTS, $fields);
	}//end permitItem()

	/**
	 * The audit row of a seeded item's creation.
	 *
	 * @param string $to The state the item was created in.
	 *
	 * @return array<string, mixed> The audit fixture.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private static function created(string $to): array {
		return ['from' => '', 'to' => $to, 'cause' => CaseItemAudit::CAUSE_IMPORT, 'actor' => 'demo-seed'];
	}//end created()

	/**
	 * The audit row of an item activated by its default entry sentry.
	 *
	 * @return array<string, mixed> The audit fixture.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private static function activatedBySentry(): array {
		return [
			'from' => CaseItem::STATE_AVAILABLE,
			'to' => CaseItem::STATE_ACTIVE,
			'cause' => CaseItemAudit::CAUSE_SENTRY,
			'causeRef' => 'entry:default',
			'actor' => 'case-plan',
		];
	}//end activatedBySentry()

	/**
	 * Group 1: the permit case's active intake stage with its three children.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function intakeStage(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c1',
			'item' => self::permitItem(fields: ['itemKey' => 'intake', 'name' => 'Intake', 'planItemType' => CaseItem::TYPE_STAGE, 'position' => 0, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'definitionItemKey' => 'intake', 'realisationKind' => CaseItem::REALISATION_NONE]),
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE), self::activatedBySentry()],
			'children' => [
				$this->completenessCheck(),
				$this->applicationCompleteMilestone(),
				$this->adhocSiteVisit(),
			],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end intakeStage()

	/**
	 * The intake's active human task, shared as anchor with the task fixtures.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function completenessCheck(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c2',
			'item' => self::permitItem(fields: ['itemKey' => 'completeness-check', 'name' => 'Controleer volledigheid', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'position' => 0, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'definitionItemKey' => 'completeness-check', 'candidateGroups' => ['demo-behandelaars'], 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-000000000001']),
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE), self::activatedBySentry()],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end completenessCheck()

	/**
	 * The intake's milestone, entered when the completeness check completes.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function applicationCompleteMilestone(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c3',
			'item' => self::permitItem(fields: ['itemKey' => 'application-complete', 'name' => 'Aanvraag volledig', 'planItemType' => CaseItem::TYPE_MILESTONE, 'position' => 1, 'state' => CaseItem::STATE_AVAILABLE, 'isTerminal' => false, 'definitionItemKey' => 'application-complete', 'entryCriteria' => [['id' => 'complete:entry', 'on' => ['event' => 'case.item.completed', 'item' => 'completeness-check']]]]),
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE)],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end applicationCompleteMilestone()

	/**
	 * Group 3: ad-hoc, in no definition, realised by a task with run_uuid null.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function adhocSiteVisit(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c6',
			'item' => self::permitItem(fields: ['itemKey' => 'adhoc-site-visit', 'name' => 'Locatiebezoek (ad hoc)', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'position' => 2, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'origin' => CaseItem::ORIGIN_ADHOC, 'required' => false, 'candidateUsers' => ['demo-behandelaar-1'], 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-0000000000c7']),
			'audit' => [['from' => '', 'to' => CaseItem::STATE_AVAILABLE, 'cause' => CaseItemAudit::CAUSE_USER, 'causeRef' => 'attach', 'actor' => 'demo-behandelaar-1'], self::activatedBySentry()],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end adhocSiteVisit()

	/**
	 * The permit case's assessment stage with its discretionary advice item.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function assessmentStage(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c4',
			'item' => self::permitItem(fields: ['itemKey' => 'assessment', 'name' => 'Beoordeling', 'planItemType' => CaseItem::TYPE_STAGE, 'position' => 1, 'state' => CaseItem::STATE_AVAILABLE, 'isTerminal' => false, 'definitionItemKey' => 'assessment', 'entryCriteria' => [['id' => 'assessment:entry', 'on' => ['event' => 'case.item.completed', 'item' => 'application-complete']]]]),
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE)],
			'children' => [$this->externalAdvice()],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end assessmentStage()

	/**
	 * Group 2: the discretionary advice item, with its denied enable audited.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function externalAdvice(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c5',
			'item' => self::permitItem(fields: ['itemKey' => 'external-advice', 'name' => 'Extern advies', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'position' => 0, 'state' => CaseItem::STATE_AVAILABLE, 'isTerminal' => false, 'definitionItemKey' => 'external-advice', 'origin' => CaseItem::ORIGIN_DISCRETIONARY, 'discretionary' => true, 'required' => false, 'authorizationRules' => ['demo-beslissers'], 'candidateRole' => 'demo-adviseurs']),
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_ENABLED, 'cause' => CaseItemAudit::CAUSE_USER, 'actor' => 'demo-stranger', 'reason' => "Verb 'enable' denied: the caller holds none of the item's authorizations.", 'authorized' => false]],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end externalAdvice()

	/**
	 * Group 4: a terminated stage whose exit cascaded to its two children.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function terminatedHearing(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c8',
			'item' => ['objectUuid' => self::TERMINATED_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'hearing', 'name' => 'Hoorzitting', 'planItemType' => CaseItem::TYPE_STAGE, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'hearing', 'position' => 0, 'state' => CaseItem::STATE_TERMINATED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'realisationCount' => 1, 'terminatedReason' => 'Bezwaar ingetrokken.', 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE), self::activatedBySentry(), ['from' => CaseItem::STATE_ACTIVE, 'to' => CaseItem::STATE_TERMINATED, 'cause' => CaseItemAudit::CAUSE_USER, 'actor' => 'demo-behandelaar-1', 'reason' => 'Bezwaar ingetrokken.']],
			'children' => [
				$this->invitePartiesTerminated(),
				$this->hearingReportDisabled(),
			],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end terminatedHearing()

	/**
	 * The hearing's active task, terminated by the stage's exit.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function invitePartiesTerminated(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000c9',
			'item' => ['objectUuid' => self::TERMINATED_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'invite-parties', 'name' => 'Partijen uitnodigen', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'invite-parties', 'position' => 0, 'state' => CaseItem::STATE_TERMINATED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'realisationCount' => 1, 'terminatedReason' => "Stage 'hearing' exited to 'terminated'.", 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_ACTIVE, 'to' => CaseItem::STATE_TERMINATED, 'cause' => CaseItemAudit::CAUSE_CASCADE, 'causeRef' => '00000000-0000-0000-0000-0000000000c8', 'actor' => 'case-plan', 'reason' => "Stage 'hearing' exited to 'terminated'."]],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end invitePartiesTerminated()

	/**
	 * The hearing's available task, disabled by the stage's exit.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function hearingReportDisabled(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000ca',
			'item' => ['objectUuid' => self::TERMINATED_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'hearing-report', 'name' => 'Verslag hoorzitting', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'hearing-report', 'position' => 1, 'state' => CaseItem::STATE_DISABLED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'realisationCount' => 1, 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_AVAILABLE, 'to' => CaseItem::STATE_DISABLED, 'cause' => CaseItemAudit::CAUSE_CASCADE, 'causeRef' => '00000000-0000-0000-0000-0000000000c8', 'actor' => 'case-plan', 'reason' => "Stage 'hearing' exited to 'terminated'."]],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end hearingReportDisabled()

	/**
	 * Group 5, first realisation of the repeating item: completed.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function completedDocumentRequest(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000cb',
			'item' => ['objectUuid' => self::REPEATING_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'request-documents', 'name' => 'Stukken opvragen', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'request-documents', 'position' => 0, 'state' => CaseItem::STATE_COMPLETED, 'isTerminal' => true, 'required' => true, 'discretionary' => false, 'repetition' => ['max' => 3], 'realisationCount' => 1, 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-0000000000cd', 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
			'audit' => [self::created(to: CaseItem::STATE_AVAILABLE), ['from' => CaseItem::STATE_ACTIVE, 'to' => CaseItem::STATE_COMPLETED, 'cause' => CaseItemAudit::CAUSE_REALISATION, 'causeRef' => '00000000-0000-0000-0000-0000000000cd', 'actor' => 'case-plan']],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end completedDocumentRequest()

	/**
	 * Group 5, second realisation of the repeating item: active.
	 *
	 * @return array<string, mixed> The fixture node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	private function activeDocumentRequest(): array {
		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- one fixture row per line keeps each seed readable as one record.
		return [
			'uuid' => '00000000-0000-0000-0000-0000000000cc',
			'item' => ['objectUuid' => self::REPEATING_OBJECT, 'registerId' => 1, 'schemaId' => 1, 'itemKey' => 'request-documents', 'name' => 'Stukken opvragen', 'planItemType' => CaseItem::TYPE_HUMAN_TASK, 'origin' => CaseItem::ORIGIN_DEFINED, 'definitionItemKey' => 'request-documents', 'position' => 0, 'state' => CaseItem::STATE_ACTIVE, 'isTerminal' => false, 'required' => true, 'discretionary' => false, 'repetition' => ['max' => 3], 'realisationCount' => 2, 'realisationKind' => CaseItem::REALISATION_TASK, 'realisationUuid' => '00000000-0000-0000-0000-0000000000ce', 'planSettings' => ['authorization' => ['demo-behandelaars']], 'createdBy' => 'demo-seed'],
			'audit' => [['from' => '', 'to' => CaseItem::STATE_AVAILABLE, 'cause' => CaseItemAudit::CAUSE_REALISATION, 'causeRef' => '00000000-0000-0000-0000-0000000000cb', 'actor' => 'case-plan'], self::activatedBySentry()],
		];
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end activeDocumentRequest()
}//end class
