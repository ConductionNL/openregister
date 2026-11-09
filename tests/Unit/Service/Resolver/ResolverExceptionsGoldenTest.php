<?php

/**
 * Pins what each resolver exception carries: message, code, previous and context.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Resolver
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Resolver;

use Exception;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCA\OpenRegister\Service\Resolver\Exception\PropertyNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\ResolverConfigKey;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException
 * @covers \OCA\OpenRegister\Service\Resolver\Exception\PropertyNotFoundException
 * @covers \OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException
 * @covers \OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException
 */
final class ResolverExceptionsGoldenTest extends TestCase {

	/**
	 * A schema that does not resolve answers 404 with its context.
	 *
	 * @return void
	 */
	public function testSchemaNotFound(): void {
		$e = new SchemaNotFoundException('opencatalogi', 'theme_schema', 'theme');

		$this->assertInstanceOf(Exception::class, $e);
		$this->assertSame('Schema "theme" (from config key "theme_schema" on app "opencatalogi") not found in caller tenant.', $e->getMessage());
		$this->assertSame(404, $e->getCode());
		$this->assertNull($e->getPrevious());
		$this->assertSame('opencatalogi', $e->getAppId());
		$this->assertSame('theme_schema', $e->getConfigKey());
		$this->assertSame('theme', $e->getResolvedValue());
	}//end testSchemaNotFound()

	/**
	 * A register that does not resolve answers 404 and keeps the previous exception.
	 *
	 * @return void
	 */
	public function testRegisterNotFound(): void {
		$previous = new Exception('lookup failed');
		$e        = new RegisterNotFoundException('opencatalogi', 'theme_register', 'publication', $previous);

		$this->assertSame('Register "publication" (from config key "theme_register" on app "opencatalogi") not found in caller tenant.', $e->getMessage());
		$this->assertSame(404, $e->getCode());
		$this->assertSame($previous, $e->getPrevious());
		$this->assertSame('opencatalogi', $e->getAppId());
		$this->assertSame('theme_register', $e->getConfigKey());
		$this->assertSame('publication', $e->getResolvedValue());
	}//end testRegisterNotFound()

	/**
	 * A property that does not resolve names the schema it was looked for on.
	 *
	 * @return void
	 */
	public function testPropertyNotFound(): void {
		$e = new PropertyNotFoundException(new ResolverConfigKey('openconnector', 'title_property'), 'title', 'publication');

		$this->assertSame('Property "title" (from config key "title_property" on app "openconnector") not found on schema "publication".', $e->getMessage());
		$this->assertSame(404, $e->getCode());
		$this->assertNull($e->getPrevious());
		$this->assertSame('openconnector', $e->getAppId());
		$this->assertSame('title_property', $e->getConfigKey());
		$this->assertSame('title', $e->getResolvedValue());
		$this->assertSame('publication', $e->getSchemaIdentifier());
	}//end testPropertyNotFound()

	/**
	 * An unset config key answers 500.
	 *
	 * @return void
	 */
	public function testMissingConfig(): void {
		$previous = new Exception('unset');
		$e        = new MissingConfigException('opencatalogi', 'theme_register', $previous);

		$this->assertSame('Resolver config key "theme_register" is not set for app "opencatalogi" and no default was provided.', $e->getMessage());
		$this->assertSame(500, $e->getCode());
		$this->assertSame($previous, $e->getPrevious());
		$this->assertSame('opencatalogi', $e->getAppId());
		$this->assertSame('theme_register', $e->getConfigKey());
	}//end testMissingConfig()
}//end class
