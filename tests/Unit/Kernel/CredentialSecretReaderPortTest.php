<?php

/**
 * Every credential store answers the Core credential-reading port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
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

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\CredentialSecretReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\SourceInterface;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Service\Credential\NextcloudVaultCredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The DBAL connection factory (Core) reads secrets through the Core port; the
 * Integrations credential store extends it, so the resolved store still fits.
 */
final class CredentialSecretReaderPortTest extends TestCase {

	public function testTheCredentialStoreIsThePort(): void {
		$this->assertTrue(is_subclass_of(CredentialStore::class, CredentialSecretReaderInterface::class));
		$this->assertTrue(is_subclass_of(NextcloudVaultCredentialStore::class, CredentialSecretReaderInterface::class));
		$this->assertTrue(is_subclass_of(DoriathCredentialStore::class, CredentialSecretReaderInterface::class));
	}

	public function testTheStoreKeepsItsThreeMethods(): void {
		$methods = array_map(
			static fn ($method): string => $method->getName(),
			(new ReflectionClass(CredentialStore::class))->getMethods()
		);
		sort($methods);

		$this->assertSame(['delete', 'get', 'put'], $methods);
	}

	public function testTheFactoryNamesOnlyKernelContracts(): void {
		$constructor = (new ReflectionClass(DbalConnectionFactory::class))->getConstructor();
		$this->assertNotNull($constructor);
		$type = $constructor->getParameters()[0]->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(CredentialSecretReaderInterface::class, $type->getName());

		$source = (new ReflectionClass(DbalConnectionFactory::class))->getMethod('getConnection')->getParameters()[0]->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $source);
		$this->assertSame(SourceInterface::class, $source->getName());
	}
}//end class
