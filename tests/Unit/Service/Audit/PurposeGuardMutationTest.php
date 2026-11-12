<?php

/**
 * Mutation-killing tests for the doelbinding guard.
 *
 * Written against the mutants Infection left alive in PurposeGuard: the
 * warning that makes the unsatisfiable-annotation hole visible, the refusal
 * row written for a read with only a schema or only a register, the error
 * logged when that row cannot be written, and the normalisation of the string
 * form of the annotation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail\PurposeRefusal;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeContext;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PurposeGuardMutationTest extends TestCase {
	/**
	 * Test double or fixture for registry.
	 *
	 * @var PurposeRegistry&MockObject
	 */
	private PurposeRegistry&MockObject $registry;

	/**
	 * Test double or fixture for audit.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $audit;

	/**
	 * Test double or fixture for logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->registry = $this->createMock(PurposeRegistry::class);
		$this->audit    = $this->createMock(AuditTrailMapperInterface::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function guard(): PurposeGuard {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);

		return new PurposeGuard(
			$this->registry,
			new PurposeContext($request),
			$this->audit,
			$this->logger
		);
	}//end guard()

	private function schema(int $id, mixed $annotation): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setConfiguration([PurposeGuard::ANNOTATION => $annotation]);

		return $schema;
	}//end schema()

	private function register(int $id, mixed $annotation): Register {
		$register = new Register();
		$register->setId($id);
		$register->setConfiguration([PurposeGuard::ANNOTATION => $annotation]);

		return $register;
	}//end register()

	private function refusing(): void {
		$this->registry->method('hasAdministeredPurposes')->willReturn(true);
		$this->registry->method('requirePurpose')->willThrowException(
			new PurposeRefusedException(
				rule: PurposeRefusedException::RULE_MISSING,
				reason: 'no purpose',
				purpose: null
			)
		);
	}//end refusing()

	public function testUnsatisfiableAnnotationIsLoggedWithBothIds(): void {
		$this->registry->method('hasAdministeredPurposes')->willReturn(false);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('administers none'),
				['schema' => 41, 'register' => 7]
			);

		$this->assertFalse($this->guard()->isRequired($this->register(7, true), $this->schema(41, true)));
	}//end testUnsatisfiableAnnotationIsLoggedWithBothIds()

	public function testUnsatisfiableRegisterAnnotationWithoutSchemaIsLogged(): void {
		$this->registry->method('hasAdministeredPurposes')->willReturn(false);
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->anything(), ['schema' => null, 'register' => 7]);

		$this->assertFalse($this->guard()->isRequired($this->register(7, true), null));
	}//end testUnsatisfiableRegisterAnnotationWithoutSchemaIsLogged()

	public function testAdministeredInstanceDoesNotWarn(): void {
		$this->registry->method('hasAdministeredPurposes')->willReturn(true);
		$this->logger->expects($this->never())->method('warning');

		$this->assertTrue($this->guard()->isRequired($this->register(7, true), $this->schema(41, true)));
	}//end testAdministeredInstanceDoesNotWarn()

	public function testRefusalWithoutRegisterIsRecordedForTheSchema(): void {
		$this->refusing();
		$this->audit->expects($this->once())
			->method('recordPurposeRefusal')
			->with(new PurposeRefusal(PurposeRefusedException::RULE_MISSING, null, new AuditedObject(null, 41)))
			->willReturn(new AuditTrail());
		$this->logger->expects($this->never())->method('error');

		$this->expectException(PurposeRefusedException::class);
		$this->guard()->enforce(null, $this->schema(41, true));
	}//end testRefusalWithoutRegisterIsRecordedForTheSchema()

	public function testRefusalWithoutSchemaIsRecordedForTheRegister(): void {
		$this->refusing();
		$this->audit->expects($this->once())
			->method('recordPurposeRefusal')
			->with(new PurposeRefusal(PurposeRefusedException::RULE_MISSING, null, new AuditedObject(7, null)))
			->willReturn(new AuditTrail());
		$this->logger->expects($this->never())->method('error');

		$this->expectException(PurposeRefusedException::class);
		$this->guard()->enforce($this->register(7, true), null);
	}//end testRefusalWithoutSchemaIsRecordedForTheRegister()

	public function testUnrecordableRefusalIsLoggedAndStillRefuses(): void {
		$this->refusing();
		$this->audit->method('recordPurposeRefusal')
			->willThrowException(new RuntimeException('trail down'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('could not be recorded'),
				['rule' => PurposeRefusedException::RULE_MISSING, 'error' => 'trail down']
			);

		$this->expectException(PurposeRefusedException::class);
		$this->guard()->enforce($this->register(7, true), $this->schema(41, true));
	}//end testUnrecordableRefusalIsLoggedAndStillRefuses()

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function stringForms(): array {
		return [
			'padded true'     => [' true ', true],
			'upper TRUE'      => ['TRUE', true],
			'mixed Yes'       => ['Yes', true],
			'padded 1'        => ["1\n", true],
			'false'           => ['false', false],
			'padded no'       => [' no ', false],
		];
	}//end stringForms()

	#[DataProvider('stringForms')]
	public function testStringAnnotationIsNormalised(string $annotation, bool $required): void {
		$this->registry->method('hasAdministeredPurposes')->willReturn(true);

		$this->assertSame($required, $this->guard()->isRequired(null, $this->schema(41, $annotation)));
	}//end testStringAnnotationIsNormalised()
}//end class
