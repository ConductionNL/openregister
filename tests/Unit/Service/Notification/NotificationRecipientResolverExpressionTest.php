<?php

/**
 * Characterisation test for the `expression` recipients kind of NotificationRecipientResolver.
 *
 * Pins what an expression entry resolves to when a server container is
 * injected: the resolver's uids (handed the object and the trigger context),
 * and nobody when the tag is empty, there is no object, the service is not a
 * RecipientResolverInterface or the resolver throws.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver
 */
final class NotificationRecipientResolverExpressionTest extends TestCase {

	/**
	 * What the resolver was handed, per call.
	 *
	 * @var list<array{0: ObjectEntity, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A resolver whose expression service is the given value (or throws when it is a Throwable).
	 *
	 * @param mixed           $service The service the container hands out for tag `X`.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return NotificationRecipientResolver
	 */
	private function resolver(mixed $service, LoggerInterface $logger): NotificationRecipientResolver {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['anna', 'bram'], true));

		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service) {
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		return new NotificationRecipientResolver(
			userManager: $users,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $logger,
			serverContainer: $container
		);
	}//end resolver()

	/**
	 * An expression service that records its calls and returns the given uids.
	 *
	 * @param array<int|string, string> $uids The uids it returns.
	 *
	 * @return RecipientResolverInterface
	 */
	private function expression(array $uids): RecipientResolverInterface {
		$calls = &$this->calls;
		return new class ($uids, $calls) implements RecipientResolverInterface {
			/**
			 * @param array<int|string, string> $uids  The uids it returns.
			 * @param array<int, mixed>         $calls The recorded calls.
			 */
			public function __construct(private array $uids, private array &$calls) {
			}

			/**
			 * @param ObjectEntity         $object  The object.
			 * @param array<string, mixed> $context The trigger context.
			 *
			 * @return array<int|string, string>
			 */
			public function resolve(ObjectEntity $object, array $context): array {
				$this->calls[] = [$object, $context];
				return $this->uids;
			}
		};
	}//end expression()

	/**
	 * Resolve one expression entry.
	 *
	 * @param NotificationRecipientResolver $resolver The resolver.
	 * @param string                        $tag      The resolver tag.
	 * @param ObjectEntity|null             $object   The object.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve(NotificationRecipientResolver $resolver, string $tag, ?ObjectEntity $object): array {
		return $resolver->resolveWithDiagnostics(
			recipientsSpec: [['kind' => 'expression', 'resolver' => $tag]],
			data: [],
			object: $object,
			context: ['trigger' => 'created']
		);
	}//end resolve()

	/**
	 * The resolver's uids come back; it is handed the object and the trigger context.
	 *
	 * @return void
	 */
	public function testTheResolverUidsAreReturned(): void {
		$object   = new ObjectEntity();
		$resolver = $this->resolver($this->expression(['x' => 'anna', 'y' => 'bram']), $this->createMock(LoggerInterface::class));

		$this->assertSame(['uids' => ['anna', 'bram'], 'unresolved' => []], $this->resolve($resolver, 'X', $object));
		$this->assertCount(1, $this->calls);
		$this->assertSame($object, $this->calls[0][0]);
		$this->assertSame(['trigger' => 'created'], $this->calls[0][1]);
	}//end testTheResolverUidsAreReturned()

	/**
	 * Without an object, or with an empty tag, the resolver is never asked.
	 *
	 * @return void
	 */
	public function testNoObjectOrNoTagAsksNobody(): void {
		$resolver = $this->resolver($this->expression(['anna']), $this->createMock(LoggerInterface::class));

		$this->assertSame(['uids' => [], 'unresolved' => []], $this->resolve($resolver, 'X', null));
		$this->assertSame(['uids' => [], 'unresolved' => []], $this->resolve($resolver, '', new ObjectEntity()));
		$this->assertSame([], $this->calls);
	}//end testNoObjectOrNoTagAsksNobody()

	/**
	 * A service that is not a recipient resolver resolves to nobody, with a warning.
	 *
	 * @return void
	 */
	public function testAWrongServiceResolvesToNobody(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('does not implement RecipientResolverInterface'));

		$this->assertSame(['uids' => [], 'unresolved' => []], $this->resolve($this->resolver(new \stdClass(), $logger), 'X', new ObjectEntity()));
	}//end testAWrongServiceResolvesToNobody()

	/**
	 * A resolver that cannot be built resolves to nobody, with a warning.
	 *
	 * @return void
	 */
	public function testAFailingResolverResolvesToNobody(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('expression resolver "X" failed: boom'));

		$this->assertSame(
			['uids' => [], 'unresolved' => []],
			$this->resolve($this->resolver(new \RuntimeException('boom'), $logger), 'X', new ObjectEntity())
		);
	}//end testAFailingResolverResolvesToNobody()
}//end class
