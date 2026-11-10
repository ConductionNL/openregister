<?php

/**
 * Golden test for DeepLinkRegistryService registration and slug maps.
 *
 * Pins, on the code as it was before the refactor, the debug log lines of a
 * first and a duplicate registration, the default icon, and how the id to
 * slug maps skip blank slugs and keep what was read before a failure.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Dto\DeepLinkRegistration;

/**
 * Golden outcomes of DeepLinkRegistryService::register() and the slug maps.
 */
class DeepLinkRegistryRegisterGoldenTest extends TestCase {

	/**
	 * Log lines as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	protected function setUp(): void {
		DeepLinkRegistryService::reset();
		$this->logs = [];
	}//end setUp()

	protected function tearDown(): void {
		DeepLinkRegistryService::reset();
	}//end tearDown()

	/**
	 * @param array<int, Register>|Exception $registers Rows, or the exception findAll throws.
	 * @param array<int, Schema>|Exception   $schemas   Rows, or the exception findAll throws.
	 *
	 * @return DeepLinkRegistryService
	 */
	private function service(array|Exception $registers = [], array|Exception $schemas = []): DeepLinkRegistryService {
		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$schemaMapper   = $this->createMock(SchemaMapperInterface::class);
		foreach ([[$registerMapper, $registers], [$schemaMapper, $schemas]] as [$mapper, $rows]) {
			if ($rows instanceof Exception) {
				$mapper->method('findAll')->willThrowException($rows);
				continue;
			}

			$mapper->method('findAll')->willReturn($rows);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[RegisterMapperInterface::class, $registerMapper],
				[SchemaMapperInterface::class, $schemaMapper],
			]
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new DeepLinkRegistryService($container, $logger);
	}//end service()

	private function register(int $id, ?string $slug): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		return $register;
	}//end register()

	private function schema(int $id, ?string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	public function testAFirstRegistrationIsStoredAndADuplicateIsLoggedAndIgnored(): void {
		$service = $this->service(registers: [$this->register(1, 'cases')], schemas: [$this->schema(2, 'case')]);

		$service->add(new DeepLinkRegistration('procest', 'cases', 'case', '/apps/procest/#/{uuid}'));
		$service->add(new DeepLinkRegistration('other', 'cases', 'case', '/apps/other/{uuid}', 'icon-x', 'Other'));

		$this->assertSame(
			[
				[
					'debug',
					'[DeepLinkRegistry] Registered deep link for {key} → {appId} ({template})',
					['key' => 'cases::case', 'appId' => 'procest', 'template' => '/apps/procest/#/{uuid}'],
				],
				[
					'debug',
					'[DeepLinkRegistry] Ignoring duplicate registration for {key} from {appId} (already claimed by {existing})',
					['key' => 'cases::case', 'appId' => 'other', 'existing' => 'procest'],
				],
			],
			$this->logs
		);

		$registration = $service->resolve(1, 2);
		$this->assertNotNull($registration);
		$this->assertSame(
			['procest', 'cases', 'case', '/apps/procest/#/{uuid}', 'icon-procest', null],
			[
				$registration->appId,
				$registration->registerSlug,
				$registration->schemaSlug,
				$registration->urlTemplate,
				$registration->icon,
				$registration->displayName,
			]
		);
	}//end testAFirstRegistrationIsStoredAndADuplicateIsLoggedAndIgnored()

	public function testAGivenIconAndDisplayNameAreKept(): void {
		$service = $this->service(registers: [$this->register(1, 'r')], schemas: [$this->schema(2, 's')]);
		$service->add(new DeepLinkRegistration('app', 'r', 's', '/u', 'icon-custom', 'Shown'));

		$this->assertSame('icon-custom', $service->resolveIcon(1, 2));
		$this->assertSame('Shown', $service->resolveDisplayName(1, 2));
	}//end testAGivenIconAndDisplayNameAreKept()

	public function testBlankSlugsAreNotMapped(): void {
		$service = $this->service(
			registers: [$this->register(1, null), $this->register(3, ''), $this->register(5, 'r')],
			schemas: [$this->schema(2, ''), $this->schema(4, null), $this->schema(6, 's')]
		);
		$service->add(new DeepLinkRegistration('app', 'r', 's', '/u'));
		$service->add(new DeepLinkRegistration('blank', '', '', '/b'));

		$this->assertNotNull($service->resolve(5, 6));
		$this->assertNull($service->resolve(3, 2));
		$this->assertNull($service->resolve(1, 6));
		$this->assertNull($service->resolve(3, 6));
		$this->assertNull($service->resolve(5, 2));
		$this->assertNull($service->resolve(5, 4));
	}//end testBlankSlugsAreNotMapped()

	public function testAFailedRegisterReadIsLoggedAndTheSchemasAreStillRead(): void {
		$service = $this->service(registers: new Exception('db down'), schemas: [$this->schema(2, 's')]);
		$service->add(new DeepLinkRegistration('app', 'r', 's', '/u'));
		$this->logs = [];

		$this->assertNull($service->resolve(1, 2));
		$this->assertSame(
			[
				[
					'warning',
					'[DeepLinkRegistry] Failed to load registers for slug resolution: {error}',
					['error' => 'db down'],
				],
			],
			$this->logs
		);
	}//end testAFailedRegisterReadIsLoggedAndTheSchemasAreStillRead()

	public function testAFailedSchemaReadIsLoggedAndTheMapsAreBuiltOnce(): void {
		$service = $this->service(registers: [$this->register(1, 'r')], schemas: new Exception('gone'));
		$service->add(new DeepLinkRegistration('app', 'r', 's', '/u'));
		$this->logs = [];

		$this->assertNull($service->resolve(1, 2));
		$this->assertNull($service->resolve(1, 2));
		$this->assertSame(
			[['warning', '[DeepLinkRegistry] Failed to load schemas for slug resolution: {error}', ['error' => 'gone']]],
			$this->logs
		);
	}//end testAFailedSchemaReadIsLoggedAndTheMapsAreBuiltOnce()

	public function testRegistersReadBeforeAFailureStayMapped(): void {
		$good = $this->register(1, 'r');
		$bad  = new class extends Register {
			// phpcs:ignore PEAR.Commenting.FunctionComment.Missing -- a row whose slug cannot be read.
			public function getSlug(): ?string {
				throw new Exception('broken row');
			}
		};

		$service = $this->service(registers: [$good, $bad], schemas: [$this->schema(2, 's')]);
		$service->add(new DeepLinkRegistration('app', 'r', 's', '/u'));
		$this->logs = [];

		$this->assertNotNull($service->resolve(1, 2));
		$this->assertSame(
			[['warning', '[DeepLinkRegistry] Failed to load registers for slug resolution: {error}', ['error' => 'broken row']]],
			$this->logs
		);
	}//end testRegistersReadBeforeAFailureStayMapped()

	public function testResolveUrlFillsTheTemplateOrReturnsNull(): void {
		$service = $this->service(registers: [$this->register(1, 'r')], schemas: [$this->schema(2, 's')]);
		$service->add(new DeepLinkRegistration('app', 'r', 's', '/apps/app/{uuid}'));

		$this->assertSame('/apps/app/abc', $service->resolveUrl(1, 2, ['uuid' => 'abc']));
		$this->assertNull($service->resolveUrl(1, 9, ['uuid' => 'abc']));
	}//end testResolveUrlFillsTheTemplateOrReturnsNull()
}//end class
