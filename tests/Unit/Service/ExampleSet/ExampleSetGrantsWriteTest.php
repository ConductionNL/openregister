<?php

/**
 * Unit tests for ExampleSetGrants — writing, matching and reverting a set's shares.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetActingAs;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class ExampleSetGrantsWriteTest extends TestCase {

	private PartyService&MockObject $parties;
	private ObjectSharingService&MockObject $sharing;
	private IManager&MockObject $shares;
	private ExampleSetActingAs&MockObject $actingAs;
	private ExampleSetGrants $grants;

	protected function setUp(): void {
		$this->parties  = $this->createMock(PartyService::class);
		$this->sharing  = $this->createMock(ObjectSharingService::class);
		$this->shares   = $this->createMock(IManager::class);
		$this->actingAs = $this->createMock(ExampleSetActingAs::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[PartyService::class, $this->parties],
				[ObjectSharingService::class, $this->sharing],
				[IManager::class, $this->shares],
				[ExampleSetActingAs::class, $this->actingAs],
			]
		);

		$this->grants = new ExampleSetGrants($container);
	}

	private function row(array $data): ExampleSetRow {
		return new ExampleSetRow('demo', 'g1', $data);
	}

	private function share(int $node, string $by, string $with, int $type): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn($node);
		$share->method('getSharedBy')->willReturn($by);
		$share->method('getSharedWith')->willReturn($with);
		$share->method('getShareType')->willReturn($type);
		return $share;
	}

	private function entry(): array {
		return ['id' => '77', 'object' => 'obj-1', 'node' => 5, 'sharedBy' => 'ann', 'sharedWith' => 'bob', 'shareType' => 0];
	}

	public function testThePartAndNounNameGrants(): void {
		$this->assertSame('grants', $this->grants->part());
		$this->assertSame('grant', $this->grants->noun());
	}

	public function testWritingSharesTheObjectAsTheSharerAndRecordsTheShareIdentity(): void {
		$object = new ObjectEntity();
		$this->parties->expects($this->once())->method('findUnscoped')->with('obj-1')->willReturn($object);
		$this->actingAs->expects($this->once())->method('run')
			->with('ann', $this->isType('callable'))
			->willReturnCallback(static fn (string $uid, callable $operation): mixed => $operation());
		$this->sharing->expects($this->once())->method('grantWithTerms')
			->with(
				$this->identicalTo($object),
				$this->callback(
					static fn (GrantRequest $request): bool => $request->type === 'user'
						&& $request->shareWith === 'bob'
						&& $request->terms->permissions === 3
						&& $request->terms->verbs === ['read', 'comment']
				)
			)
			->willReturn(['id' => 77, 'nodeId' => '5', 'sharedBy' => 'ann', 'sharedWith' => 'bob', 'shareType' => '0']);

		$result = $this->grants->write(
			$this->row(['object' => 'obj-1', 'type' => 'user', 'shareWith' => 'bob', 'sharedBy' => 'ann', 'permissions' => 3, 'verbs' => ['read', 'comment']])
		);

		$this->assertSame(['error' => '', 'entry' => $this->entry()], $result);
	}

	public function testPermissionsDefaultToRead(): void {
		$this->parties->method('findUnscoped')->willReturn(new ObjectEntity());
		$this->actingAs->method('run')->willReturnCallback(static fn (string $uid, callable $operation): mixed => $operation());
		$this->sharing->expects($this->once())->method('grantWithTerms')
			->with($this->anything(), $this->callback(static fn (GrantRequest $r): bool => $r->terms->permissions === 1 && $r->terms->verbs === []))
			->willReturn(['id' => 1, 'nodeId' => 1, 'sharedBy' => 'a', 'sharedWith' => 'g', 'shareType' => 1]);

		$this->grants->write($this->row(['object' => 'o', 'type' => 'group', 'shareWith' => 'g', 'sharedBy' => 'a']));
	}

	public function testAMissingObjectIsAnErrorWithoutSharing(): void {
		$this->parties->method('findUnscoped')->willReturn(null);
		$this->actingAs->expects($this->never())->method('run');

		$this->assertSame(
			['error' => 'no object "gone" to share', 'entry' => null],
			$this->grants->write($this->row(['object' => 'gone', 'type' => 'user', 'shareWith' => 'b', 'sharedBy' => 'a']))
		);
	}

	public function testAFailingGrantIsReportedAsItsMessage(): void {
		$this->parties->method('findUnscoped')->willReturn(new ObjectEntity());
		$this->actingAs->method('run')->willThrowException(new RuntimeException('not allowed'));

		$this->assertSame(
			['error' => 'not allowed', 'entry' => null],
			$this->grants->write($this->row(['object' => 'o', 'type' => 'user', 'shareWith' => 'b', 'sharedBy' => 'a']))
		);
	}

	public function testAShareThatStillMatchesItsRecordIsNotLost(): void {
		$this->shares->expects($this->once())->method('getShareById')->with('77')->willReturn($this->share(5, 'ann', 'bob', 0));

		$this->assertFalse($this->grants->lost($this->entry()));
	}

	public function testAShareThatChangedOrVanishedIsLost(): void {
		$this->shares->method('getShareById')->willReturnOnConsecutiveCalls(
			$this->share(5, 'ann', 'carol', 0),
			$this->throwException(new RuntimeException('no share'))
		);

		$this->assertTrue($this->grants->lost($this->entry()));
		$this->assertTrue($this->grants->lost($this->entry()));
	}

	public function testAnEntryWithoutNodeIsLostWithoutLookingItUp(): void {
		$this->shares->expects($this->never())->method('getShareById');

		$this->assertTrue($this->grants->lost(['id' => '77']));
	}

	public function testRevertDeletesTheMatchingShare(): void {
		$share = $this->share(5, 'ann', 'bob', 0);
		$this->actingAs->method('refusal')->willReturn('');
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects($this->once())->method('deleteShare')->with($this->identicalTo($share));

		$this->assertSame('removed', $this->grants->revert($this->entry()));
	}

	public function testRevertFailsWhenDeletingFails(): void {
		$this->actingAs->method('refusal')->willReturn('');
		$this->shares->method('getShareById')->willReturn($this->share(5, 'ann', 'bob', 0));
		$this->shares->method('deleteShare')->willThrowException(new RuntimeException('locked'));

		$this->assertSame('failed', $this->grants->revert($this->entry()));
	}

	public function testAShareAlreadyGoneCountsAsRemoved(): void {
		$this->actingAs->method('refusal')->willReturn('');
		$this->shares->method('getShareById')->willReturn($this->share(9, 'x', 'y', 1));
		$this->shares->expects($this->never())->method('deleteShare');

		$this->assertSame('removed', $this->grants->revert($this->entry()));
	}

	public function testRevertFailsWhenActingAsIsRefusedOrTheEntryHasNoIdentity(): void {
		$this->actingAs->method('refusal')->willReturnOnConsecutiveCalls('no sudo', '');
		$this->shares->expects($this->never())->method('getShareById');
		$this->shares->expects($this->never())->method('deleteShare');

		$this->assertSame('failed', $this->grants->revert($this->entry()));
		$this->assertSame('failed', $this->grants->revert(['id' => '77']));
	}
}
