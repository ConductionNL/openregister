<?php

/**
 * The concrete classes lane D7 hid behind an interface stay hidden.
 *
 * Each class implements its interface, the registrar of its component binds
 * the interface to it (a missing binding is a runtime 500 that no other unit
 * test catches), and no lib/ file outside the class's component names the
 * class itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverInterface;
use OCA\OpenRegister\Service\Form\FormDefinitionResolver;
use OCA\OpenRegister\Service\Form\FormDefinitionResolverInterface;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormDestinationValidatorInterface;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormIdempotencyStoreInterface;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Form\FormUploadStoreInterface;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\Handoff\HandoffServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializer;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lane D7: interface, binding and no outside reference, per hidden class.
 */
final class D7InterfaceBindingsTest extends TestCase {

	/**
	 * The lib/ directory.
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [its interface, the registrar whose BINDINGS bind it].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string}>
	 */
	private const HIDDEN = [
		ExampleSetRecord::class => [ExampleSetRecordInterface::class, ContractBindings::class],
		ExampleSetRemover::class => [ExampleSetRemoverInterface::class, ContractBindings::class],
		FormDefinitionResolver::class => [FormDefinitionResolverInterface::class, ContractBindings::class],
		FormDestinationValidator::class => [FormDestinationValidatorInterface::class, ContractBindings::class],
		FormIdempotencyStore::class => [FormIdempotencyStoreInterface::class, ContractBindings::class],
		FormSubmitService::class => [FormSubmitServiceInterface::class, ContractBindings::class],
		FormUploadStore::class => [FormUploadStoreInterface::class, ContractBindings::class],
		HandoffService::class => [HandoffServiceInterface::class, ContractBindings::class],
		JsonLdContextService::class => [JsonLdContextServiceInterface::class, ContractBindings::class],
		JsonLdSerializer::class => [JsonLdSerializerInterface::class, ContractBindings::class],
	];

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function hiddenClasses(): array {
		$cases = [];
		foreach (array_keys(self::HIDDEN) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end hiddenClasses()

	/**
	 * The class implements its interface.
	 *
	 * @param string $class The hidden class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testClassImplementsItsInterface(string $class): void {
		$this->assertTrue(is_subclass_of($class, self::HIDDEN[$class][0]), $class . ' implements its interface');
	}//end testClassImplementsItsInterface()

	/**
	 * The registrar of the class's component binds the interface to the class.
	 *
	 * @param string $class The hidden class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testInterfaceIsBound(string $class): void {
		[$interface, $registrar] = self::HIDDEN[$class];
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertSame($class, ($bindings[$interface] ?? null), $interface . ' is bound in ' . $registrar);
	}//end testInterfaceIsBound()

	/**
	 * No lib/ file outside the class's component names the class.
	 *
	 * @param string $class The hidden class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$component = explode('\\', $class)[2];
		$outside   = array_filter(
			$this->libFiles(),
			fn (string $path): bool => str_starts_with($path, $component . '/') === false
		);
		$naming    = array_values(array_filter($outside, fn (string $path): bool => $this->names(path: $path, class: $class)));
		$this->assertSame([], $naming, $class . ' is named outside lib/' . $component);
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every PHP file in lib/, relative to lib/.
	 *
	 * @return array<int, string>
	 */
	private function libFiles(): array {
		$paths = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			if (str_ends_with((string)$file, '.php') === true) {
				$paths[] = substr((string)$file, strlen(self::LIB) + 1);
			}
		}

		return $paths;
	}//end libFiles()

	/**
	 * Whether the file's code (not its comments) names the class.
	 *
	 * An import or fully qualified name counts, and so does the bare name in
	 * the class's own namespace.
	 *
	 * @param string $path  The file, relative to lib/.
	 * @param string $class The fully qualified class name.
	 *
	 * @return bool
	 */
	private function names(string $path, string $class): bool {
		$code      = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string)file_get_contents(self::LIB . '/' . $path));
		$namespace = substr($class, 0, (int)strrpos($class, '\\'));
		$short     = substr($class, (int)strrpos($class, '\\') + 1);
		$body      = (string)preg_replace('/^namespace\s+[^;]+;/m', '', $code);
		if (preg_match('/' . preg_quote($class, '/') . '(?![\w\\\\])/', $body) === 1) {
			return true;
		}

		$sameNamespace = preg_match('/^namespace\s+' . preg_quote($namespace, '/') . ';/m', $code) === 1;
		return $sameNamespace === true && preg_match('/(?<![\w\\\\$])' . $short . '(?!\w)/', $body) === 1;
	}//end names()
}//end class
