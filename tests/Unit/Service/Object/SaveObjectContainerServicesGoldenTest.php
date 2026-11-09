<?php

/**
 * Golden tests for the services SaveObject resolves lazily from the app container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Quality\UniqueHintChecker;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * Pins the four lazy resolvers: no container, a found service, a container that throws, and a wrong type.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 */
class SaveObjectContainerServicesGoldenTest extends TestCase {

	/** @var list<string> */
	private array $debug = [];

	/**
	 * The resolvers: method, container id, log label.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function resolvers(): array {
		return [
			'shared master data' => ['resolveSharedMasterDataService', SharedMasterDataService::class, 'SharedMasterDataService'],
			'retention' => ['resolveRetentionService', RetentionServiceInterface::class, 'RetentionService'],
			'unique hints' => ['resolveUniqueHintChecker', UniqueHintChecker::class, 'UniqueHintChecker'],
			'dedup policy' => ['resolveDedupCreatePolicy', DedupCreatePolicyInterface::class, 'DedupCreatePolicy'],
		];
	}//end resolvers()

	/**
	 * Call a resolver on a SaveObject with the given container.
	 *
	 * @param string                  $method    The resolver.
	 * @param ContainerInterface|null $container The container.
	 *
	 * @return mixed
	 */
	private function resolve(string $method, ?ContainerInterface $container): mixed {
		$this->debug = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function ($message): void {
				$this->debug[] = (string) $message;
			}
		);
		$handler = new SaveObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(ComputedFieldHandler::class),
			$this->createMock(TranslationHandler::class),
			$this->createMock(TranslationProjectionService::class),
			$this->createMock(TranslationStatusService::class),
			$logger,
			$this->createMock(TmloService::class),
			$this->createMock(FolderManagementHandler::class),
			$this->createMock(PermissionHandler::class),
			container: $container,
		);

		return (new ReflectionMethod(SaveObject::class, $method))->invoke($handler);
	}//end resolve()

	/**
	 * A container that answers one id with the given value or exception.
	 *
	 * @param string $id     The id it is asked for.
	 * @param mixed  $answer The value, or a Throwable to throw.
	 *
	 * @return ContainerInterface
	 */
	private function container(string $id, mixed $answer): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with($id)->willReturnCallback(
			static function () use ($answer) {
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		return $container;
	}//end container()

	/**
	 * @dataProvider resolvers
	 */
	public function testNoContainerResolvesNothingAndLogsNothing(string $method, string $id, string $label): void {
		$this->assertNull($this->resolve($method, null));
		$this->assertSame([], $this->debug);
	}//end testNoContainerResolvesNothingAndLogsNothing()

	/**
	 * @dataProvider resolvers
	 */
	public function testTheContainersServiceIsReturnedAsIs(string $method, string $id, string $label): void {
		$service = $this->createMock($id);
		$this->assertSame($service, $this->resolve($method, $this->container($id, $service)));
		$this->assertSame([], $this->debug);
	}//end testTheContainersServiceIsReturnedAsIs()

	/**
	 * @dataProvider resolvers
	 */
	public function testAFailingContainerResolvesNothingAndLogsWhy(string $method, string $id, string $label): void {
		$this->assertNull($this->resolve($method, $this->container($id, new RuntimeException('boom'))));
		$this->assertSame(['[SaveObject] ' . $label . ' not available: boom'], $this->debug);
	}//end testAFailingContainerResolvesNothingAndLogsWhy()

	/**
	 * @dataProvider resolvers
	 */
	public function testAnAnswerOfTheWrongTypeResolvesNothing(string $method, string $id, string $label): void {
		$this->assertNull($this->resolve($method, $this->container($id, new stdClass())));
		$this->assertSame([], $this->debug);
	}//end testAnAnswerOfTheWrongTypeResolvesNothing()
}//end class
