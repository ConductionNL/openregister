<?php

declare(strict_types=1);

/**
 * The archiving process facts, as an object read reports them.
 *
 * A handler answering a Woo request needs the grondslag in front of them. The
 * category says which class of record this is; the ROW says which row of which
 * list actually decided, which is what an archiefinspecteur asks for. And an
 * object nothing could nominate has to read as `unnominatable` rather than as
 * one nobody has got to yet: from an absent appraisal alone those two look
 * exactly the same, and only one of them is somebody's problem.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the process facts ArchivalDecisionResolver publishes.
 */
class ArchivalProcessFactsTest extends TestCase {

	private ArchivalDecisionResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ArchivalDecisionResolver();
	}

	/**
	 * An object carrying a retention block.
	 *
	 * @param array<string, mixed> $retention The block.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRetention($retention);

		return $object;
	}

	public function testAHandlerSeesTheRowTheDateAndTheHoldTogether(): void {
		$decision = $this->resolver->resolve(
			$this->object(
				[
					'archiefnominatie' => 'vernietigen',
					'archiefactiedatum' => '2033-03-01',
					'classification' => '11.1.2',
					'selectielijstRow' => '11.1.2',
					'selectielijstBron' => 'Selectielijst gemeenten 2020',
					'legalHold' => ['active' => true, 'reason' => 'Woo-verzoek 2026/44'],
				]
			)
		);

		$this->assertSame('destroy', $decision['appraisal']);
		$this->assertSame('2033-03-01', $decision['disposalDate']);
		$this->assertSame('11.1.2', $decision['disposalCategory']);
		$this->assertSame('11.1.2', $decision['selectionListRow']);
		$this->assertSame('selection_list', $decision['basis']);
		$this->assertSame('Selectielijst gemeenten 2020', $decision['source']);
		$this->assertTrue($decision['legalHold']['active']);
	}

	public function testAnUnnominatableRecordSaysSoAndWhy(): void {
		$decision = $this->resolver->resolve(
			$this->object(
				[
					'nomination' => [
						'status' => ArchivalNominationService::STATUS_UNNOMINATABLE,
						'trigger' => 'closure',
						'unnominatableReason' => 'no selectielijst row matches category 11.1.2',
					],
				]
			)
		);

		$this->assertSame(
			ArchivalNominationService::STATUS_UNNOMINATABLE,
			$decision['nomination']['status']
		);
		$this->assertStringContainsString(
			'no selectielijst row matches',
			$decision['nomination']['unnominatableReason']
		);
	}

	public function testTheRecordCarriesItsOwnTransferRecord(): void {
		$decision = $this->resolver->resolve(
			$this->object(
				[
					'archiefnominatie' => 'blijvend_bewaren',
					'outcome' => [
						'kind' => 'transfer',
						'by' => 'els',
						'reason' => 'Blijvend bewaren, naar het e-depot',
						'transferListUuid' => 'transfer-list-7',
					],
				]
			)
		);

		$this->assertSame('transfer', $decision['outcome']['kind']);
		$this->assertSame('els', $decision['outcome']['by']);
		$this->assertSame('transfer-list-7', $decision['outcome']['transferListUuid']);
	}

	/**
	 * A record with nothing to say about its own archiving still says nothing.
	 * Adding the process keys must not turn "no obligation was ever declared"
	 * into a block that reads as one.
	 */
	public function testARecordWithNoArchivalClaimStillResolvesToNothing(): void {
		$this->assertNull($this->resolver->resolve($this->object([])));
	}
}
