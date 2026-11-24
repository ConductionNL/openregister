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
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class ServiceRegistrarTest extends TestCase {

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
				'OCA\OpenRegister\Service\RegisterSlugResolver',
				'OCA\OpenRegister\Contract\RegisterSlugResolverInterface',
				'OCA\OpenRegister\Contract\ObjectFileReaderInterface',
				'OCA\OpenRegister\Service\SchemaImport\SchemaImportService',
				'OCA\OpenRegister\Service\OrganisationService',
				'OCA\OpenRegister\Service\UserService',
				'OCA\OpenRegister\Service\Object\CacheHandler',
				'OCA\OpenRegister\Service\File\FolderManagementHandler',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for; a final class it cannot double is handed over unconstructed. The
	 * cache handler stores what the cache factory builds as a memcache.
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
			$this->assertInstanceOf(expected: $name, actual: $factory($container), message: $name);
		}
	}//end testEveryFactoryBuildsItsService()

	/**
	 * The office converter seam points at the converter that uses Nextcloud's conversion API.
	 *
	 * @return void
	 */
	public function testTheOfficeConverterIsTheConversionManagerConverter(): void {
		$context = new RecordingRegistrationContext();
		\OCA\OpenRegister\Files\FilesRegistrar::register(context: $context);

		$this->assertContains(
			needle: [
				'alias'  => 'OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface',
				'target' => 'OCA\OpenRegister\Files\Service\File\Office\ConversionManagerConverter',
			],
			haystack: $context->aliases
		);
	}//end testTheOfficeConverterIsTheConversionManagerConverter()
}//end class
