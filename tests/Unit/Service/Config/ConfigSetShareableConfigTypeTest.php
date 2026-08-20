<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\Config\Types\ConfigSetShareableConfigType;
use OCA\OpenRegister\Service\ConfigurationService;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class ConfigSetShareableConfigTypeTest extends TestCase {
	private ConfigurationMapper $mapper;

	private ConfigurationService $configService;

	private ConfigSetShareableConfigType $type;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ConfigurationMapper::class);
		$this->configService = $this->createMock(ConfigurationService::class);
		$this->type = new ConfigSetShareableConfigType($this->mapper, $this->configService);
	}

	public function testIdentity(): void {
		$this->assertSame('openregister.configset', $this->type->getId());
		$this->assertSame('openregister-configset', $this->type->getTopic());
		$this->assertSame('Configuration set', $this->type->getDisplayName());
	}

	public function testSerialiseByConfigurationIdExportsTheSet(): void {
		$config = $this->createMock(Configuration::class);
		$this->mapper->expects($this->once())->method('find')->with(5)->willReturn($config);
		$this->configService->expects($this->once())->method('exportConfig')
			->with($config, false)
			->willReturn(['openapi' => '3.0.0', 'components' => ['registers' => ['a' => []]]]);

		$bundle = $this->type->serialise(['configuration' => '5']);
		$this->assertSame('3.0.0', $bundle['openapi']);
	}

	public function testSerialiseByAppUsesTheFirstMatch(): void {
		$config = $this->createMock(Configuration::class);
		$this->mapper->expects($this->once())->method('findByApp')->with('openbuild')->willReturn([$config]);
		$this->configService->expects($this->once())->method('exportConfig')
			->with($config, true)
			->willReturn(['openapi' => '3.0.0']);

		$bundle = $this->type->serialise(['app' => 'openbuild', 'includeObjects' => true]);
		$this->assertSame('3.0.0', $bundle['openapi']);
	}

	public function testSerialiseWithoutASelectionThrows(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->type->serialise([]);
	}

	public function testSerialiseForAnUnknownAppThrows(): void {
		$this->mapper->method('findByApp')->willReturn([]);
		$this->expectException(UnexpectedValueException::class);
		$this->type->serialise(['app' => 'nope']);
	}

	public function testDeserialiseImportsTheSet(): void {
		$bundle = ['info' => ['version' => '2.1.0'], 'components' => ['registers' => []]];
		$this->configService->expects($this->once())->method('importFromJson')
			->with($bundle, null, null, null, '2.1.0', false)
			->willReturn(['registers' => 1, 'schemas' => 3]);

		$result = $this->type->deserialise($bundle);
		$this->assertSame(3, $result['schemas']);
	}
}
