<?php

/**
 * The DBAL object source (Core) names only Kernel contracts for its source and its credentials.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Kernel\Contracts\Core\CredentialSecretReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\SourceInterface;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceWiring;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The provider types its backing source against the Kernel source contract, and
 * the connection factory is built with the Core credential reader port, which
 * Integrations binds to the active credential store.
 */
final class DbalSourceKernelContractsTest extends TestCase {

	public function testTheFactoryIsBuiltWithTheCredentialReaderPort(): void {
		$services = (new ReflectionClass(ObjectSourceWiring::class))->getConstant('SERVICES');

		$this->assertSame(CredentialSecretReaderInterface::class, $services[DbalConnectionFactory::class]['credentialStore']);
	}

	public function testIntegrationsBindsThePortToTheCredentialStore(): void {
		$this->assertSame(CredentialStore::class, IntegrationWiring::BINDINGS[CredentialSecretReaderInterface::class]);
		$this->assertTrue(is_subclass_of(CredentialStore::class, CredentialSecretReaderInterface::class));
	}

	public function testEverySourceParameterIsTheKernelContract(): void {
		$checked = 0;
		foreach ((new ReflectionClass(DbalObjectSourceProvider::class))->getMethods() as $method) {
			foreach ($method->getParameters() as $parameter) {
				$type = $parameter->getType();
				if ($type instanceof ReflectionNamedType === false) {
					continue;
				}

				$this->assertNotSame(Source::class, $type->getName(), $method->getName());
				if ($type->getName() === SourceInterface::class) {
					$checked++;
				}
			}
		}

		$this->assertSame(7, $checked);
	}

	public function testOnlyADatabaseSourceIsAccepted(): void {
		$check = new ReflectionMethod(DbalObjectSourceProvider::class, 'isDatabaseSource');

		$database = new Source();
		$database->setType('database');
		$rest = new Source();
		$rest->setType('api');

		$this->assertTrue($check->invoke(null, $database));
		$this->assertFalse($check->invoke(null, $rest));
		$this->assertFalse($check->invoke(null, null));
	}

	public function testAnyKernelSourceOfTypeDatabaseIsAccepted(): void {
		$check  = new ReflectionMethod(DbalObjectSourceProvider::class, 'isDatabaseSource');
		$source = new class implements SourceInterface {

			public function getType(): string {
				return 'database';
			}

			public function jsonSerialize(): array {
				return [];
			}
		};

		$this->assertTrue($check->invoke(null, $source));
	}
}//end class
