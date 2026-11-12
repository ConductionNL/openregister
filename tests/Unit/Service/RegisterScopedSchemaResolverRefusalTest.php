<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Error;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins what the two refusals of the register-scoped resolver carry.
 *
 * @covers \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 */
class RegisterScopedSchemaResolverRefusalTest extends TestCase {

	/**
	 * An unlisted slug is refused with the register, the slug, the count elsewhere and the register's schema count.
	 *
	 * @return void
	 */
	public function testTheNotInRegisterRefusalCarriesItsContext(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('countBySlug')->willReturnCallback(static fn (string $slug): int => ($slug === 'TimeEntry' ? 3 : 0));

		$register = new Register();
		$register->setId(18);
		$register->setSlug('hrmq');
		$register->setSchemas([1, 2]);

		try {
			(new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper))->resolveSchemaWithin($register, 'TimeEntry');
			$this->fail('expected a refusal');
		} catch (SchemaNotInRegisterException $e) {
			$this->assertSame(
				[18, 'hrmq', 'TimeEntry', 3, 2],
				[$e->getRegisterId(), $e->getRegisterSlug(), $e->getSchemaSlug(), $e->getCandidatesElsewhere(), $e->getRegisterSchemaCount()]
			);
		}
	}//end testTheNotInRegisterRefusalCarriesItsContext()

	/**
	 * A register that does not resolve is refused; an Exception is chained, an Error is not.
	 *
	 * @return void
	 */
	public function testTheRegisterNotFoundRefusalChainsOnlyExceptions(): void {
		$exception = new RuntimeException('db down');
		foreach ([[$exception, $exception], [new Error('fatal'), null]] as [$thrown, $previous]) {
			$registerMapper = $this->createMock(RegisterMapper::class);
			$registerMapper->method('find')->willThrowException($thrown);

			try {
				(new RegisterScopedSchemaResolver($registerMapper, $this->createMock(SchemaMapper::class)))->resolveRegister('hrmq');
				$this->fail('expected a refusal');
			} catch (RegisterNotFoundException $e) {
				$this->assertSame($previous, $e->getPrevious());
				$this->assertSame(404, $e->getCode());
				$this->assertStringContainsString('hrmq', $e->getMessage());
			}
		}
	}//end testTheRegisterNotFoundRefusalChainsOnlyExceptions()
}//end class
