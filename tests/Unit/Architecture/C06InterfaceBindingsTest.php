<?php

/**
 * Schema-annotation validators lane C06 hid are reached through their interfaces.
 *
 * The schema save checks in lib/Db/SchemaMapper used to construct these
 * concrete Service validators with `new`. They now take them from
 * SchemaAnnotationValidators, which returns each one typed as an interface.
 * The validators are not container services (they were never injected), so
 * there is no binding to pin; this test pins the implementation, that the
 * factory builds exactly the class the check used to build, and that no lib
 * file outside lib/Service names the class again.
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

use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Archival\MdtoElementCatalogue;
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\LinkExposureInterface;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidators;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class C06InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Factory method => [the class it builds, the interface it returns].
	 *
	 * @var array<string, array{class-string, class-string}>
	 */
	private const BUILT = [
		'calculation'            => [CalculationAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'relation'               => [RelationAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'lifecycle'              => [LifecycleAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'widget'                 => [WidgetAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'externalLink'           => [ExternalLinkAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'handoff'                => [HandoffAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'handoffContractBinding' => [HandoffContractBindingValidator::class, SchemaAnnotationValidatorInterface::class],
		'reversibility'          => [ReversibilityAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'dependentValue'         => [DependentValueValidator::class, SchemaAnnotationValidatorInterface::class],
		'consent'                => [ConsentAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'review'                 => [ReviewAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'registry'               => [RegistryAnnotationValidator::class, SchemaAnnotationValidatorInterface::class],
		'linkExposure'           => [LinkExposure::class, LinkExposureInterface::class],
		'elementMapping'         => [ElementMappingValidator::class, ElementMappingValidatorInterface::class],
		'extendingForm'          => [ExtendingFormDeclaration::class, ExtendingFormDeclarationInterface::class],
	];

	/**
	 * Classes no lib file outside lib/Service may name.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		CalculationAnnotationValidator::class,
		RelationAnnotationValidator::class,
		LifecycleAnnotationValidator::class,
		WidgetAnnotationValidator::class,
		ExternalLinkAnnotationValidator::class,
		ExternalLinkResolver::class,
		HandoffAnnotationValidator::class,
		HandoffContractBindingValidator::class,
		ReversibilityAnnotationValidator::class,
		DependentValueValidator::class,
		ExpressionDefaultResolver::class,
		ConsentAnnotationValidator::class,
		ReviewAnnotationValidator::class,
		RegistryAnnotationValidator::class,
		LinkExposure::class,
		ElementMappingValidator::class,
		MdtoElementCatalogue::class,
		ExtendingFormDeclaration::class,
	];

	/**
	 * One case per factory method.
	 *
	 * @return array<string, array{string}>
	 */
	public static function built(): array {
		$cases = [];
		foreach (array_keys(self::BUILT) as $method) {
			$cases[$method] = [$method];
		}

		return $cases;
	}//end built()

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function hidden(): array {
		$cases = [];
		foreach (self::HIDDEN as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end hidden()

	/**
	 * The class implements the interface, and the factory builds a fresh instance of exactly that class.
	 *
	 * @param string $method The factory method.
	 *
	 * @return void
	 */
	#[DataProvider('built')]
	public function testTheFactoryBuildsTheClassTheCheckUsedToBuild(string $method): void {
		[$class, $contract] = self::BUILT[$method];
		$this->assertTrue(condition: interface_exists($contract), message: $contract . ' exists');
		$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);

		$first  = SchemaAnnotationValidators::$method();
		$second = SchemaAnnotationValidators::$method();
		$this->assertSame(expected: $class, actual: get_class($first));
		$this->assertNotSame(expected: $first, actual: $second, message: 'A fresh instance per call, as `new` was');
	}//end testTheFactoryBuildsTheClassTheCheckUsedToBuild()

	/**
	 * The re-exported annotation keys are the owning classes' own.
	 *
	 * @return void
	 */
	public function testTheAnnotationKeysAreUnchanged(): void {
		$this->assertSame(expected: RelationAnnotationValidator::VOCABULARY_ANNOTATION, actual: SchemaAnnotationValidators::RELATION_VOCABULARY_ANNOTATION);
		$this->assertSame(expected: ExternalLinkResolver::ANNOTATION, actual: SchemaAnnotationValidators::EXTERNAL_LINK_ANNOTATION);
		$this->assertSame(expected: ElementMappingValidator::ANNOTATION_KEY, actual: SchemaAnnotationValidators::ELEMENT_MAPPING_ANNOTATION);
	}//end testTheAnnotationKeysAreUnchanged()

	/**
	 * The expression-default pass-through returns what the resolver returns.
	 *
	 * @return void
	 */
	public function testTheExpressionDefaultRefusalsAreTheResolvers(): void {
		$cases = [
			[],
			['a' => ['type' => 'string']],
			['a' => ['type' => 'string', 'default' => '{{ broken']],
			['a' => 'not-a-property'],
		];
		foreach ($cases as $properties) {
			$this->assertSame(
				expected: ExpressionDefaultResolver::validateDeclarations(properties: $properties),
				actual: SchemaAnnotationValidators::expressionDefaultRefusals(properties: $properties)
			);
		}
	}//end testTheExpressionDefaultRefusalsAreTheResolvers()

	/**
	 * No lib file outside lib/Service names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('hidden')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles() as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Reach it through SchemaAnnotationValidators instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside lib/Service, keyed by its path under lib/.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, 'Service/') === false) {
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

			$qualified = in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class;
			if ($qualified === true || ($imported === true && $token[0] === T_STRING && $token[1] === $short)) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
