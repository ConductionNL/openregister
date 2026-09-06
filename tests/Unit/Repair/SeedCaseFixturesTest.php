<?php

/**
 * The seed step: off by default, idempotent on uuid, six groups as trees
 * with parent ids and audit rows, a failing fixture reported and skipped.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Repair\SeedCaseFixtures;
use OCA\OpenRegister\Tests\Unit\Service\Case\FakeCaseItemMapper;
use OCA\OpenRegister\Tests\Unit\Service\Case\RecordingAuditMapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Seed step coverage.
 *
 * @covers \OCA\OpenRegister\Repair\SeedCaseFixtures
 */
class SeedCaseFixturesTest extends TestCase {

	/**
	 * The step over in-memory mappers.
	 *
	 * @param bool $enabled The flag.
	 * @param FakeCaseItemMapper $items The rows.
	 * @param RecordingAuditMapper $audits The audit.
	 *
	 * @return SeedCaseFixtures The step.
	 */
	private function step(bool $enabled, FakeCaseItemMapper $items, RecordingAuditMapper $audits): SeedCaseFixtures {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->with('openregister', SeedCaseFixtures::FLAG, false)->willReturn($enabled);

		return new SeedCaseFixtures(appConfig: $config, items: $items, audits: $audits, logger: new NullLogger());
	}//end step()

	/**
	 * Off by default: nothing written.
	 *
	 * @return void
	 */
	public function testOffByDefault(): void {
		$items = new FakeCaseItemMapper($this);
		$audits = new RecordingAuditMapper($this);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('skipped'));

		$step = $this->step(enabled: false, items: $items, audits: $audits);
		$this->assertStringContainsString('flow-cmmn-case-semantics', $step->getName());
		$step->run($output);
		$this->assertSame([], $items->rows);
	}//end testOffByDefault()

	/**
	 * On: the six groups land as trees with parent ids and audit rows, and a
	 * second run seeds nothing.
	 *
	 * @return void
	 */
	public function testTheSixGroupsAreSeededIdempotently(): void {
		$items = new FakeCaseItemMapper($this);
		$audits = new RecordingAuditMapper($this);
		$output = $this->createMock(IOutput::class);
		$step = $this->step(enabled: true, items: $items, audits: $audits);

		$step->run($output);
		$this->assertCount(11, $items->rows);

		$byKey = [];
		foreach ($items->rows as $row) {
			$byKey[(string)$row->getItemKey() . '#' . (int)$row->getRealisationCount()] = $row;
		}

		// 1: nesting, milestone sentry, shared anchor with the task fixtures.
		$this->assertSame(SeedCaseFixtures::PERMIT_OBJECT, $byKey['intake#1']->getObjectUuid());
		$this->assertSame($byKey['intake#1']->getId(), $byKey['completeness-check#1']->getParentItemId());
		$this->assertSame('00000000-0000-0000-0000-000000000001', $byKey['completeness-check#1']->getRealisationUuid());
		$this->assertSame('completeness-check', $byKey['application-complete#1']->getEntryCriteria()[0]['on']['item']);
		// 2: discretionary with its denial audited.
		$advice = $byKey['external-advice#1'];
		$this->assertTrue($advice->getDiscretionary());
		$this->assertSame(['demo-beslissers'], $advice->getAuthorizationRules());
		$denials = array_filter($audits->findForItem((int)$advice->getId()), static fn (CaseItemAudit $entry): bool => $entry->getAuthorized() === false);
		$this->assertCount(1, $denials);
		// 3: ad-hoc, no definition key, no flow.
		$adhoc = $byKey['adhoc-site-visit#1'];
		$this->assertSame(CaseItem::ORIGIN_ADHOC, $adhoc->getOrigin());
		$this->assertNull($adhoc->getDefinitionItemKey());
		$this->assertNull($adhoc->getFlowUuid());
		// 4: the cascade rows name the parent.
		$hearing = $byKey['hearing#1'];
		foreach (['invite-parties#1', 'hearing-report#1'] as $child) {
			$this->assertSame($hearing->getId(), $byKey[$child]->getParentItemId());
			$cascade = array_filter($audits->findForItem((int)$byKey[$child]->getId()), static fn (CaseItemAudit $entry): bool => $entry->getCause() === CaseItemAudit::CAUSE_CASCADE);
			$this->assertCount(1, $cascade);
			$this->assertSame($hearing->getUuid(), array_values($cascade)[0]->getCauseRef());
		}

		$this->assertSame(CaseItem::STATE_DISABLED, $byKey['hearing-report#1']->getState());
		// 5: two realisations, one completed, one active.
		$this->assertSame(CaseItem::STATE_COMPLETED, $byKey['request-documents#1']->getState());
		$this->assertSame(CaseItem::STATE_ACTIVE, $byKey['request-documents#2']->getState());
		// 6: the zaaktype fixture has what the mapper tests need.
		$zaaktype = SeedCaseFixtures::zaaktypeFixture();
		$this->assertSame([3, 1, 4, 2], array_column($zaaktype['statustypen'], 'volgnummer'));
		$this->assertCount(3, $zaaktype['roltypen']);
		$this->assertCount(2, $zaaktype['resultaattypen']);

		foreach ($items->rows as $row) {
			$this->assertStringStartsWith('00000000-0000-0000-0000-', (string)$row->getUuid(), 'nil placeholders');
			$this->assertSame($row->isInTerminalState(), $row->getIsTerminal(), 'state and is_terminal agree');
		}

		$auditRows = count($audits->entries);
		$step->run($output);
		$this->assertCount(11, $items->rows, 'Idempotent on uuid.');
		$this->assertCount($auditRows, $audits->entries);
	}//end testTheSixGroupsAreSeededIdempotently()

	/**
	 * A failing fixture is reported and the rest continue.
	 *
	 * @return void
	 */
	public function testAFailingFixtureIsReportedAndTheRestContinue(): void {
		$items = new FakeCaseItemMapper($this);
		$audits = new RecordingAuditMapper($this);
		$audits->failNext = true;
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('audit table unavailable'));
		$output->expects($this->once())->method('info');

		$this->step(enabled: true, items: $items, audits: $audits)->run($output);
		$this->assertGreaterThan(6, count($items->rows), 'The other groups were seeded.');
	}//end testAFailingFixtureIsReportedAndTheRestContinue()
}//end class
