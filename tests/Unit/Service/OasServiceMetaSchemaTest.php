<?php

/**
 * Unit tests for the OpenAPI 3.1 meta-schema pass and the logging of validation issues.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\OasSources;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Meta-schema violations become report errors, are logged once each, and make a strict run fail;
 * a validator that throws never blocks generation.
 *
 * @covers \OCA\OpenRegister\Service\OasService
 */
class OasServiceMetaSchemaTest extends TestCase {

	/**
	 * The logger, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The meta-schema validator, mocked.
	 *
	 * @var OasRequestValidator&MockObject
	 */
	private OasRequestValidator&MockObject $validator;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->validator = $this->createMock(OasRequestValidator::class);

	}//end setUp()

	/**
	 * A service over no registers, with the validator and logger.
	 *
	 * @param bool          $withValidator Whether to hand it the meta-schema validator.
	 * @param list<Register> $registerList  The registers.
	 * @param list<Schema>   $schemaList    The schemas the mapper returns.
	 *
	 * @return OasService
	 */
	private function service(bool $withValidator = true, array $registerList = [], array $schemaList = []): OasService {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('findAll')->willReturn($registerList);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findMultiple')->willReturn($schemaList);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://or.example' . $path);

		return new OasService(
			new OasSources($registers, $schemas, $urls, $this->logger),
			$withValidator === true ? $this->validator : null
		);

	}//end service()

	/**
	 * The validator is asked about the generated document against the vendored 3.1 meta-schema.
	 *
	 * @return void
	 */
	public function testTheDocumentIsValidatedAgainstTheVendoredMetaSchema(): void {
		$this->validator->expects($this->once())
			->method('validate')
			->with(
				$this->callback(static fn (array $oas): bool => $oas['servers'][0]['url'] === 'https://or.example/apps/openregister/api'),
				$this->callback(static fn (array $meta): bool => str_contains((string)($meta['$id'] ?? ''), 'spec.openapis.org/oas/3.1'))
			)
			->willReturn([]);

		$this->service()->createOas();

	}//end testTheDocumentIsValidatedAgainstTheVendoredMetaSchema()

	/**
	 * Each violation becomes an error in the report and is logged at error level.
	 *
	 * @return void
	 */
	public function testAViolationIsReportedAndLoggedAsAnError(): void {
		$this->validator->method('validate')->willReturn([['path' => '/paths', 'message' => 'must be object']]);
		$logged = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$service = $this->service();
		$service->createOas();

		$errors = array_values(
			array_filter($service->getLastValidationReport()->getErrors(), static fn (array $e): bool => $e['code'] === 'meta-schema-violation')
		);
		$this->assertCount(1, $errors);
		$this->assertSame('/paths', $errors[0]['path']);
		$this->assertSame('OpenAPI 3.1 meta-schema violation: must be object', $errors[0]['message']);
		$this->assertContains(
			['OAS validation: OpenAPI 3.1 meta-schema violation: must be object', ['path' => '/paths', 'code' => 'meta-schema-violation']],
			$logged
		);

	}//end testAViolationIsReportedAndLoggedAsAnError()

	/**
	 * A strict run fails on a meta-schema violation.
	 *
	 * @return void
	 */
	public function testAStrictRunFailsOnAViolation(): void {
		$this->validator->method('validate')->willReturn([['path' => '/info', 'message' => 'required']]);

		$this->expectException(OasValidationException::class);

		$this->service()->createOas(null, true);

	}//end testAStrictRunFailsOnAViolation()

	/**
	 * A throwing validator is logged and generation completes without meta-schema errors.
	 *
	 * @return void
	 */
	public function testAThrowingValidatorDoesNotBlockGeneration(): void {
		$this->validator->method('validate')->willThrowException(new RuntimeException('schema too deep'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$service = $this->service();
		$oas     = $service->createOas(null, true);

		$this->assertArrayHasKey('openapi', $oas);
		$this->assertContains('[OasService] meta-schema validation pass errored: schema too deep', $warnings);
		$this->assertSame(
			[],
			array_filter($service->getLastValidationReport()->getErrors(), static fn (array $e): bool => $e['code'] === 'meta-schema-violation')
		);

	}//end testAThrowingValidatorDoesNotBlockGeneration()

	/**
	 * Without a validator no meta-schema pass runs, and a clean document logs nothing at error level.
	 *
	 * @return void
	 */
	public function testWithoutAValidatorNoMetaSchemaPassRuns(): void {
		$this->validator->expects($this->never())->method('validate');
		$this->logger->expects($this->never())->method('error');

		$service = $this->service(false);
		$service->createOas();

		$this->assertFalse($service->getLastValidationReport()->hasErrors());

	}//end testWithoutAValidatorNoMetaSchemaPassRuns()
	/**
	 * A warning (a declared tag no operation uses) is logged at warning level, not error.
	 *
	 * @return void
	 */
	public function testAWarningIsLoggedAtWarningLevel(): void {
		$register = new Register();
		$register->setId(1);
		$register->setTitle('Crm');
		$register->setSchemas([10]);
		$lonely = new Schema();
		$lonely->setId(99);
		$lonely->setTitle('Lonely');
		$lonely->setProperties(['name' => ['type' => 'string']]);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context['code'] ?? null];
			}
		);
		$this->logger->expects($this->never())->method('error');

		$service = $this->service(false, [$register], [$lonely]);
		$service->createOas();

		$this->assertContains(['OAS validation: Top-level tag "Lonely" is declared but not referenced by any operation.', 'unused_tag'], $warnings);

	}//end testAWarningIsLoggedAtWarningLevel()
}//end class
