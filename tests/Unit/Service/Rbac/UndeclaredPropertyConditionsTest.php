<?php

/**
 * A rule on a property the schema does not declare matches no record, on a single read as on a list.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\UndeclaredPropertyConditions
 * @covers \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 */
class UndeclaredPropertyConditionsTest extends TestCase {

	/**
	 * A matcher whose container finds schema 7 declaring `status` and `owner`.
	 *
	 * @param bool $schemaReadFails Whether reading the schema throws.
	 *
	 * @return ConditionMatcher The matcher.
	 */
	private function matcher(bool $schemaReadFails = false): ConditionMatcher {
		$schema = new Schema();
		$schema->setProperties(['status' => ['type' => 'string'], 'owner' => ['type' => 'object']]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		if ($schemaReadFails === true) {
			$schemas->method('find')->willThrowException(new RuntimeException('gone'));
		} else {
			$schemas->method('find')->willReturn($schema);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => ($id === SchemaMapperInterface::class ? $schemas : null)
		);

		return new ConditionMatcher(
			new ConditionUserResolvers($this->createMock(IUserSession::class)),
			$container,
			new OperatorEvaluator(logger: new NullLogger()),
			new NullLogger()
		);
	}//end matcher()

	/**
	 * A record of schema 7 with only a title.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function record(): array {
		return ['title' => 'x', '@self' => ['schema' => '7']];
	}//end record()

	public function testAnUndeclaredPropertyGrantsNothing(): void {
		$this->assertFalse($this->matcher()->objectMatchesConditions(object: $this->record(), match: ['bogus' => null]));
		$this->assertFalse($this->matcher()->objectMatchesConditions(object: $this->record(), match: ['bogus' => ['$exists' => false]]));
		$this->assertFalse($this->matcher()->objectMatchesConditions(object: $this->record(), match: ['bogus.deep' => null]));
	}

	public function testAnUndeclaredPropertySelectsTheRowForADenial(): void {
		$this->assertTrue($this->matcher()->namesUnknownMetadata(match: ['bogus' => 'x'], object: $this->record()));
	}

	public function testADeclaredPropertyTheRecordLacksStillReadsAsNull(): void {
		$this->assertTrue($this->matcher()->objectMatchesConditions(object: $this->record(), match: ['status' => null]));
		$this->assertTrue($this->matcher()->objectMatchesConditions(object: $this->record(), match: ['owner.id' => null]));
	}

	public function testAKeyTheRecordCarriesIsNotJudgedByTheSchema(): void {
		$this->assertTrue($this->matcher()->objectMatchesConditions(object: $this->record(), match: ['title' => 'x']));
	}

	public function testWithoutAReadableSchemaNothingIsRefusedHere(): void {
		$this->assertTrue($this->matcher(schemaReadFails: true)->objectMatchesConditions(object: $this->record(), match: ['bogus' => null]));
		$this->assertTrue($this->matcher()->objectMatchesConditions(object: ['title' => 'x'], match: ['bogus' => null]));
	}

	public function testTheListPathIsUnchanged(): void {
		$this->assertFalse($this->matcher()->namesUnknownMetadata(match: ['bogus' => 'x']));
	}
}
