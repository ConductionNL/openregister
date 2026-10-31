<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Tool;

use OCA\OpenRegister\Tool\AgentTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the LLM function definitions of the built-in tools.
 *
 * Compared with fixtures/tool-function-definitions-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Tool\AgentTool
 * @covers \OCA\OpenRegister\Tool\ApplicationTool
 * @covers \OCA\OpenRegister\Tool\ObjectsTool
 * @covers \OCA\OpenRegister\Tool\RegisterTool
 * @covers \OCA\OpenRegister\Tool\SchemaTool
 */
class ToolFunctionDefinitionsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/tool-function-definitions-golden.json';

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public static function tools(): array {
		$cases = [];
		foreach ([AgentTool::class, ApplicationTool::class, ObjectsTool::class, RegisterTool::class, SchemaTool::class] as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end tools()

	/**
	 * @param class-string $class The tool class
	 *
	 * @return void
	 */
	#[DataProvider('tools')]
	public function testFunctionsMatchGolden(string $class): void {
		$tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$actual = json_decode(json_encode($tool->getFunctions()), true);

		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$class] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($class, $golden, 'No golden recording for this tool.');
		$this->assertSame($golden[$class], $actual);
	}//end testFunctionsMatchGolden()
}//end class
