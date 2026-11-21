<?php

/**
 * Core asks the DeclaredActions helper, not the Schema entity, for a schema's declared actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Schema\DeclaredActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

/**
 * The deprecated Schema::declaredActionNames() and the helper the callers now ask give the same answer.
 */
final class DeclaredActionsCallersTest extends TestCase {

	/**
	 * Configurations: absent, empty, malformed, and a mixed declaration block.
	 *
	 * @return array<string, array{0: array|null}>
	 */
	public static function configurations(): array {
		return [
			'no configuration'   => [null],
			'empty'              => [[]],
			'no action block'    => [['x-openregister-other' => ['a' => 1]]],
			'malformed block'    => [['x-openregister-action' => 'approve']],
			'declared actions'   => [
				[
					'x-openregister-action' => [
						'finish-request' => ['name' => 'Finish', 'description' => 'Finish the request'],
						'approve'        => ['name' => 'Approve'],
						'read'           => ['name' => 'A control key'],
						'broken'         => 'not a definition',
					],
				],
			],
		];
	}//end configurations()

	#[DataProvider('configurations')]
	public function testTheHelperAnswersWhatTheEntityAnswers(?array $configuration): void {
		$schema = new Schema();
		$schema->setConfiguration($configuration);

		$this->assertSame($schema->declaredActionNames(), (new DeclaredActions())->names(configuration: $schema->getConfiguration()));
	}

	public function testTheEntityMethodIsDeprecated(): void {
		$doc = (string)(new ReflectionMethod(Schema::class, 'declaredActionNames'))->getDocComment();

		$this->assertStringContainsString('@deprecated', $doc);
		$this->assertStringContainsString('DeclaredActions::names()', $doc);
	}

	public function testNoLibFileButTheEntityCallsTheDeprecatedMethod(): void {
		$lib     = (string)realpath(__DIR__ . '/../../../../lib');
		$callers = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() !== 'php' || $file->getPathname() === $lib . '/Db/Schema.php') {
				continue;
			}

			if (str_contains((string)file_get_contents($file->getPathname()), '->declaredActionNames(') === true) {
				$callers[] = substr($file->getPathname(), strlen($lib) + 1);
			}
		}

		$this->assertSame([], $callers);
	}
}//end class
