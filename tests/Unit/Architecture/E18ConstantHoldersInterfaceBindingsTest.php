<?php

/**
 * The E18 constant holders: outside callers read these constants from an interface.
 *
 * HybridSearchDiagnostics read PgVectorPlatform::SIDECAR_TABLE, IntakeSourceRegistry
 * read SeedIntakeSourceRegister's slugs and FlowTimerDefinitionStore read
 * SeedFlowTimerRegister::REGISTER_PATH. Each constant moved to a small interface the
 * class implements, so the class still resolves it to the same value, and the
 * outside callers read it from the interface instead of naming the class. No
 * interface is resolved from the container, so there is no binding to pin.
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

use OCA\OpenRegister\Repair\FlowTimerRegisterPathInterface;
use OCA\OpenRegister\Repair\IntakeSourceRegisterSlugsInterface;
use OCA\OpenRegister\Repair\SeedFlowTimerRegister;
use OCA\OpenRegister\Repair\SeedIntakeSourceRegister;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSidecarTableInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class E18ConstantHoldersInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against, and the registrar that
	 * binds it (null: a constant holder, never resolved from the container).
	 *
	 * @var array<class-string, array{class-string, class-string|null}>
	 */
	private const CLASSES = [
		PgVectorPlatform::class => [VectorSidecarTableInterface::class, null],
		SeedFlowTimerRegister::class => [FlowTimerRegisterPathInterface::class, null],
		SeedIntakeSourceRegister::class => [IntakeSourceRegisterSlugsInterface::class, null],
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * None are kept.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [];

	/**
	 * The values outside callers read, pinned so the move cannot change them.
	 *
	 * @return void
	 */
	public function testTheConstantsKeepTheirValues(): void {
		$this->assertSame('openregister_vec_ann', VectorSidecarTableInterface::SIDECAR_TABLE);
		$this->assertSame(VectorSidecarTableInterface::SIDECAR_TABLE, PgVectorPlatform::SIDECAR_TABLE);
		$this->assertSame('intake-sources', IntakeSourceRegisterSlugsInterface::REGISTER_SLUG);
		$this->assertSame('intake-source', IntakeSourceRegisterSlugsInterface::SCHEMA_SLUG);
		$this->assertSame(IntakeSourceRegisterSlugsInterface::REGISTER_SLUG, SeedIntakeSourceRegister::REGISTER_SLUG);
		$this->assertSame('/lib/Settings/flow_timer_register.json', FlowTimerRegisterPathInterface::REGISTER_PATH);
		$this->assertSame(FlowTimerRegisterPathInterface::REGISTER_PATH, SeedFlowTimerRegister::REGISTER_PATH);
	}//end testTheConstantsKeepTheirValues()

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::CLASSES) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements its interface, and its registrar is in its own component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertSame(explode('\\', $class)[2], explode('\\', $contract)[2], 'The interface lives in the component of the class');
		if ($registrar !== null) {
			$this->assertSame(explode('\\', $class)[2], explode('\\', $registrar)[2], 'Bound in its own component');
		}
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in the registrar of the class's component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		if ($registrar === null) {
			$this->assertTrue(interface_exists($contract), 'Constant holder, not container-bound');
			return;
		}

		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey($contract, $bindings);
		$this->assertSame($class, $bindings[$contract]);
	}//end testTheInterfaceIsBound()

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

		$this->assertSame(self::KEPT_CALLERS[$class] ?? [], $naming, 'Type against ' . self::CLASSES[$class][0] . ' instead.');
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
		$short    = substr($class, (int) strrpos($class, '\\') + 1);
		$imported = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($imported === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

	/**
	 * Whether a qualified-name token is the class.
	 *
	 * @param array{int, string, int} $token The token.
	 * @param class-string            $class The class.
	 *
	 * @return bool
	 */
	private function tokenNames(array $token, string $class): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end tokenNames()
}//end class
