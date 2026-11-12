<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\DeckCardService;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\DeckPlatform;
use OCA\OpenRegister\Service\DeckLinkService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Pins the leaf-row shape both Deck services give a label and an assignment,
 * including every getter that throws or returns something odd.
 *
 * @covers \OCA\OpenRegister\Service\DeckCardService
 * @covers \OCA\OpenRegister\Service\DeckLinkService
 * @uses   \OCA\OpenRegister\Service\DeckRowMapper
 */
class DeckRowMappingTest extends TestCase {

	/**
	 * A Deck-like entity whose getters return the given values, or throw for '!throw'.
	 *
	 * @param array<string, mixed> $values Getter name => value.
	 */
	private static function entity(array $values): object {
		return new class($values) {
			public function __construct(private array $values) {
			}

			public function __call(string $name, array $args): mixed {
				$value = $this->values[$name] ?? null;
				if ($value === '!throw') {
					throw new RuntimeException($name);
				}

				return $value;
			}
		};
	}//end entity()

	/**
	 * @return array<string, array{array<string, mixed>, array<string, mixed>}>
	 */
	public static function labels(): array {
		return [
			'full'         => [['getId' => '7', 'getTitle' => 'Urgent', 'getColor' => 'ff0000'], ['id' => 7, 'title' => 'Urgent', 'color' => 'ff0000']],
			'null id'      => [['getId' => null, 'getTitle' => 'x', 'getColor' => null], ['id' => null, 'title' => 'x', 'color' => '']],
			'all throw'    => [['getId' => '!throw', 'getTitle' => '!throw', 'getColor' => '!throw'], ['id' => null, 'title' => '', 'color' => '']],
			'title object' => [['getId' => 3, 'getTitle' => new \stdClass(), 'getColor' => 'aa'], ['id' => 3, 'title' => '', 'color' => 'aa']],
		];
	}//end labels()

	/**
	 * @dataProvider labels
	 */
	public function testLabel(array $values, array $expected): void {
		foreach ([DeckCardService::class, DeckLinkService::class] as $class) {
			$this->assertSame($expected, $this->call($class, 'mapLabel', self::entity($values)), $class);
		}
	}//end testLabel()

	/**
	 * @return array<string, array{array<string, mixed>, array<string, mixed>}>
	 */
	public static function assignees(): array {
		return [
			'known user'     => [['getParticipant' => 'alice', 'getTypeString' => 'user'], ['uid' => 'alice', 'type' => 'user', 'displayName' => 'Alice A.']],
			'unknown user'   => [['getParticipant' => 'ghost', 'getTypeString' => 'user'], ['uid' => 'ghost', 'type' => 'user', 'displayName' => 'ghost']],
			'lookup throws'  => [['getParticipant' => 'boom', 'getTypeString' => 'user'], ['uid' => 'boom', 'type' => 'user', 'displayName' => 'boom']],
			'group'          => [['getParticipant' => 'staff', 'getTypeString' => 'group'], ['uid' => 'staff', 'type' => 'group', 'displayName' => 'staff']],
			'group like user' => [['getParticipant' => 'alice', 'getTypeString' => 'group'], ['uid' => 'alice', 'type' => 'group', 'displayName' => 'alice']],
			'type throws'    => [['getParticipant' => 'alice', 'getTypeString' => '!throw'], ['uid' => 'alice', 'type' => 'user', 'displayName' => 'Alice A.']],
			'no participant' => [['getParticipant' => '!throw', 'getTypeString' => 'user'], ['uid' => '', 'type' => 'user', 'displayName' => '']],
		];
	}//end assignees()

	/**
	 * @dataProvider assignees
	 */
	public function testAssignee(array $values, array $expected): void {
		foreach ([DeckCardService::class, DeckLinkService::class] as $class) {
			$this->assertSame($expected, $this->call($class, 'mapAssignee', self::entity($values)), $class);
		}
	}//end testAssignee()

	private function call(string $class, string $method, object $entity): array {
		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice A.');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($alice) {
			if ($uid === 'boom') {
				throw new RuntimeException('lookup');
			}

			return $uid === 'alice' ? $alice : null;
		});

		$service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		if ($class === DeckCardService::class) {
			$deck = new DeckPlatform(userManager: $users, container: $this->createMock(ContainerInterface::class));
			(new ReflectionProperty($class, 'deck'))->setValue($service, $deck);
		} else {
			(new ReflectionProperty($class, 'userManager'))->setValue($service, $users);
		}
		$reflection = new ReflectionMethod($service, $method);
		$reflection->setAccessible(true);
		return $reflection->invoke($service, $entity);
	}//end call()
}//end class
