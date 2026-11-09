<?php

/**
 * Behaviour tests for ExampleSetEmailLinks: missing objects, failed inserts, and
 * how a link is checked and reverted.
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


use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Behaviour of the email-link rows on missing objects, failures and reverts.
 */
class ExampleSetEmailLinksBehaviourTest extends TestCase {

	/** @var EmailLinkMapperInterface&MockObject */
	private EmailLinkMapperInterface $mapper;

	/** @var PartyService&MockObject */
	private PartyService $parties;

	protected function setUp(): void {
		$this->mapper  = $this->createMock(EmailLinkMapperInterface::class);
		$this->parties = $this->createMock(PartyService::class);
	}//end setUp()

	private function links(): ExampleSetEmailLinks {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[EmailLinkMapperInterface::class, $this->mapper],
				[PartyService::class, $this->parties],
			]
		);
		return new ExampleSetEmailLinks($container);
	}//end links()

	private static function row(): ExampleSetRow {
		return new ExampleSetRow('app', 'mail-1', ['object' => 'obj-1', 'subject' => 'Aanvraag', 'sender' => 'a@example.org']);
	}//end row()

	public function testWriteIsRefusedWithoutTheObject(): void {
		$this->parties->method('findUnscoped')->with('obj-1')->willReturn(null);
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame(['error' => 'no object "obj-1" to link the mail to', 'entry' => null], $this->links()->write(self::row()));
	}

	public function testWriteReportsAFailedInsert(): void {
		$object = new ObjectEntity();
		$object->setRegister('1');
		$object->setSchema('2');
		$this->parties->method('findUnscoped')->willReturn($object);
		$this->mapper->method('insert')->willThrowException(new \RuntimeException('duplicate link'));

		$this->assertSame(['error' => 'duplicate link', 'entry' => null], $this->links()->write(self::row()));
	}

	public function testLostFollowsWhetherTheLinkExists(): void {
		$this->mapper->method('find')->willReturnCallback(
			static function (int $id): EmailLink {
				if ($id !== 5) {
					throw new DoesNotExistException('gone');
				}

				return new EmailLink();
			}
		);

		$this->assertFalse($this->links()->lost(['id' => 5]));
		$this->assertTrue($this->links()->lost(['id' => 6]));
	}

	public function testRevertDeletesTheLink(): void {
		$link = new EmailLink();
		$this->mapper->method('find')->with(5)->willReturn($link);
		$this->mapper->expects($this->once())->method('delete')->with($link);

		$this->assertSame('removed', $this->links()->revert(['id' => 5]));
	}

	public function testRevertOfAGoneLinkCountsAsRemoved(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame('removed', $this->links()->revert(['id' => 5]));
	}

	public function testRevertReportsAnyOtherFailure(): void {
		$this->mapper->method('find')->willReturn(new EmailLink());
		$this->mapper->method('delete')->willThrowException(new \RuntimeException('locked'));

		$this->assertSame('failed', $this->links()->revert(['id' => 5]));
	}
}//end class
