<?php

/**
 * Unit tests for DbalConnectionFactory's driver check: a supported driver
 * whose PHP extension is missing is refused before DBAL is asked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Dbal\DbalConnectionFactory
 * @uses   \OCA\OpenRegister\Service\Dbal\DbalConnectionException
 * @uses   \OCA\OpenRegister\Db\Source
 */
class DbalConnectionFactoryDriverTest extends TestCase {

	/**
	 * A factory whose driver extension check answers as given.
	 *
	 * @param bool                 $available What the extension check answers.
	 * @param CredentialStore|null $store     The credential store.
	 *
	 * @return DbalConnectionFactory
	 */
	private function factory(bool $available, ?CredentialStore $store = null): DbalConnectionFactory {
		$factory = $this->getMockBuilder(DbalConnectionFactory::class)
			->setConstructorArgs([$store ?? $this->createMock(CredentialStore::class), $this->createMock(LoggerInterface::class)])
			->onlyMethods(['isDriverAvailable'])
			->getMock();
		$factory->method('isDriverAvailable')->willReturn($available);

		return $factory;
	}//end factory()

	/**
	 * A database source with this configuration.
	 *
	 * @param array<string, mixed> $config The source configuration.
	 *
	 * @return Source
	 */
	private function source(array $config): Source {
		$source = new Source();
		$source->setId(3);
		$source->setUuid('src-3');
		$source->setType('database');
		$source->setAuthConfig($config);

		return $source;
	}//end source()

	public function testASupportedDriverWithoutItsExtensionIsRefused(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->never())->method($this->anything());

		try {
			$this->factory(false, $store)->getConnection($this->source(['driver' => 'pdo_pgsql', 'host' => 'db', 'dbname' => 'x', 'credential' => 'cred-1']));
			$this->fail('A missing driver extension must be refused.');
		} catch (DbalConnectionException $e) {
			$this->assertSame('The database driver extension is not available on this instance.', $e->getMessage());
		}
	}//end testASupportedDriverWithoutItsExtensionIsRefused()

	public function testAnUnsupportedDriverIsRefusedBeforeTheExtensionIsAsked(): void {
		$factory = $this->getMockBuilder(DbalConnectionFactory::class)
			->setConstructorArgs([$this->createMock(CredentialStore::class), $this->createMock(LoggerInterface::class)])
			->onlyMethods(['isDriverAvailable'])
			->getMock();
		$factory->expects($this->never())->method('isDriverAvailable');

		$this->expectException(DbalConnectionException::class);
		$this->expectExceptionMessage('Unsupported or missing database driver for the source.');

		$factory->getConnection($this->source(['driver' => 'oci8', 'host' => 'db', 'dbname' => 'x']));
	}//end testAnUnsupportedDriverIsRefusedBeforeTheExtensionIsAsked()
}//end class
