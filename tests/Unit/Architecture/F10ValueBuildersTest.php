<?php

/**
 * The F10 bulk-save outcome, stream channel and leaf bundle are built in their own component.
 *
 * Each class listed here was constructed (with `new` or a named constructor)
 * by a lib file in another component. Those callers now ask a small factory in
 * the class's own component, which builds exactly what they built, typed as an
 * interface. This test pins that the factories build the same class with the
 * same answers, and that no lib file outside the component names the class.
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

use OCA\OpenRegister\Dto\BulkSaveOutcome;
use OCA\OpenRegister\Dto\BulkSaveOutcomeInterface;
use OCA\OpenRegister\Dto\BulkSaveOutcomes;
use OCA\OpenRegister\Kernel\Contracts\ChatStreamChannelInterface;
use OCA\OpenRegister\Mcp\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Mcp\Service\Chat\StreamYieldChannels;
use OCA\OpenRegister\Service\Integration\LeafBundle;
use OCA\OpenRegister\Service\Integration\LeafBundleInterface;
use OCA\OpenRegister\Service\Integration\LeafBundles;
use OCA\OpenRegister\Service\Object\BatchOperationStatusInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F10ValueBuildersTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface its factory returns it as.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BUILT = [
		BulkSaveOutcome::class => BulkSaveOutcomeInterface::class,
		StreamYieldChannel::class => ChatStreamChannelInterface::class,
		LeafBundle::class => LeafBundleInterface::class,
	];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::BUILT) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * BulkSaveOutcomes answers exactly what BulkSaveOutcome's named constructors answer.
	 *
	 * @return void
	 */
	public function testTheOutcomeFactoryBuildsWhatTheControllerBuilt(): void {
		$bulk     = ['invalid' => [['object' => ['id' => 'x'], 'error' => 'bad', 'type' => 'ValidationException']]];
		$expected = BulkSaveOutcome::fromBulkResult(bulkResult: $bulk, requestedCount: 3, accountedCount: 2);
		$actual   = BulkSaveOutcomes::fromBulkResult(bulkResult: $bulk, requestedCount: 3, accountedCount: 2);
		$this->assertSame(expected: BulkSaveOutcome::class, actual: get_class($actual));
		$this->assertSame(expected: $expected->failedCount, actual: $actual->failedCount());
		$this->assertSame(expected: $expected->failures, actual: $actual->failures());
		$this->assertSame(expected: $expected->isComplete(), actual: $actual->isComplete());

		$status = $this->createMock(originalClassName: BatchOperationStatusInterface::class);
		$status->method('getFailed')->willReturn([]);
		$expected = BulkSaveOutcome::fromBatchStatus(status: $status, requestedCount: 2, accountedCount: 2);
		$actual   = BulkSaveOutcomes::fromBatchStatus(status: $status, requestedCount: 2, accountedCount: 2);
		$this->assertSame(expected: BulkSaveOutcome::class, actual: get_class($actual));
		$this->assertSame(expected: $expected->failedCount, actual: $actual->failedCount());
		$this->assertSame(expected: $expected->failures, actual: $actual->failures());
	}//end testTheOutcomeFactoryBuildsWhatTheControllerBuilt()

	/**
	 * The channel and leaf-bundle factories build a fresh instance of the class each time.
	 *
	 * @return void
	 */
	public function testTheFactoriesBuildFreshInstances(): void {
		$channel = StreamYieldChannels::channel();
		$this->assertSame(expected: StreamYieldChannel::class, actual: get_class($channel));
		$this->assertNotSame(expected: $channel, actual: StreamYieldChannels::channel());

		$apps   = $this->createMock(originalClassName: IAppManager::class);
		$bundle = LeafBundles::forApps(appManager: $apps);
		$this->assertSame(expected: LeafBundle::class, actual: get_class($bundle));
		$this->assertNotSame(expected: $bundle, actual: LeafBundles::forApps(appManager: $apps));
	}//end testTheFactoriesBuildFreshInstances()

	/**
	 * The class implements the interface its factory returns.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		$this->assertContains(needle: self::BUILT[$class], haystack: class_implements($class), message: $class . ' implements ' . self::BUILT[$class]);
	}//end testTheClassImplementsItsInterface()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Ask the factory and type against ' . self::BUILT[$class] . ' instead.');
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
