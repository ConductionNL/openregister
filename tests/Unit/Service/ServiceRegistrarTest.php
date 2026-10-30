<?php

/**
 * ServiceRegistrar registers the request-scoped services, the seams and the hand-built object services in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ServiceRegistrar;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Tests\Unit\AppHost\FactoryWiringAssertions;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

class ServiceRegistrarTest extends TestCase {
	use FactoryWiringAssertions;

	/**
	 * Optional dependencies these factories leave out on purpose.
	 *
	 * @var array<string,string>
	 */
	private const LEFT_OUT = [
		'OCA\OpenRegister\Service\OrganisationService::$settingsService'      => 'No construction cycle today. Left out because wiring it would switch the'
			.' default-organisation key from `defaultOrganisation` to `organisation.default_organisation`;'
			.' the two keys already diverge (see the comment on the factory).',
		'OCA\OpenRegister\Service\File\FolderManagementHandler::$fileService' => 'Set by FileService::__construct() through setFileService():'
			.' FileService takes this handler, so constructor injection would be a cycle.',
	];

	/**
	 * Optional dependencies stored under another property name.
	 *
	 * @var array<string,string>
	 */
	private const HELD_BY = [
		'OCA\OpenRegister\Service\Object\CacheHandler::$cacheFactory' => 'queryCache',
	];

	/**
	 * register() registers the request-scoped services, the seams and the hand-built object services, in this order.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		ServiceRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\LanguageService',
				'OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext',
				'OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass',
				'OCA\OpenRegister\Service\RegisterSlugResolver',
				'OCA\OpenRegister\Contract\RegisterSlugResolverInterface',
				'OCA\OpenRegister\Contract\EmailLinkReaderInterface',
				'OCA\OpenRegister\Service\Credential\CredentialStore',
				'OCA\OpenRegister\Service\Translation\TranslationProviderInterface',
				'OCA\OpenRegister\Service\File\Pdf\Fallback\NcOfficeConverterInterface',
				'OCA\OpenRegister\Service\WebPush\HexIconService',
				'OCA\OpenRegister\Service\Sync\SourceFetcherRegistry',
				'OCA\OpenRegister\Service\SchemaImport\SchemaImportService',
				'OCA\OpenRegister\Service\OrganisationService',
				'OCA\OpenRegister\Service\UserService',
				'OCA\OpenRegister\Service\Task\TaskInboxService',
				'OCA\OpenRegister\Service\Portal\PortalPartyResolver',
				'OCA\OpenRegister\Service\Object\CacheHandler',
				'OCA\OpenRegister\Service\File\FolderManagementHandler',
				'OCA\OpenRegister\Service\VectorizationService',
				'OCA\OpenRegister\Service\TaskService',
				'OCA\OpenRegister\Service\NoteService',
				'OCA\OpenRegister\Service\Integration\TimeProvider',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for; a final class it cannot double is handed over unconstructed. The
	 * cache handler stores what the cache factory builds as a memcache. Each
	 * built service must also hold every nullable object dependency its
	 * constructor declares, except those on LEFT_OUT.
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		ServiceRegistrar::register(context: $context);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(originalClassName: IMemcache::class));

		$doubles = ['OCP\ICacheFactory' => $cacheFactory];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles) {
				if (isset($doubles[$id]) === false) {
					$class = new ReflectionClass(objectOrClass: $id);
					$doubles[$id] = $class->isFinal() === true ? $class->newInstanceWithoutConstructor() : $this->createMock(originalClassName: $id);
				}

				return $doubles[$id];
			}
		);

		foreach ($context->factories as $name => $factory) {
			$service = $factory($container);
			$this->assertInstanceOf(expected: $name, actual: $service, message: $name);
			$this->assertFactorySuppliedEveryOptionalDependency(service: $service, allowList: self::LEFT_OUT, properties: self::HELD_BY);
		}
	}//end testEveryFactoryBuildsItsService()

	/**
	 * TaskService is still built when the container cannot build its write-back
	 * gate; it then refuses updates of projected VTODOs.
	 *
	 * @return void
	 */
	public function testTaskServiceIsBuiltWithoutTheWriteBackGate(): void {
		$context = new RecordingRegistrationContext();
		ServiceRegistrar::register(context: $context);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($logger) {
				if ($id === TaskVtodoWriteBackGate::class) {
					throw new RuntimeException(message: 'not buildable');
				}

				return $id === 'Psr\Log\LoggerInterface' ? $logger : $this->createMock(originalClassName: $id);
			}
		);

		$this->assertInstanceOf(
			expected: TaskService::class,
			actual: $context->factories[TaskService::class]($container)
		);
	}//end testTaskServiceIsBuiltWithoutTheWriteBackGate()
}//end class
