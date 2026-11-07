<?php

/**
 * The Object and Aggregation classes lane D2 hid stay hidden behind their interfaces.
 *
 * For each class below: it implements the interface next to it, the registrar
 * of its component binds that interface to it (a missing binding is a 500 at
 * the first request, which no unit test of the callers sees), and no lib/ file
 * outside the class's own component names the class itself. Comments do not
 * count; a caller that may keep the class is listed with the reason.
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

use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectStreamingSaveInterface;
use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCA\OpenRegister\Service\Object\FacetCacheVersionInterface;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\Object\ObjectEventGateInterface;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillServiceInterface;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\QueryHandlerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandlerInterface;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * @coversNothing
 */
final class D2InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [its interface, the BINDINGS that bind it, its component].
	 *
	 * @var array<class-string, array{0: class-string, 1: array<class-string, class-string>, 2: string}>
	 */
	private const CLASSES = [
		AggregationRunner::class => [AggregationRunnerInterface::class, ContractBindings::BINDINGS, 'Service'],
		FacetCacheVersion::class => [FacetCacheVersionInterface::class, ContractBindings::BINDINGS, 'Service'],
		ObjectEventGate::class => [ObjectEventGateInterface::class, ContractBindings::BINDINGS, 'Service'],
		ObjectService::class => [ObjectStreamingSaveInterface::class, ContractBindings::BINDINGS, 'Service'],
		ObjectSlugBackfillService::class => [ObjectSlugBackfillServiceInterface::class, ContractBindings::BINDINGS, 'Service'],
		QueryHandler::class => [QueryHandlerInterface::class, ContractBindings::BINDINGS, 'Service'],
		SchemaTypeConverter::class => [SchemaTypeConverterInterface::class, ContractBindings::BINDINGS, 'Service'],
		TranslationHandler::class => [TranslationHandlerInterface::class, ContractBindings::BINDINGS, 'Service'],
	];

	/**
	 * Files outside the component that may still name the class => why.
	 *
	 * @var array<class-string, array<string, string>>
	 */
	private const KEPT = [
		ObjectEventGate::class => [
			'BackgroundJob/DeferredObjectEventJob.php' => 'constructs the gate (`new`) around its own dispatcher',
			'Db/MagicMapper.php' => 'constructs the gate (`new`); the file is also changed by open PR #6108',
		],
		ObjectService::class => [
			'Controller/ObjectsController.php' => 'public action parameter of show(), destroy(), canDelete(); showServices() reads its permission handler',
			'Controller/TagsController.php' => 'reads its permission handler, which no interface carries',
		],
		SchemaTypeConverter::class => [
			'Command/RematerialiseCalculationsCommand.php' => 'constructs the converter as a parameter default (`new`)',
			'Db/MagicMapper.php' => 'left while open PR #6108 changes the file',
			'Db/MagicMapper/MagicSearchHandler.php' => 'left while open PR #6108 changes the file',
		],
	];

	/**
	 * Each class implements its interface.
	 *
	 * @return void
	 */
	public function testEveryClassImplementsItsInterface(): void {
		foreach (self::CLASSES as $class => $entry) {
			$this->assertTrue((new ReflectionClass($class))->implementsInterface($entry[0]), $class . ' implements ' . $entry[0]);
		}
	}//end testEveryClassImplementsItsInterface()

	/**
	 * The component's registrar binds each interface to its class.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsBound(): void {
		foreach (self::CLASSES as $class => $entry) {
			$this->assertSame($class, $this->boundTo(bindings: $entry[1], contract: $entry[0]), $entry[0] . ' is bound');
		}
	}//end testEveryInterfaceIsBound()

	/**
	 * No lib/ file outside the component names the class, apart from the kept ones.
	 *
	 * @return void
	 */
	public function testNoFileOutsideTheComponentNamesTheClass(): void {
		$sources = $this->sources();
		foreach (self::CLASSES as $class => $entry) {
			$named = array_filter(
				$sources,
				fn (string $code, string $path) => $this->outside(path: $path, component: $entry[2]) === true
					&& $this->names(code: $code, class: $class) === true,
				ARRAY_FILTER_USE_BOTH
			);
			$this->assertSame($this->kept(kept: self::KEPT, class: $class), array_keys($named), $class . ' is named outside ' . $entry[2]);
		}
	}//end testNoFileOutsideTheComponentNamesTheClass()

	/**
	 * The class a BINDINGS table binds the contract to, if any.
	 *
	 * @param array<string, string> $bindings The registrar's BINDINGS.
	 * @param string                $contract The interface.
	 *
	 * @return string|null
	 */
	private function boundTo(array $bindings, string $contract): ?string {
		return $bindings[$contract] ?? null;
	}//end boundTo()

	/**
	 * The files outside the component that may keep naming the class.
	 *
	 * @param array<string, array<string, string>> $kept  The KEPT table.
	 * @param string                               $class The class.
	 *
	 * @return list<string>
	 */
	private function kept(array $kept, string $class): array {
		return array_keys($kept[$class] ?? []);
	}//end kept()

	/**
	 * Whether a lib-relative path lies outside the component.
	 *
	 * @param string $path      The path relative to lib/.
	 * @param string $component The first-level directory under lib/.
	 *
	 * @return bool
	 */
	private function outside(string $path, string $component): bool {
		return str_starts_with($path, $component . '/') === false;
	}//end outside()

	/**
	 * Whether comment-free code names the class by its fully qualified name.
	 *
	 * An import, a fully qualified reference and an aliased import all carry
	 * the full name; a bare name outside the class's namespace needs one of them.
	 *
	 * @param string $code  Code with the comments removed.
	 * @param string $class The class.
	 *
	 * @return bool
	 */
	private function names(string $code, string $class): bool {
		return preg_match('/(?<![\w\\\\])\\\\?' . preg_quote($class, '/') . '(?![\w\\\\])/', $code) === 1;
	}//end names()

	/**
	 * Every PHP file under lib/, by lib-relative path, with its comments removed.
	 *
	 * @return array<string, string>
	 */
	private function sources(): array {
		$sources = [];
		$files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$path           = substr($file->getPathname(), strlen(self::LIB) + 1);
				$sources[$path] = $this->withoutComments(code: (string) file_get_contents($file->getPathname()));
			}
		}

		ksort($sources);
		return $sources;
	}//end sources()

	/**
	 * The code with its comments and docblocks removed.
	 *
	 * @param string $code The file's code.
	 *
	 * @return string
	 */
	private function withoutComments(string $code): string {
		$text = '';
		foreach (token_get_all($code) as $token) {
			if (is_array($token) === false) {
				$text .= $token;
				continue;
			}

			if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) === false) {
				$text .= $token[1];
			}
		}

		return $text;
	}//end withoutComments()
}//end class
