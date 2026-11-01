<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- fixture classes for the scanner live next to the test.

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Mcp\AttributeToolScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Fixture: every kind of method the scanner meets.
 */
abstract class ScanClassGoldenFixture {
	/**
	 * Find things.
	 *
	 * @param string $term What to look for
	 * @param int    $limit How many
	 *
	 * @return array<string, mixed> The hits
	 */
	#[McpTool(readOnlyHint: true, scope: 'read', subject: 'thing', action: 'list', reach: 'user')]
	public function find(string $term, int $limit = 10): array {
		return [$term, $limit];
	}

	#[McpTool(name: 'renamed', description: 'Declared description.', destructiveHint: true)]
	public function write(?string $value): void {
	}

	#[McpTool]
	public function undocumented(): void {
	}

	#[McpTool]
	private function hidden(): void {
	}

	#[McpTool]
	public static function shared(): void {
	}

	#[McpTool]
	abstract public function later(): void;

	#[McpTool(scope: 'everything')]
	public function badScope(): void {
	}

	#[McpTool(reach: 'galaxy')]
	public function badReach(): void {
	}

	#[McpTool(annotations: ['' => 'x'])]
	public function badAnnotation(): void {
	}

	#[McpTool(name: 5)]
	public function badArgument(): void {
	}

	public function plain(): void {
	}
}

/**
 * Golden test for AttributeToolScanner::scanClass().
 *
 * The recording holds the descriptors and every log call for each scanned class.
 * Compared with fixtures/attribute-tool-scanner-scan-class-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Mcp\AttributeToolScanner
 * @uses   \OCA\OpenRegister\Mcp\Attribute\McpTool
 * @uses   \OCA\OpenRegister\Mcp\ToolScanSubject
 */
class AttributeToolScannerScanClassGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/attribute-tool-scanner-scan-class-golden.json';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		return [
			'fixture class' => [ScanClassGoldenFixture::class],
			'missing class' => ['OCA\\OpenRegister\\Tests\\Unit\\Mcp\\NoSuchScanFixture'],
			'class without tools' => [\ArrayObject::class],
		];
	}//end cases()

	/**
	 * @param string $className The class to scan
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testScanClassMatchesGolden(string $className): void {
		$trace = [];
		$logger = new class($trace) extends AbstractLogger {
			/** @param list<mixed> $trace */
			public function __construct(private array &$trace) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				// PHP's own error text differs between versions; only its presence is recorded.
				if (isset($context['error']) === true) {
					$context['error'] = ($context['error'] !== '');
				}

				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		$descriptors = (new AttributeToolScanner())->scanClass(appId: 'demo', className: $className, logger: $logger);
		$actual = json_decode(json_encode(['descriptors' => $descriptors, 'log' => $trace]), true);

		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testScanClassMatchesGolden()
}//end class
