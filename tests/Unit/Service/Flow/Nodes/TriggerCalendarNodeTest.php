<?php

/**
 * Unit tests for TriggerCalendarNode's declaration and config refusals
 * (flow-calendar-and-webhook-triggers task 1.1).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerCalendarNode;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerCalendarNode
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\TriggerNodeBase
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 */
class TriggerCalendarNodeTest extends TestCase {

	/**
	 * The node over a `contracten` schema with a date, a date-time and a text property,
	 * and one known user `beheer`.
	 *
	 * @return TriggerCalendarNode
	 */
	private function node(): TriggerCalendarNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'beheer' ? $this->createMock(IUser::class) : null));
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(
			[
				'einddatum' => ['type' => 'string', 'format' => 'date'],
				'ondertekend' => ['type' => 'string', 'format' => 'date-time'],
				'omschrijving' => ['type' => 'string'],
			]
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id) use ($schema): Schema {
				if ($id !== 'contracten') {
					throw new DoesNotExistException('no schema ' . $id);
				}

				return $schema;
			}
		);

		return new TriggerCalendarNode(new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class)), $users, $schemas);
	}//end node()

	/**
	 * A config that fires thirty days before a contract ends.
	 *
	 * @param array<string, mixed> $overrides Changes.
	 *
	 * @return array<string, mixed>
	 */
	private static function config(array $overrides = []): array {
		return array_merge(
			['register' => 'contracts', 'schema' => 'contracten', 'dateProperty' => 'einddatum', 'offset' => '-P30D', 'runAs' => 'beheer'],
			$overrides
		);
	}//end config()

	/**
	 * The node declares its id and the keys it reads.
	 *
	 * @return void
	 */
	public function testTheDeclaration(): void {
		$node = $this->node();

		$this->assertSame('openregister.trigger-calendar', $node->getId());
		$this->assertSame(['register', 'schema', 'dateProperty', 'offset', 'workingCalendar', 'runAs'], $node->configKeys());
		$this->assertSame($node->configKeys(), array_column($node->configForm(), 'key'));
	}//end testTheDeclaration()

	/**
	 * Valid configs pass: a date, a date-time, a positive offset, a working calendar.
	 *
	 * @return void
	 */
	public function testAValidConfigPasses(): void {
		$node = $this->node();

		$node->validateConfig(self::config());
		$node->validateConfig(self::config(['dateProperty' => 'ondertekend', 'offset' => 'P1W', 'workingCalendar' => 'gemeente-standaard']));
		$node->validateConfig(self::config(['offset' => 'P0D', 'workingCalendar' => null]));
		$this->addToAssertionCount(3);
	}//end testAValidConfigPasses()

	/**
	 * Every refused config, with the words its message must carry.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'no register' => [['register' => ''], '"register" and the "schema"'],
			'no schema' => [['schema' => ' '], '"register" and the "schema"'],
			'unknown schema' => [['schema' => 'gone'], 'schema "gone" cannot be found'],
			'no date property' => [['dateProperty' => ''], '"dateProperty"'],
			'unknown property' => [['dateProperty' => 'looptijd'], 'has no property "looptijd"'],
			'a text property' => [['dateProperty' => 'omschrijving'], '"omschrijving", which is not a date. It accepts a property with the format date or date-time'],
			'no offset' => [['offset' => ''], 'ISO 8601 duration'],
			'a number as offset' => [['offset' => '30'], '"30" is not'],
			'a bare minus' => [['offset' => '-'], 'ISO 8601 duration'],
			'a calendar that is not text' => [['workingCalendar' => ['x']], '"workingCalendar"'],
			'no runAs' => [['runAs' => ''], '"runAs"'],
			'unknown runAs' => [['runAs' => 'niemand'], '"niemand", which is not an existing user'],
		];
	}//end refusals()

	/**
	 * Each refusal is an InvalidArgumentException naming what is wrong.
	 *
	 * @param array<string, mixed> $overrides The config changes.
	 * @param string               $message   Words the message carries.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testEachRefusedConfig(array $overrides, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->node()->validateConfig(self::config($overrides));
	}//end testEachRefusedConfig()
}//end class
