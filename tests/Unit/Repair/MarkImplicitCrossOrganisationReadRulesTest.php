<?php

/**
 * Unit tests for MarkImplicitCrossOrganisationReadRules.
 *
 * A read rule that binds a row field to the caller's organisation read across
 * organisations before `crossOrganisation` became the only way to do so. The
 * repair step marks those rules so they keep working, and leaves every other
 * rule to keep the caller's organisation filter.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Repair;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Repair\MarkImplicitCrossOrganisationReadRules;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\MarkImplicitCrossOrganisationReadRules
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Repair\SchemaListing
 */
final class MarkImplicitCrossOrganisationReadRulesTest extends TestCase {
	/**
	 * The schemas the mapper was asked to write.
	 *
	 * @var array<int, Schema>
	 */
	private array $written = [];

	/**
	 * The info lines the step logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The summary the step reported.
	 *
	 * @var string
	 */
	private string $summary = '';

	private function schema(?array $authorization, int $id = 7): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('usage');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	private function sweep(array $schemas, bool $updateFails = false): void {
		$this->written = [];
		$this->logged = [];

		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findAll')->willReturn($schemas);
		$mapper->method('update')->willReturnCallback(
			function (...$args) use ($updateFails) {
				if ($updateFails === true) {
					throw new RuntimeException('refused');
				}

				$this->written[] = $args[0];
				return $args[0];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function ($message) {
			$this->logged[] = (string)$message;
		});

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function ($message) {
			$this->summary = (string)$message;
		});

		(new MarkImplicitCrossOrganisationReadRules($mapper, $logger))->run($output);
	}//end sweep()

	public function testABoundFieldRuleIsMarkedAndAPlainGroupRuleNarrows(): void {
		$provider = ['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']];
		$schema = $this->schema(['read' => ['hr', $provider], 'update' => [$provider]]);

		$this->sweep([$schema]);

		self::assertCount(1, $this->written);
		self::assertSame(
			['hr', $provider + ['crossOrganisation' => true]],
			$schema->getAuthorization()['read']
		);
		self::assertSame([$provider], $schema->getAuthorization()['update']);
		self::assertStringContainsString('Schema 7 has 1 read rule(s) that now keep', implode("\n", $this->logged));
		self::assertStringContainsString('on the read rules of 1 schema(s), 0 could not be written. 1 other', $this->summary);
	}//end testABoundFieldRuleIsMarkedAndAPlainGroupRuleNarrows()

	public function testAMembershipBindingIsMarked(): void {
		$rule = ['group' => 'team', 'match' => ['administrationId' => ['$memberOf' => ['schema' => 'm', 'field' => 'a']]]];
		$schema = $this->schema(['read' => [$rule]]);

		$this->sweep([$schema]);

		self::assertSame([$rule + ['crossOrganisation' => true]], $schema->getAuthorization()['read']);
	}//end testAMembershipBindingIsMarked()

	public function testRulesThatNeverCrossedImplicitlyAreLeftAlone(): void {
		$read = [
			['group' => 'authenticated', 'match' => ['provider' => '$organisation']],
			['group' => 'public', 'match' => ['provider' => '$organisation']],
			['user' => 'femke', 'group' => 'team', 'match' => ['provider' => '$organisation']],
			['group' => 'team', 'match' => ['_organisation' => '$organisation', 'provider' => '$organisation']],
			['group' => 'team', 'match' => ['caseType' => 'ct-permit']],
			['group' => 'team', 'match' => ['provider' => ['$in' => ['$organisation']]]],
			['group' => '', 'match' => ['provider' => '$organisation']],
			'authenticated',
			'user:femke',
			'admin',
		];
		$schema = $this->schema(['read' => $read]);

		$this->sweep([$schema]);

		self::assertSame([], $this->written);
		self::assertSame($read, $schema->getAuthorization()['read']);
		self::assertStringContainsString('Schema 7 has 1 read rule(s)', implode("\n", $this->logged));
	}//end testRulesThatNeverCrossedImplicitlyAreLeftAlone()

	public function testAnUnmarkedPublicRuleIsLoggedAsNarrowedAndAMarkedOneIsNot(): void {
		$schema = $this->schema(['read' => ['public', ['group' => 'public', 'match' => ['s' => 1]], ['group' => 'public', 'crossOrganisation' => true]]]);

		$this->sweep([$schema]);

		self::assertSame([], $this->written);
		self::assertStringContainsString('Schema 7 has 2 read rule(s)', implode("\n", $this->logged));
	}//end testAnUnmarkedPublicRuleIsLoggedAsNarrowedAndAMarkedOneIsNot()

	public function testARuleThatAlreadySaysCrossOrganisationIsLeftAsWritten(): void {
		$bound = ['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']];
		$read = [
			$bound + ['crossOrganisation' => false],
			['group' => 'team', 'match' => ['provider' => '$organisation'], 'crossOrganisation' => null],
			['group' => 'gebruik', 'match' => ['consumer' => '$organisation'], 'crossOrganisation' => 'no'],
		];
		$schema = $this->schema(['read' => $read]);

		$this->sweep([$schema]);

		self::assertSame([], $this->written);
		self::assertSame($read, $schema->getAuthorization()['read']);
	}//end testARuleThatAlreadySaysCrossOrganisationIsLeftAsWritten()

	public function testASecondRunChangesNothing(): void {
		$schema = $this->schema(['read' => [['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']]]]);

		$this->sweep([$schema]);
		$this->sweep([$schema]);

		self::assertSame([], $this->written);
	}//end testASecondRunChangesNothing()

	public function testSchemasWithoutReadRulesAreSkipped(): void {
		$this->sweep([$this->schema(null), $this->schema(['update' => ['hr']]), $this->schema(['read' => 'hr'])]);

		self::assertSame([], $this->written);
		self::assertSame([], $this->logged);
	}//end testSchemasWithoutReadRulesAreSkipped()

	public function testARefusedWriteIsCountedAndDoesNotAbortTheRun(): void {
		$first = $this->schema(['read' => [['group' => 'a', 'match' => ['provider' => '$organisation']]]], 1);
		$second = $this->schema(['read' => [['group' => 'b', 'match' => ['provider' => '$organisation']]]], 2);

		$this->sweep([$first, $second], updateFails: true);

		self::assertStringContainsString('of 0 schema(s), 2 could not be written', $this->summary);
	}//end testARefusedWriteIsCountedAndDoesNotAbortTheRun()

	public function testAListThatFailsSkipsTheStep(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findAll')->willThrowException(new RuntimeException('no table'));
		$mapper->expects(self::never())->method('update');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');

		(new MarkImplicitCrossOrganisationReadRules($mapper, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testAListThatFailsSkipsTheStep()

	public function testTheStepHasAName(): void {
		$step = new MarkImplicitCrossOrganisationReadRules($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class));

		self::assertStringContainsString('across organisations', $step->getName());
	}//end testTheStepHasAName()
}//end class
