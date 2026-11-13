<?php

declare(strict_types=1);

/**
 * The downloaded schema document gives a standard validator the save path's verdict.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/capability-rows-stated-requirements/specs/runtime-schema-api/spec.md#requirement-a-schema-can-be-downloaded-as-a-standard-json-schema-document
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An outside tool reads the document `Schema::getSchemaObject()` serves and decides as the API does.
 *
 * @covers \OCA\OpenRegister\Db\Schema
 */
class SchemaJsonSchemaDocumentTest extends TestCase {

	/**
	 * The URL generator.
	 *
	 * @var IURLGenerator
	 */
	private IURLGenerator $urls;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('getBaseUrl')->willReturn('http://localhost:8080');
	}//end setUp()

	/**
	 * A schema with a required title, a bounded count and an enum.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setUuid('00000000-0000-4000-8000-000000000001');
		$schema->setSlug('permit');
		$schema->setTitle('Permit');
		$schema->setProperties(
			[
				'title' => ['type' => 'string', 'maxLength' => 20],
				'count' => ['type' => 'integer', 'minimum' => 0],
				'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
			]
		);
		$schema->setRequired(['title']);

		return $schema;
	}//end schema()

	/**
	 * The document as a client downloads it: JSON, decoded.
	 *
	 * @return object
	 */
	private function downloaded(): object {
		return json_decode((string)json_encode($this->schema()->getSchemaObject($this->urls)));
	}//end downloaded()

	/**
	 * The save path's verdict on a record.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool True when the save path accepts it.
	 */
	private function savePathAccepts(array $record): bool {
		$handler = new ValidateObject(
			schemas: new ValidationSchemaSource(config: $this->createMock(IAppConfig::class), schemaMapper: $this->createMock(SchemaMapper::class), urlGenerator: $this->urls),
			lookups: new ValidationLookups(objectMapper: $this->createMock(MagicMapper::class), userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);

		return $handler->validateObject($record, $this->schema())->isValid();
	}//end savePathAccepts()

	/**
	 * A standard validator's verdict with the downloaded document.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool True when the standard validator accepts it.
	 */
	private function standardAccepts(array $record): bool {
		return (new Validator())->validate(json_decode((string)json_encode($record)), $this->downloaded())->isValid();
	}//end standardAccepts()

	/**
	 * The document declares draft 2020-12 and carries the required list and the constraints.
	 *
	 * @return void
	 */
	public function testTheDocumentIsADraft202012SchemaWithItsConstraints(): void {
		$document = $this->downloaded();

		$this->assertSame('https://json-schema.org/draft/2020-12/schema', $document->{'$schema'});
		$this->assertSame('object', $document->type);
		$this->assertSame(['title'], $document->required);
		$this->assertSame(20, $document->properties->title->maxLength);
		$this->assertSame(0, $document->properties->count->minimum);
		$this->assertSame(['open', 'closed'], $document->properties->status->enum);
	}//end testTheDocumentIsADraft202012SchemaWithItsConstraints()

	/**
	 * Records the API refuses and accepts, by the reason.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function records(): array {
		return [
			'complete' => [['title' => 'Terrace', 'count' => 3, 'status' => 'open'], true],
			'title only' => [['title' => 'Terrace'], true],
			'missing the required title' => [['count' => 3], false],
			'title too long' => [['title' => str_repeat('x', 21)], false],
			'negative count' => [['title' => 'Terrace', 'count' => -1], false],
			'status outside the enum' => [['title' => 'Terrace', 'status' => 'pending'], false],
		];
	}//end records()

	/**
	 * A standard validator with the downloaded document decides as the save path does.
	 *
	 * @param array<string, mixed> $record   The record.
	 * @param bool                 $accepted Whether the API accepts it.
	 *
	 * @return void
	 *
	 * @dataProvider records
	 */
	public function testAStandardValidatorAgreesWithTheSavePath(array $record, bool $accepted): void {
		$this->assertSame($accepted, $this->savePathAccepts(record: $record), 'save path');
		$this->assertSame($accepted, $this->standardAccepts(record: $record), 'standard validator with the downloaded document');
	}//end testAStandardValidatorAgreesWithTheSavePath()
}//end class
