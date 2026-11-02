<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\IntegrationsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\ObjectsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the tool definitions of the built-in MCP tool providers.
 *
 * Compared with fixtures/mcp-tool-definitions-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\IntegrationsToolProvider
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\ObjectsToolProvider
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider
 */
class McpToolDefinitionsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mcp-tool-definitions-golden.json';

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public static function tools(): array {
		$cases = [];
		$classes = [
			FlowMcpToolProvider::class,
			IntegrationsToolProvider::class,
			ObjectsToolProvider::class,
			RegistersToolProvider::class,
			SchemasToolProvider::class,
		];
		foreach ($classes as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end tools()

	/**
	 * @param class-string $class The provider class
	 *
	 * @return void
	 */
	#[DataProvider('tools')]
	public function testToolsMatchGolden(string $class): void {
		$tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$actual = json_decode(json_encode($tool->getTools()), true);

		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$class] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($class, $golden, 'No golden recording for this provider.');
		$this->assertSame($golden[$class], $actual);
	}//end testToolsMatchGolden()
}//end class
