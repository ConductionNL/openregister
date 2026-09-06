<?php

/**
 * The unlock step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNode
 */
final class UnlockObjectNodeTest extends TestCase {

	private const RUN = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private ObjectService $objects;

	private UnlockObjectNode $node;

	/**
	 * Wire the node over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(originalClassName: ObjectService::class);
		$this->objects->method('runAs')->willReturnCallback(
			static fn (IUser $user, callable $operation) => $operation()
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid !== 'alice') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn(true);
				return $user;
			}
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $p = []): string => $p === [] ? $text : vsprintf($text, $p)
		);

		$this->node = new UnlockObjectNode(
			$this->objects,
			$users,
			$l10n,
			$this->createMock(IURLGenerator::class)
		);
	}//end setUp()

	/**
	 * The run context.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return ['runUuid' => self::RUN, 'runAs' => 'alice'];
	}//end context()

	/**
	 * The release is scoped to the RUN, so it can only free the run's own lock.
	 *
	 * @return void
	 */
	public function testTheReleaseIsScopedToTheRun(): void {
		$seen = [];
		$this->objects->method('unlockObject')->willReturnCallback(
			function (...$args) use (&$seen): bool {
				// Positional order: identifier, advisory, runUuid.
				$seen = $args;
				return true;
			}
		);

		$out = $this->node->execute(
			[FlowItems::item(json: ['uuid' => self::OBJ])],
			[],
			$this->context()
		);

		$this->assertSame(self::OBJ, $seen[0]);
		$this->assertSame(self::RUN, $seen[2], 'the release was not scoped to the run');
		$this->assertSame(self::OBJ, $out[0][FlowItems::JSON]['uuid']);
	}//end testTheReleaseIsScopedToTheRun()

	/**
	 * Two items naming one object release it once.
	 *
	 * @return void
	 */
	public function testDuplicateTargetsAreReleasedOnce(): void {
		$this->objects->expects($this->once())->method('unlockObject')->willReturn(true);

		$this->node->execute(
			[
				FlowItems::item(json: ['uuid' => self::OBJ]),
				FlowItems::item(json: ['uuid' => self::OBJ]),
			],
			[],
			$this->context()
		);
	}//end testDuplicateTargetsAreReleasedOnce()

	/**
	 * An empty firing releases nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyFiringReleasesNothing(): void {
		$this->objects->expects($this->never())->method('unlockObject');
		$this->assertSame([], $this->node->execute([], [], $this->context()));
	}//end testAnEmptyFiringReleasesNothing()

	/**
	 * An unresolvable target throws rather than releasing nothing quietly.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTargetThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->node->execute([FlowItems::item(json: ['name' => 'no uuid'])], [], $this->context());
	}//end testAnUnresolvableTargetThrows()

	/**
	 * A run that cannot identify itself is refused: it could only release
	 * somebody else's lock or nothing at all.
	 *
	 * @return void
	 */
	public function testARunWithNoIdentityIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->node->execute(
			[FlowItems::item(json: ['uuid' => self::OBJ])],
			[],
			['runAs' => 'alice']
		);
	}//end testARunWithNoIdentityIsRefused()

	/**
	 * A non-text target is refused at authoring time.
	 *
	 * @return void
	 */
	public function testANonTextTargetIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['uuid' => ['not', 'text']]);
	}//end testANonTextTargetIsRefused()

	/**
	 * An empty config is valid: the target defaults to the item's object.
	 *
	 * @return void
	 */
	public function testAnEmptyConfigIsValid(): void {
		$this->node->validateConfig([]);
		$this->addToAssertionCount(1);
	}//end testAnEmptyConfigIsValid()

	/**
	 * Every form field writes a key the node declares.
	 *
	 * @return void
	 */
	public function testEveryFormFieldWritesADeclaredConfigKey(): void {
		foreach ($this->node->configForm() as $field) {
			$this->assertContains((string)$field['key'], $this->node->configKeys());
		}
	}//end testEveryFormFieldWritesADeclaredConfigKey()
}//end class
