<?php

/**
 * What a reference means, right now, and what happens when nothing knows.
 *
 * 🔴 THE RE-RESOLUTION TEST IS THE POINT OF THE WHOLE DESIGN. A municipal
 * approval outlives the roster it was raised against: a task assigned to the
 * bezwaarcommissie in March must be answerable by whoever sits on it in June.
 * Freezing the resolution would keep authorising somebody who has left and stop
 * authorising the person now responsible.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow\Principal;

use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * A resolver whose answer the test can change between calls.
 */
class MutableResolver implements IPrincipalResolver {

	/**
	 * What each id currently resolves to.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $members = [];

	/**
	 * How many times resolve() was called.
	 *
	 * @var integer
	 */
	public int $calls = 0;

	/**
	 * Constructor.
	 *
	 * @param string  $type   The type it answers for.
	 * @param boolean $throws Whether resolving blows up.
	 */
	public function __construct(
		private readonly string $type = 'group',
		private readonly bool $throws = false,
	) {

	}//end __construct()

	/**
	 * The type.
	 *
	 * @return string The type.
	 */
	public function type(): string {
		return $this->type;
	}//end type()

	/**
	 * The current members.
	 *
	 * @param string $id The id.
	 *
	 * @return array<int, string> The uids.
	 */
	public function resolve(string $id): array {
		$this->calls++;
		if ($this->throws === true) {
			throw new RuntimeException('the directory is unreachable');
		}

		return ($this->members[$id] ?? []);
	}//end resolve()
}//end class

/**
 * Tests for {@see PrincipalResolverRegistry}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 */
final class PrincipalResolverRegistryTest extends TestCase {

	/**
	 * A registry holding the given resolvers.
	 *
	 * @param array<int, IPrincipalResolver> $resolvers The resolvers.
	 * @param LoggerInterface|null           $logger    A logger to observe.
	 *
	 * @return PrincipalResolverRegistry The registry.
	 */
	private function registryOf(array $resolvers, ?LoggerInterface $logger = null): PrincipalResolverRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($resolvers): void {
				if (($event instanceof RegisterPrincipalResolversEvent) === false) {
					return;
				}

				foreach ($resolvers as $resolver) {
					$event->registerResolver($resolver);
				}
			}
		);

		return new PrincipalResolverRegistry($dispatcher, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end registryOf()

	/**
	 * 🔴 A GROUP RE-RESOLVES AFTER A MEMBERSHIP CHANGE.
	 *
	 * Somebody who joins after the task was raised can answer it; somebody who
	 * leaves can no longer answer it. A frozen resolution gets both backwards.
	 *
	 * @return void
	 */
	public function testAGroupReResolvesAfterAMembershipChange(): void {
		$groups = new MutableResolver();
		$groups->members['bezwaar'] = ['alice', 'bob'];

		$registry = $this->registryOf([$groups]);
		$reference = PrincipalReference::from(value: ['type' => 'group', 'id' => 'bezwaar']);

		$this->assertSame(['alice', 'bob'], $registry->resolve(reference: $reference));

		// June: bob has left the committee and carol has joined it.
		$groups->members['bezwaar'] = ['alice', 'carol'];

		$this->assertSame(
			['alice', 'carol'],
			$registry->resolve(reference: $reference),
			'a cached resolution would keep authorising bob and refuse carol'
		);
		$this->assertSame(2, $groups->calls, 'the resolver must be asked every time, not once');
	}//end testAGroupReResolvesAfterAMembershipChange()

	/**
	 * An unregistered type answers `has() === false`.
	 *
	 * @return void
	 */
	public function testAnUnregisteredTypeIsNotKnown(): void {
		$registry = $this->registryOf([new MutableResolver()]);

		$this->assertTrue($registry->has(type: 'group'));
		$this->assertFalse($registry->has(type: 'position'));
		$this->assertSame(['group'], $registry->types());
	}//end testAnUnregisteredTypeIsNotKnown()

	/**
	 * 🔴 AN UNKNOWN TYPE RESOLVES TO NOBODY RATHER THAN THROWING.
	 *
	 * This is called while authorising an answer. A type whose app has been
	 * disabled since the flow was authored must refuse the answer, not break
	 * the request with a 500 where "you may not answer this" belongs.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeResolvesToNobodyAndSaysSo(): void {
		$seen = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$seen): void {
				$seen[] = $message;
			}
		);

		$registry = $this->registryOf([new MutableResolver()], $logger);
		$ids = $registry->resolve(reference: PrincipalReference::from(value: ['type' => 'position', 'id' => 'chair']));

		$this->assertSame([], $ids);
		$this->assertStringContainsString('position', implode(' ', $seen));
	}//end testAnUnknownTypeResolvesToNobodyAndSaysSo()

	/**
	 * A resolver that throws refuses the answer rather than taking the verb down.
	 *
	 * @return void
	 */
	public function testAResolverThatThrowsRefusesRatherThanBreaks(): void {
		$registry = $this->registryOf([new MutableResolver('group', throws: true)]);

		$this->assertSame(
			[],
			$registry->resolve(reference: PrincipalReference::from(value: ['type' => 'group', 'id' => 'bezwaar']))
		);
	}//end testAResolverThatThrowsRefusesRatherThanBreaks()

	/**
	 * Two apps claiming one type is refused, not resolved by load order.
	 *
	 * @return void
	 */
	public function testADuplicateTypeIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$registry = $this->registryOf([new MutableResolver('group'), new MutableResolver('group')]);
		$registry->has(type: 'group');
	}//end testADuplicateTypeIsRefused()

	/**
	 * A list resolves to the union, without duplicates.
	 *
	 * @return void
	 */
	public function testAListResolvesToTheUnion(): void {
		$groups = new MutableResolver();
		$groups->members['bezwaar'] = ['alice', 'bob'];
		$groups->members['college'] = ['bob', 'carol'];

		$registry = $this->registryOf([$groups]);
		$ids = $registry->resolveAll(
			references: PrincipalReference::listFrom(
				value: [
					['type' => 'group', 'id' => 'bezwaar'],
					['type' => 'group', 'id' => 'college'],
				]
			)
		);

		// bob sits on both and is one person.
		$this->assertSame(['alice', 'bob', 'carol'], $ids);
	}//end testAListResolvesToTheUnion()

	/**
	 * A resolver naming no type is refused.
	 *
	 * @return void
	 */
	public function testAResolverMustNameItsType(): void {
		$this->expectException(UnexpectedValueException::class);

		$registry = $this->registryOf([new MutableResolver('  ')]);
		$registry->types();
	}//end testAResolverMustNameItsType()

	/**
	 * Contribution is collected once, however many questions are asked.
	 *
	 * @return void
	 */
	public function testContributionIsCollectedOncePerRequest(): void {
		$dispatched = 0;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$dispatched): void {
				$dispatched++;
				if (($event instanceof RegisterPrincipalResolversEvent) === true) {
					$event->registerResolver(new MutableResolver());
				}
			}
		);

		$registry = new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$registry->has(type: 'group');
		$registry->types();
		$registry->resolve(reference: PrincipalReference::from(value: ['type' => 'group', 'id' => 'x']));

		$this->assertSame(1, $dispatched);
	}//end testContributionIsCollectedOncePerRequest()
}//end class
