<?php

/**
 * Characterisation: every write, log line and summary line of the export-grant
 * and crossOrganisation-marking repair steps, for a mixed set of schemas, a
 * refused write and a mapper that cannot list schemas.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Repair\GrantExportWhereReadIsGranted;
use OCA\OpenRegister\Repair\MarkImplicitCrossOrganisationReadRules;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs both steps over recording doubles and compares the log with a golden file.
 */
class RuleRepairsGoldenTest extends TestCase {

	/**
	 * Every write, log and output line, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A schema with an id and an authorization block.
	 *
	 * @param int        $id            The id.
	 * @param array|null $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(int $id, ?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('s' . $id);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * The schemas both steps walk: every shape the two steps tell apart.
	 *
	 * @return array<int, mixed> The schemas, with one entry that is not a schema.
	 */
	private static function schemas(): array {
		return [
			self::schema(1, null),
			self::schema(2, ['read' => ['hr'], 'update' => ['hr']]),
			self::schema(3, ['read' => ['hr'], 'export' => ['admin']]),
			self::schema(4, ['update' => ['hr']]),
			self::schema(5, ['read' => [['group' => 'hr', 'match' => ['owner' => '$userId']], 'staff', ['group' => 'public']]]),
			self::schema(6, ['read' => [['group' => 'hr', 'match' => ['_organisation' => '$organisation', 'unit' => '$organisation']]]]),
			self::schema(7, ['read' => ['authenticated', 'admin', 'user:jan', ['group' => 'public', 'crossOrganisation' => true]]]),
			self::schema(8, ['read' => [['group' => 'hr', 'match' => ['unit' => '$organisation']]]]),
			self::schema(9, ['read' => [['group' => 'hr', 'match' => ['unit' => '$organisation']], 'staff']]),
			'not a schema',
		];
	}//end schemas()

	/**
	 * Run one step over recording doubles.
	 *
	 * @param string $step     `grant` or `mark`.
	 * @param bool   $listable Whether the mapper can list schemas.
	 *
	 * @return array<int, string> The call log.
	 */
	private function record(string $step, bool $listable): array {
		$this->calls = [];

		$mapper = $this->createMock(SchemaMapper::class);
		if ($listable === true) {
			$mapper->method('findAll')->willReturn(self::schemas());
		} else {
			$mapper->method('findAll')->willThrowException(new RuntimeException('database gone'));
		}

		$mapper->method('update')->willReturnCallback(
			function (Schema $schema): Schema {
				$this->calls[] = 'update ' . $schema->getId() . ' ' . json_encode($schema->getAuthorization());
				if ($schema->getId() === 8) {
					throw new RuntimeException('refused');
				}

				return $schema;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = 'log.' . $level . ' ' . $message;
				}
			);
		}

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = 'output.info ' . $message;
			}
		);

		if ($step === 'grant') {
			(new GrantExportWhereReadIsGranted($mapper, $logger))->run($output);
		} else {
			(new MarkImplicitCrossOrganisationReadRules($mapper, $logger))->run($output);
		}

		return $this->calls;
	}//end record()

	/**
	 * Both steps, over a listable and an unlistable mapper.
	 *
	 * @return array<string, array{0: string, 1: bool}> The cases.
	 */
	public static function cases(): array {
		return [
			'grant' => ['grant', true],
			'grant-unlistable' => ['grant', false],
			'mark' => ['mark', true],
			'mark-unlistable' => ['mark', false],
		];
	}//end cases()

	/**
	 * The call log matches the golden one.
	 *
	 * @param string $step     `grant` or `mark`.
	 * @param bool   $listable Whether the mapper can list schemas.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheStepMakesTheGoldenCalls(string $step, bool $listable): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/fixtures/rule-repairs-golden.json'), true, 512, JSON_THROW_ON_ERROR);
		$case = $step;
		if ($listable === false) {
			$case .= '-unlistable';
		}

		$this->assertSame($golden[$case], $this->record($step, $listable));
	}//end testTheStepMakesTheGoldenCalls()
}//end class
