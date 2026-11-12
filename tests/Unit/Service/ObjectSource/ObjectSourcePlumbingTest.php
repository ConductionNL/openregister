<?php

/**
 * The plumbing several object-source providers share: count through findAll,
 * the installed-app check, and the container and getter reads of Deck and Talk.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude Pins plumbing shared by the object-source providers.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Plumbing shared by the object-source providers.
 */
class ObjectSourcePlumbingTest extends TestCase {

	/**
	 * Providers whose count() is the size of findAll().
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function countingProviders(): array {
		return [
			'caldav' => [ObjectSource\CalDavVtodoObjectSourceProvider::class],
			'calendar' => [ObjectSource\CalendarEventObjectSourceProvider::class],
			'contacts' => [ObjectSource\ContactsObjectSourceProvider::class],
			'deck' => [ObjectSource\DeckObjectSourceProvider::class],
			'federated' => [ObjectSource\FederatedObjectSourceProvider::class],
			'files' => [ObjectSource\FilesObjectSourceProvider::class],
			'group' => [ObjectSource\GroupObjectSourceProvider::class],
			'organisation' => [ObjectSource\OrganisationObjectSourceProvider::class],
			'talk' => [ObjectSource\TalkObjectSourceProvider::class],
			'user directory' => [ObjectSource\UserDirectoryObjectSourceProvider::class],
		];
	}//end countingProviders()

	/**
	 * Deck and Talk with the app each needs.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function appProviders(): array {
		return [
			'deck' => [ObjectSource\DeckObjectSourceProvider::class, 'deck'],
			'talk' => [ObjectSource\TalkObjectSourceProvider::class, 'spreed'],
		];
	}//end appProviders()

	/**
	 * count() hands its arguments to findAll() and returns how many came back.
	 *
	 * @param class-string $class The provider class.
	 *
	 * @return void
	 */
	#[DataProvider('countingProviders')]
	public function testCountIsTheSizeOfFindAll(string $class): void {
		$register = new Register();
		$schema   = new Schema();
		$provider = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['findAll'])->getMock();
		$provider->expects($this->once())->method('findAll')
			->with(new SourcedTable(new RegisterSchema($register, $schema), ['k' => 'v']), ['limit' => 2])
			->willReturn([new ObjectEntity(), new ObjectEntity(), new ObjectEntity()]);

		$this->assertSame(3, $provider->count(new SourcedTable(new RegisterSchema($register, $schema), ['k' => 'v']), ['limit' => 2]));
	}//end testCountIsTheSizeOfFindAll()

	/**
	 * Build Deck or Talk over the given app manager, container and logger.
	 *
	 * @param class-string       $class      The provider class.
	 * @param IAppManager        $appManager The app manager.
	 * @param ContainerInterface $container  The container.
	 * @param LoggerInterface    $logger     The logger.
	 *
	 * @return object The provider.
	 */
	private function build(string $class, IAppManager $appManager, ContainerInterface $container, LoggerInterface $logger): object {
		return new $class(new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $appManager, container: $container, logger: $logger), $this->createMock(\OCP\IUserSession::class));
	}//end build()

	/**
	 * isEnabled() follows the app install and is false when asking fails.
	 *
	 * @param class-string $class The provider class.
	 * @param string       $app   The app it needs.
	 *
	 * @return void
	 */
	#[DataProvider('appProviders')]
	public function testIsEnabledFollowsTheApp(string $class, string $app): void {
		$answers = [true, false, new RuntimeException('down')];
		$seen    = [];
		foreach ($answers as $answer) {
			$apps = $this->createMock(IAppManager::class);
			$call = $apps->method('isInstalled')->with($app);
			if ($answer instanceof RuntimeException) {
				$call->willThrowException($answer);
			} else {
				$call->willReturn($answer);
			}

			$seen[] = $this->build($class, $apps, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class))->isEnabled();
		}

		$this->assertSame([true, false, false], $seen);
	}//end testIsEnabledFollowsTheApp()

	/**
	 * resolveService(): null for an unknown class, the service when the
	 * container has it, null plus a warning naming the provider when it throws.
	 *
	 * @param class-string $class The provider class.
	 *
	 * @return void
	 */
	#[DataProvider('appProviders')]
	public function testResolveService(string $class): void {
		$id = (new ReflectionClass($class))->newInstanceWithoutConstructor()->getId();
		$this->assertServiceResolution(
			label: $id,
			build: fn (ContainerInterface $container, LoggerInterface $logger): object => $this->build($class, $this->createMock(IAppManager::class), $container, $logger)
		);
	}//end testResolveService()

	/**
	 * The Tables reader resolves its services the same way, labelled `tables`.
	 *
	 * @return void
	 */
	public function testTablesReaderResolvesServicesTheSameWay(): void {
		$this->assertServiceResolution(
			label: 'tables',
			build: fn (ContainerInterface $container, LoggerInterface $logger): object => new ObjectSource\TablesTableReader(app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $container, logger: $logger))
		);
	}//end testTablesReaderResolvesServicesTheSameWay()

	/**
	 * Null for an unknown class, the service when the container has it, null
	 * plus a warning carrying the label when the container throws.
	 *
	 * @param string   $label The log label.
	 * @param callable $build Builds the object over a container and a logger.
	 *
	 * @return void
	 */
	private function assertServiceResolution(string $label, callable $build): void {
		$service   = new \stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === \stdClass::class) {
					return $service;
				}

				throw new RuntimeException('no such service');
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[ObjectSource:'.$label.'] could not resolve '.ReflectionClass::class.': no such service');

		$provider = $build($container, $logger);
		$resolve  = new ReflectionMethod($provider, 'resolveService');

		$this->assertNull($resolve->invoke($provider, 'OCA\\Nope\\Missing'));
		$this->assertSame($service, $resolve->invoke($provider, \stdClass::class));
		$this->assertNull($resolve->invoke($provider, ReflectionClass::class));
	}//end assertServiceResolution()

	/**
	 * intGetter() and stringGetter() cast, pass null through, and swallow a failing getter.
	 *
	 * @param class-string $class The provider class.
	 *
	 * @return void
	 */
	#[DataProvider('appProviders')]
	public function testGetters(string $class): void {
		$provider = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$entity   = new class {
			public function getN(): string { return '42'; }
			public function getNone(): ?int { return null; }
			public function getS(): int { return 7; }
			public function getBoom(): string { throw new RuntimeException('x'); }
		};
		$int = new ReflectionMethod($provider, 'intGetter');
		$str = new ReflectionMethod($provider, 'stringGetter');

		$this->assertSame(
			[42, null, null, '7', '', ''],
			[
				$int->invoke($provider, $entity, 'getN'),
				$int->invoke($provider, $entity, 'getNone'),
				$int->invoke($provider, $entity, 'getBoom'),
				$str->invoke($provider, $entity, 'getS'),
				$str->invoke($provider, $entity, 'getBoom'),
				$str->invoke($provider, $entity, 'getMissing'),
			]
		);
	}//end testGetters()
}//end class
