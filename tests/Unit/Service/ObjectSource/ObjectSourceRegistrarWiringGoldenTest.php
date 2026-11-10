<?php

/**
 * Every factory of the object source registrar hands each constructor argument the service it names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden record: per registered service, the container ids its factory asks for, in order,
 * and which of them lands in each constructor parameter.
 *
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistrar
 * @uses   \OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry
 * @uses   \OCA\OpenRegister\Service\ObjectSource\FederatedObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\UserDirectoryObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\GroupObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\ContactsObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\CalendarEventObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\FilesObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DeckObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\TalkObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver
 * @uses   \OCA\OpenRegister\Service\ObjectSource\TablesTableReader
 * @uses   \OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper
 * @uses   \OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService
 * @uses   \OCA\OpenRegister\Service\ObjectSource\TablesObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\Dbal\DbalConnectionFactory
 * @uses   \OCA\OpenRegister\Service\Dbal\SqlTypeMapper
 * @uses   \OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService
 */
class ObjectSourceRegistrarWiringGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-source-registrar-wiring.json';

	/**
	 * The wiring of every factory matches the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryFactoryWiresTheRecordedServices(): void {
		$context = new RecordingRegistrationContext();
		ObjectSourceRegistrar::register(context: $context);

		$actual = [];
		foreach ($context->factories as $name => $factory) {
			$actual[$name] = $this->wiringOf(factory: $factory);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testEveryFactoryWiresTheRecordedServices()

	/**
	 * Build one service with a fresh container and record what it asked for and where each answer went.
	 *
	 * @param callable $factory The registered factory.
	 *
	 * @return array{asked: list<string>, params: array<string, string>}
	 */
	private function wiringOf(callable $factory): array {
		$asked     = [];
		$byObject  = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked, &$byObject) {
				$asked[] = $id;
				$double  = $this->doubleFor(id: $id);
				$byObject[spl_object_id($double)] = $id;
				return $double;
			}
		);
		$byObject[spl_object_id($container)] = '(container)';

		$service = $factory($container);
		$params  = [];
		$class   = new ReflectionClass(objectOrClass: $service);
		foreach (($class->getConstructor()?->getParameters() ?? []) as $param) {
			$name = $param->getName();
			if ($class->hasProperty($name) === false) {
				$params[$name] = '(no property)';
				continue;
			}

			$value         = $class->getProperty($name)->getValue($service);
			$params[$name] = '(scalar)';
			if (is_object($value) === true) {
				$params[$name] = $byObject[spl_object_id($value)] ?? '(other)';
			}
		}

		return ['asked' => $asked, 'params' => $params];
	}//end wiringOf()

	/**
	 * A distinct test double for a service id; a final class is created without its constructor.
	 *
	 * @param string $id The service id.
	 *
	 * @return object
	 */
	private function doubleFor(string $id): object {
		$class = new ReflectionClass(objectOrClass: $id);
		if ($class->isFinal() === true) {
			return $class->newInstanceWithoutConstructor();
		}

		return $this->createMock(originalClassName: $id);
	}//end doubleFor()
}//end class
