<?php

/**
 * The zaaktype mapping: sequence-ordered milestones, the constrained result
 * set, carried terms, and a report naming everything unmappable.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Repair\SeedCaseFixtures;
use OCA\OpenRegister\Service\Case\CasePlanDefinition;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * Coverage of ZaaktypeCaseSkeletonMapper over the design's fixture.
 *
 * @covers \OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper
 */
class ZaaktypeCaseSkeletonMapperTest extends TestCase {

	/**
	 * Four out-of-order statustypen become four milestones in volgnummer order,
	 * and the skeleton is a valid draft definition.
	 *
	 * @return void
	 */
	public function testStatusesBecomeSequenceOrderedMilestonesInAValidDraft(): void {
		$result = (new ZaaktypeCaseSkeletonMapper())->map(zaaktype: SeedCaseFixtures::zaaktypeFixture());

		$this->assertTrue($result['draft']);
		$milestones = [];
		foreach ($result['definition']['items'] as $stage) {
			foreach ($stage['children'] as $child) {
				if ($child['type'] === CaseItem::TYPE_MILESTONE) {
					$milestones[] = $child['name'];
				}
			}
		}

		$this->assertSame(['Ontvangen', 'Volledig', 'In behandeling', 'Afgehandeld'], $milestones);
		$this->assertSame([], $result['definition']['items'][0]['entryCriteria'], 'The first stage enters at once.');
		$this->assertSame('status-1-ontvangen', $result['definition']['items'][1]['entryCriteria'][0]['on']['item'], 'The second waits for the first milestone.');

		// The draft compiles through the real definition boundary.
		$normalised = (new CasePlanDefinition(sentries: new CaseSentryEvaluator(catalog: new EventCatalogService())))->validate(definition: $result['definition']);
		$this->assertCount(4, $normalised['items']);
	}//end testStatusesBecomeSequenceOrderedMilestonesInAValidDraft()

	/**
	 * Roles, results and terms land where the spec says; unmappable content is reported, never dropped.
	 *
	 * @return void
	 */
	public function testRolesResultsTermsAndTheReport(): void {
		$result = (new ZaaktypeCaseSkeletonMapper())->map(zaaktype: SeedCaseFixtures::zaaktypeFixture());
		$settings = $result['definition']['settings'];

		$this->assertSame(['Verleend', 'Geweigerd'], $settings['results']);
		$this->assertSame('blijvend_bewaren', $settings['resultMetadata']['Verleend']['archiefnominatie']);
		$this->assertSame('P8W', $settings['doorlooptijd']);
		$this->assertSame('P6W', $settings['servicenorm']);
		$this->assertCount(3, $settings['candidateRoles']);
		$this->assertSame('initiator', $settings['candidateRoles'][0]['generic'], 'The generic designation is preserved.');

		$task = $result['definition']['items'][0]['children'][0];
		$this->assertSame(CaseItem::TYPE_HUMAN_TASK, $task['type']);
		$this->assertSame('Vergunningverlener', $task['candidateRole'], 'The behandelaar role is on the human items.');
		$this->assertSame('P8W', $task['doorlooptijd']);

		$byElement = [];
		foreach ($result['report'] as $line) {
			$byElement[$line['element']] = $line;
			$this->assertNotSame('', $line['action']);
		}

		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $byElement['publicatieIndicatie']['status']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $byElement['verlengingMogelijk']['status']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $byElement['authorization']['status']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::CARRIED, $byElement['doorlooptijd']['status']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::CARRIED, $byElement["roltypen 'Welstandscommissie'"]['status']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::MAPPED, $byElement["roltypen 'Vergunningverlener'"]['status']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::MAPPED, $byElement['resultaattypen']['status']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::APPROXIMATE, $byElement['statustypen (volgnummer 1)']['status']);
		$this->assertArrayNotHasKey('omschrijving', $byElement, 'Identity elements are not reported as unmapped.');
	}//end testRolesResultsTermsAndTheReport()

	/**
	 * Degenerate input: no statuses, no results, malformed entries, an
	 * unnumbered status placed last, extra status attributes reported.
	 *
	 * @return void
	 */
	public function testDegenerateInputIsReportedNotGuessed(): void {
		$mapper = new ZaaktypeCaseSkeletonMapper();
		$empty = $mapper->map(zaaktype: ['identificatie' => 'X']);
		$elements = array_column($empty['report'], 'status', 'element');
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $elements['statustypen']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $elements['resultaattypen']);
		$this->assertSame([], $empty['definition']['items']);
		$this->assertSame('X', $empty['definition']['settings']['name']);

		$odd = $mapper->map(
			zaaktype: [
				'statustypen' => [
					'not-an-object',
					['omschrijving' => 'Zonder nummer', 'statustekst' => 'tekst'],
					['volgnummer' => 2, 'omschrijving' => 'Twee'],
					['volgnummer' => 1, 'omschrijving' => '***'],
				],
				'roltypen' => ['x', ['omschrijvingGeneriek' => 'adviseur']],
				'resultaattypen' => [['archiefnominatie' => 'v']],
				'doorlooptijd' => '',
			]
		);
		$names = array_map(static fn (array $stage): string => $stage['name'], $odd['definition']['items']);
		$this->assertSame(['***', 'Twee', 'Zonder nummer'], $names, 'Numbered first, unnumbered last.');
		$this->assertStringStartsWith('fase-1-status', $odd['definition']['items'][0]['key'], 'An unsluggable label falls back.');
		$statuses = array_column($odd['report'], 'status', 'element');
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $statuses['statustypen[0]']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::APPROXIMATE, $statuses['statustypen[1]']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $statuses['statustypen (volgnummer -).statustekst']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $statuses['roltypen[0]']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $statuses['roltypen[1]']);
		$this->assertSame(ZaaktypeCaseSkeletonMapper::UNMAPPED, $statuses['resultaattypen[0]']);
		$this->assertSame([], $odd['definition']['settings']['results']);
		$this->assertArrayNotHasKey('doorlooptijd', $odd['definition']['settings']);
	}//end testDegenerateInputIsReportedNotGuessed()
}//end class
