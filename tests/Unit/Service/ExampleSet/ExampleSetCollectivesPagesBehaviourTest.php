<?php

/**
 * Behaviour tests for ExampleSetCollectivesPages: what a page row records, and how
 * store failures and reverts are reported.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\CollectivePageText;
use OCA\OpenRegister\Service\ExampleSet\CollectiveSeat;
use OCA\OpenRegister\Service\ExampleSet\CollectiveTeam;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesStore;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Behaviour of the Collectives page rows on store failures and reverts.
 */
class ExampleSetCollectivesPagesBehaviourTest extends TestCase {

	/** @var ExampleSetCollectivesStore&MockObject */
	private ExampleSetCollectivesStore $store;

	protected function setUp(): void {
		$this->store = $this->createMock(ExampleSetCollectivesStore::class);
	}//end setUp()

	private function pages(): ExampleSetCollectivesPages {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ExampleSetCollectivesStore::class)->willReturn($this->store);
		return new ExampleSetCollectivesPages($container);
	}//end pages()

	private static function row(): ExampleSetRow {
		return new ExampleSetRow('app', 'k', ['owner' => 'alice', 'collective' => 'Kennis', 'title' => 'Welkom', 'content' => 'Hoi']);
	}//end row()

	public function testWriteIsRefusedWhenCollectivesIsNotInstalled(): void {
		$this->store->method('available')->willReturn(false);
		$this->store->expects($this->never())->method('collectiveOf');

		$this->assertSame(['error' => 'Collectives is not installed', 'entry' => null], $this->pages()->write(self::row()));
	}

	public function testWriteReportsAStoreFailure(): void {
		$this->store->method('available')->willReturn(true);
		$this->store->method('collectiveOf')->willThrowException(new \RuntimeException('circles down'));
		$this->store->expects($this->never())->method('page');

		$this->assertSame(['error' => 'circles down', 'entry' => null], $this->pages()->write(self::row()));
	}

	public function testWriteRecordsWhatItCreated(): void {
		$this->store->method('available')->willReturn(true);
		$this->store->expects($this->once())->method('collectiveOf')->with(new CollectiveTeam('alice', []), 'Kennis')->willReturn(['id' => 3, 'created' => false]);
		$this->store->expects($this->once())->method('page')->with(new CollectiveSeat(3, 'alice'), new CollectivePageText('Welkom', 'Hoi'))->willReturn(['id' => 9, 'created' => true]);

		$this->assertSame(
			['error' => '', 'entry' => ['collective' => 3, 'createdCollective' => false, 'page' => 9, 'createdPage' => true, 'owner' => 'alice']],
			$this->pages()->write(self::row())
		);
	}

	public function testLostIsTrueWhenTheStoreFails(): void {
		$this->store->method('pageFound')->willThrowException(new \RuntimeException('down'));

		$this->assertTrue($this->pages()->lost(['collective' => 3, 'page' => 9, 'owner' => 'alice']));
	}

	public function testLostFollowsWhetherThePageExists(): void {
		$this->store->method('pageFound')->with(new CollectiveSeat(3, 'alice'), 9)->willReturn(true);

		$this->assertFalse($this->pages()->lost(['collective' => 3, 'page' => 9, 'owner' => 'alice']));
	}

	public function testRevertOfACreatedCollectiveDeletesItAndNotThePage(): void {
		$this->store->expects($this->once())->method('deleteCollective')->with(3, 'alice');
		$this->store->expects($this->never())->method('deletePage');

		$this->assertSame('removed', $this->pages()->revert(['collective' => 3, 'createdCollective' => true, 'page' => 9, 'createdPage' => true, 'owner' => 'alice']));
	}

	public function testRevertOfAReusedCollectiveDeletesOnlyACreatedPage(): void {
		$this->store->expects($this->never())->method('deleteCollective');
		$this->store->expects($this->once())->method('deletePage')->with(new CollectiveSeat(3, 'alice'), 9);

		$this->assertSame('removed', $this->pages()->revert(['collective' => 3, 'createdCollective' => false, 'page' => 9, 'createdPage' => true, 'owner' => 'alice']));
	}

	public function testRevertReportsAFailure(): void {
		$this->store->method('deleteCollective')->willThrowException(new \RuntimeException('locked'));

		$this->assertSame('failed', $this->pages()->revert(['collective' => 3, 'createdCollective' => true, 'page' => 9, 'owner' => 'alice']));
	}
}//end class
