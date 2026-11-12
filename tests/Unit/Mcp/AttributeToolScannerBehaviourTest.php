<?php

/**
 * AttributeToolScanner behaviour tests.
 *
 * Covers the rejection paths the existing scanner tests leave open (static
 * and abstract attributed methods, an attribute that cannot be instantiated)
 * and the schema fallbacks: no description anywhere, untyped and
 * parameterless methods, and return types that do or do not yield an
 * output schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use OCA\OpenRegister\Mcp\ToolScanSubject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Mcp\AttributeToolScanner
 * @uses   \OCA\OpenRegister\Mcp\Attribute\McpTool
 * @uses   \OCA\OpenRegister\Mcp\ReflectionJsonType
 * @uses   \OCA\OpenRegister\Mcp\ToolScanSubject
 * @uses   \OCA\OpenRegister\Mcp\ToolDocblock
 * @uses   \OCA\OpenRegister\Mcp\ToolDescriptorExtras
 * @uses   \OCA\OpenRegister\Mcp\ToolDeclarationCheck
 */
class AttributeToolScannerBehaviourTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	/**
	 * Warnings captured from the logger: [message, context].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * Set up a logger that records warnings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
	}//end setUp()

	/**
	 * Scan a class and index its descriptors by name.
	 *
	 * @param string $className Class.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scan(string $className): array {
		$byName = [];
		foreach ((new AttributeToolScanner())->scanClass(new ToolScanSubject('demo', $className, $this->logger)) as $descriptor) {
			$byName[$descriptor['name']] = $descriptor;
		}

		return $byName;
	}//end scan()

	/**
	 * Warned method names, for a given message.
	 *
	 * @param string $message Message.
	 *
	 * @return list<string>
	 */
	private function warnedMethods(string $message): array {
		$methods = [];
		foreach ($this->warnings as [$logged, $context]) {
			if ($logged === $message) {
				$methods[] = $context['method'];
			}
		}

		return $methods;
	}//end warnedMethods()

	/**
	 * Static attributed methods are rejected with a warning; siblings survive.
	 *
	 * @return void
	 */
	public function testStaticAttributedMethodIsIgnored(): void {
		$tools = $this->scan(ScannerEdgeFixtureService::class);

		$this->assertArrayNotHasKey('staticTool', $tools);
		$this->assertSame(
			['staticTool'],
			$this->warnedMethods('[AttributeToolScanner] #[McpTool] on a static or abstract method is ignored')
		);
		$this->assertArrayHasKey('bare', $tools);
	}//end testStaticAttributedMethodIsIgnored()

	/**
	 * Abstract attributed methods are rejected with the same warning.
	 *
	 * @return void
	 */
	public function testAbstractAttributedMethodIsIgnored(): void {
		$tools = $this->scan(AbstractScannerFixtureService::class);

		$this->assertSame(['concrete'], array_keys($tools));
		$this->assertSame(
			['abstractTool'],
			$this->warnedMethods('[AttributeToolScanner] #[McpTool] on a static or abstract method is ignored')
		);
	}//end testAbstractAttributedMethodIsIgnored()

	/**
	 * An attribute whose arguments cannot construct McpTool is skipped with a warning.
	 *
	 * @return void
	 */
	public function testUninstantiableAttributeIsSkipped(): void {
		$tools = $this->scan(ScannerEdgeFixtureService::class);

		$this->assertArrayNotHasKey('brokenAttribute', $tools);
		$this->assertSame(
			['brokenAttribute'],
			$this->warnedMethods('[AttributeToolScanner] Could not instantiate #[McpTool] attribute')
		);
	}//end testUninstantiableAttributeIsSkipped()

	/**
	 * With neither attribute nor docblock description, a Class::method placeholder is used.
	 *
	 * @return void
	 */
	public function testMissingDescriptionFallsBackToPlaceholder(): void {
		$tools = $this->scan(ScannerEdgeFixtureService::class);

		$this->assertSame(
			ScannerEdgeFixtureService::class . '::bare (no description declared).',
			$tools['bare']['description']
		);
	}//end testMissingDescriptionFallsBackToPlaceholder()

	/**
	 * A parameterless method has no `required` list; an untyped param has no `type`.
	 *
	 * @return void
	 */
	public function testParameterlessAndUntypedSchemas(): void {
		$tools = $this->scan(ScannerEdgeFixtureService::class);

		$this->assertSame(['type' => 'object', 'properties' => []], $tools['bare']['inputSchema']);
		$this->assertSame(
			['type' => 'object', 'properties' => ['anything' => []], 'required' => ['anything']],
			$tools['untyped']['inputSchema']
		);
	}//end testParameterlessAndUntypedSchemas()

	/**
	 * Output schema: none for void/untyped/union returns, a nullable pair for ?int, object for a class.
	 *
	 * @return void
	 */
	public function testOutputSchemaInference(): void {
		$tools = $this->scan(ScannerEdgeFixtureService::class);

		$this->assertArrayNotHasKey('outputSchema', $tools['bare']);
		$this->assertArrayNotHasKey('outputSchema', $tools['untyped']);
		$this->assertArrayNotHasKey('outputSchema', $tools['unionReturn']);
		$this->assertSame(['type' => ['integer', 'null']], $tools['nullableInt']['outputSchema']);
		$this->assertSame(['type' => 'object'], $tools['objectReturn']['outputSchema']);
	}//end testOutputSchemaInference()
}//end class

/**
 * Fixture: attributed methods hitting the scanner's edge branches.
 */
class ScannerEdgeFixtureService {

	#[McpTool]
	public function bare(): void {
	}

	#[McpTool]
	public function untyped($anything) {
		return $anything;
	}

	#[McpTool(description: 'Union return')]
	public function unionReturn(): int|string {
		return 1;
	}

	#[McpTool(description: 'Nullable int')]
	public function nullableInt(): ?int {
		return null;
	}

	#[McpTool(description: 'Object return')]
	public function objectReturn(): \stdClass {
		return new \stdClass();
	}

	#[McpTool(description: 'Static')]
	public static function staticTool(): string {
		return '';
	}

	#[McpTool(bogusArgument: true)]
	public function brokenAttribute(): string {
		return '';
	}
}//end class

/**
 * Fixture: an abstract class with an attributed abstract method.
 */
abstract class AbstractScannerFixtureService {

	#[McpTool(description: 'Abstract')]
	abstract public function abstractTool(): string;

	#[McpTool(description: 'Concrete')]
	public function concrete(): string {
		return '';
	}
}//end class
