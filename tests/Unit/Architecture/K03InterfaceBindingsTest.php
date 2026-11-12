<?php

/**
 * K03: the like operator and the sync record inputs are built in lib/Db.
 *
 * DbalListClauses built LikeOperator and HarvestPipelineService built
 * SyncExecution and SyncFailure by name. They now ask a static factory in
 * lib/Db. This test pins that the factories build the same class with the
 * same answers, that LikeOperator implements the interface its outside caller
 * types against, and that no lib file outside lib/Db names those classes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\LikeOperator;
use OCA\OpenRegister\Db\LikeOperatorInterface;
use OCA\OpenRegister\Db\LikeOperators;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncExecution;
use OCA\OpenRegister\Db\SyncFailure;
use OCA\OpenRegister\Db\SyncRecordInputs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class K03InterfaceBindingsTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The classes no lib file outside lib/Db may name.
	 */
	private const HIDDEN = [LikeOperator::class, SyncExecution::class, SyncFailure::class];

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (self::HIDDEN as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	public function testLikeOperatorImplementsItsInterface(): void {
		$this->assertContains(LikeOperatorInterface::class, class_implements(LikeOperator::class));
		$this->assertSame('like', LikeOperator::KEY);
	}//end testLikeOperatorImplementsItsInterface()

	public function testTheLikeFactoryBuildsWhatTheCallerBuilt(): void {
		$platform = new PostgreSQLPlatform();
		$expected = new LikeOperator(databasePlatform: $platform);
		$actual   = LikeOperators::forPlatform(databasePlatform: $platform);
		$this->assertSame(LikeOperator::class, get_class($actual));
		$this->assertNotSame($actual, LikeOperators::forPlatform(databasePlatform: $platform));
		$this->assertSame($expected->terms(value: ['a', '', 3]), $actual->terms(value: ['a', '', 3]));
		$this->assertSame($expected->pattern(term: '50%_x'), $actual->pattern(term: '50%_x'));
		$this->assertSame(
			$expected->anyCondition(column: 'c', patternSqls: [':p1', ':p2']),
			$actual->anyCondition(column: 'c', patternSqls: [':p1', ':p2'])
		);
	}//end testTheLikeFactoryBuildsWhatTheCallerBuilt()

	public function testTheSyncFactoryBuildsWhatTheCallerBuilt(): void {
		$source    = new Source();
		$execution = SyncRecordInputs::execution(source: $source, executionId: 'run-1');
		$this->assertSame($source, $execution->source);
		$this->assertSame('run-1', $execution->executionId);

		$failure = SyncRecordInputs::failure(status: 'fetch_error', message: 'boom');
		$this->assertSame('fetch_error', $failure->status);
		$this->assertSame('boom', $failure->message);
	}//end testTheSyncFactoryBuildsWhatTheCallerBuilt()

	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming, 'Ask the factory in lib/Db instead of naming ' . $class . '.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside the component of the class, keyed by its path under lib/.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(string $class): array {
		$component = explode('\\', $class)[2];
		$lib       = (string) realpath(self::LIB);
		$files     = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, $component . '/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$bare      = str_contains($source, 'use ' . $class . ';') || str_contains($source, 'namespace ' . $namespace . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ((in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class)
				|| ($bare === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
