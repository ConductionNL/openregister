<?php

/**
 * A stored null on an optional enum property validates as absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/object-validation/spec.md
 */
class ValidateObjectStoredNullTest extends TestCase {

	private ValidateObject $handler;

	/**
	 * Build a ValidateObject with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');

		$this->handler = new ValidateObject(
			$this->createMock(IAppConfig::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$urlGenerator,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserManager::class)
		);

	}//end setUp()

	/**
	 * A contract schema: required title, optional renewal outcome.
	 *
	 * @param array<int, mixed> $enum     The outcome enum.
	 * @param bool              $required Whether the outcome is required.
	 *
	 * @return object
	 */
	private function contract(array $enum, bool $required=false): object {
		return json_decode(
			(string) json_encode(
				[
					'type'       => 'object',
					'required'   => ($required === true ? ['title', 'renewalLeadOutcome'] : ['title']),
					'properties' => [
						'title'              => ['type' => 'string'],
						'renewalLeadOutcome' => ['type' => 'string', 'enum' => $enum, 'default' => ''],
					],
				]
			)
		);

	}//end contract()

	/**
	 * "" stored as null: the optional enum property validates as absent.
	 *
	 * @return void
	 */
	public function testAnEmptyStringStoredAsNullValidatesAsAbsent(): void {
		$result = $this->handler->validateObject(
			object: ['title' => 'Onderhoud sportvelden 2026', 'renewalLeadOutcome' => null],
			schemaObject: $this->contract(enum: ['', 'won', 'lost'])
		);

		$this->assertTrue($result->isValid());

	}//end testAnEmptyStringStoredAsNullValidatesAsAbsent()

	/**
	 * A required enum property holding null still fails.
	 *
	 * @return void
	 */
	public function testARequiredNullStillFails(): void {
		$result = $this->handler->validateObject(
			object: ['title' => 'Onderhoud sportvelden 2026', 'renewalLeadOutcome' => null],
			schemaObject: $this->contract(enum: ['', 'won', 'lost'], required: true)
		);

		$this->assertFalse($result->isValid());

	}//end testARequiredNullStillFails()

	/**
	 * An enum that lists null accepts it.
	 *
	 * @return void
	 */
	public function testAnEnumThatListsNullAcceptsIt(): void {
		$schema = $this->contract(enum: ['won', 'lost', null]);
		$schema->properties->renewalLeadOutcome->type = ['string', 'null'];

		$result = $this->handler->validateObject(
			object: ['title' => 'Onderhoud sportvelden 2026', 'renewalLeadOutcome' => null],
			schemaObject: $schema
		);

		$this->assertTrue($result->isValid());

	}//end testAnEnumThatListsNullAcceptsIt()

	/**
	 * A word outside the enum still fails.
	 *
	 * @return void
	 */
	public function testAWordOutsideTheEnumStillFails(): void {
		$result = $this->handler->validateObject(
			object: ['title' => 'Onderhoud sportvelden 2026', 'renewalLeadOutcome' => 'maybe'],
			schemaObject: $this->contract(enum: ['', 'won', 'lost'])
		);

		$this->assertFalse($result->isValid());

	}//end testAWordOutsideTheEnumStillFails()
}//end class
