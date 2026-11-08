<?php

/**
 * The C07 Service and Db classes the schema, import, sync, history and merge jobs ran are reached through interfaces.
 *
 * Each class below was named by a background job. Each now has a narrow
 * interface next to it holding only the methods that job calls; the job types
 * against the interface and the class's own registrar (ContractBindings or
 * DbRegistrar) aliases the interface to the class, so the container hands out
 * the same shared instance. A missing alias is a runtime failure of the job
 * that no other unit test sees, so this test pins the implementation and the
 * binding, that no background job names the class again, and, for the
 * classes only jobs named, that no lib file outside lib/Service does.
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

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\ImportPreviewFinderInterface;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Db\SchemaRunStoreInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefreshInterface;
use OCA\OpenRegister\Service\History\StateHistoryRebuild;
use OCA\OpenRegister\Service\History\StateHistoryRebuildInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use OCA\OpenRegister\Service\Schema\SchemaBatchMigratorInterface;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCA\OpenRegister\Service\Sync\SyncScheduleInterface;
use OCA\OpenRegister\Service\Sync\SyncScheduleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class C07JobInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [the interface the background jobs type against, the registrar whose BINDINGS bind it].
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const CLASSES = [
		ExampleSetDailyRefresh::class => [ExampleSetDailyRefreshInterface::class, ContractBindings::class],
		ImportPreviewMapper::class => [ImportPreviewFinderInterface::class, DbRegistrar::class],
		MasterRecomputeService::class => [MasterRecomputeInterface::class, ContractBindings::class],
		SchemaMigrationService::class => [SchemaBatchMigratorInterface::class, ContractBindings::class],
		SchemaRunMapper::class => [SchemaRunStoreInterface::class, DbRegistrar::class],
		SourceFetcherRegistry::class => [SourceFetcherLookupInterface::class, ContractBindings::class],
		StateHistoryRebuild::class => [StateHistoryRebuildInterface::class, ContractBindings::class],
		SyncScheduleService::class => [SyncScheduleInterface::class, ContractBindings::class],
	];

	/**
	 * Classes no lib file outside lib/Service names any more.
	 *
	 * The others are still named by controllers, commands and listeners
	 * outside this lane, so only the background jobs are pinned for them.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		StateHistoryRebuild::class,
		SyncScheduleService::class,
	];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (self::CLASSES as $class => [$contract]) {
			$cases[$class] = [$class, $contract];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements its interface, and its registrar binds the interface to it.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsImplementedAndBound(string $class, string $contract): void {
		$this->assertTrue(condition: interface_exists($contract), message: $contract . ' exists');
		$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
		$bindings = constant(self::CLASSES[$class][1] . '::BINDINGS');
		$this->assertSame(expected: $class, actual: ($bindings[$contract] ?? null), message: $contract . ' is bound');
	}//end testTheInterfaceIsImplementedAndBound()

	/**
	 * No background job names the class in code.
	 *
	 * @param class-string $class    The class.
	 * @param class-string $contract Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoBackgroundJobNamesTheClass(string $class, string $contract): void {
		$this->assertSame(
			expected: [],
			actual: $this->filesNaming(class: $class, prefix: 'BackgroundJob/'),
			message: 'Type against ' . $contract . ' instead.'
		);
	}//end testNoBackgroundJobNamesTheClass()

	/**
	 * No lib file outside lib/Service names a hidden class in code.
	 *
	 * @return void
	 */
	public function testNoFileOutsideServiceNamesAHiddenClass(): void {
		foreach (self::HIDDEN as $class) {
			$this->assertSame(
				expected: [],
				actual: $this->filesNaming(class: $class, prefix: null),
				message: 'Type against ' . self::CLASSES[$class][0] . ' instead.'
			);
		}
	}//end testNoFileOutsideServiceNamesAHiddenClass()

	/**
	 * The lib files naming the class: those under the prefix, or with no prefix, every file outside lib/Service.
	 *
	 * @param class-string $class  The class.
	 * @param string|null  $prefix The path prefix under lib/, or null for outside lib/Service.
	 *
	 * @return list<string>
	 */
	private function filesNaming(string $class, ?string $prefix): array {
		$naming = [];
		foreach ($this->libFiles() as $relative => $path) {
			$inScope = ($prefix === null && str_starts_with($relative, 'Service/') === false)
				|| ($prefix !== null && str_starts_with($relative, $prefix) === true);
			if ($inScope === true && $this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		return $naming;
	}//end filesNaming()

	/**
	 * Every lib PHP file, keyed by its path under lib/, sorted.
	 *
	 * @return array<string, string>
	 */
	private function libFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() === 'php') {
				$files[substr($file->getPathname(), strlen($lib) + 1)] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end libFiles()

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
