<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\MigrateStorageCommand;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\MigrationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Golden test for MigrateStorageCommand::execute().
 *
 * Each case runs the command once; the recording holds the exit code, the full
 * output and every call to the migration service.
 * Compared with fixtures/migrate-storage-command-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Command\MigrateStorageCommand
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MigrateStorageCommandGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/migrate-storage-command-golden.json';

	/**
	 * Case name => [arguments, service behaviour].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$base = ['direction' => 'to-magic', 'register' => '1', 'schema' => 'five'];
		$report = ['total' => 4, 'migrated' => 3, 'skipped' => 1, 'failed' => 0, 'errors' => []];
		$failed = ['total' => 4, 'migrated' => 1, 'skipped' => 1, 'failed' => 2, 'errors' => [
			['uuid' => 'u-1', 'message' => 'bad row'],
			['uuid' => 'u-2', 'message' => 'worse row'],
		]];

		return [
			'invalid direction' => [['direction' => 'sideways'] + $base, []],
			'resolve fails' => [$base, ['resolve' => 'throw']],
			'status fails' => [$base, ['status' => 'throw']],
			'status only, magic exists' => [['--status' => true] + $base, ['magicExists' => true]],
			'status only, no magic table' => [['--status' => true] + $base, ['magicExists' => false]],
			'to-magic' => [$base, ['report' => $report]],
			'to-blob' => [['direction' => 'to-blob'] + $base, ['report' => $report]],
			'to-blob dry run batch' => [['direction' => 'to-blob', '--dry-run' => true, '--batch-size' => '7'] + $base, ['report' => $report]],
			'to-magic with errors' => [['-b' => '50'] + $base, ['report' => $failed]],
			'errors listed but none failed' => [$base, ['report' => ['failed' => 0] + $failed]],
			'one error, one failed' => [$base, ['report' => ['failed' => 1, 'errors' => [['uuid' => 'u-9', 'message' => 'only row']]] + $failed]],
			'migration throws' => [$base, ['migrate' => 'throw']],
			'to-blob throws' => [['direction' => 'to-blob'] + $base, ['migrate' => 'throw']],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $args  Command arguments and options
	 * @param array<string, mixed> $world Service behaviour
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testExecuteMatchesGolden(array $args, array $world): void {
		$calls = [];
		$register = new class extends Register {
			public function getName(): string {
				return 'Zaken';
			}
		};
		$register->setId(1);
		$schema = new class extends Schema {
			public function getName(): string {
				return 'Five';
			}
		};
		$schema->setId(5);

		$service = $this->createMock(MigrationService::class);
		$service->method('resolveRegisterAndSchema')->willReturnCallback(
			static function ($registerId, $schemaId) use (&$calls, $world, $register, $schema): array {
				$calls[] = ['resolveRegisterAndSchema', $registerId, $schemaId];
				if (($world['resolve'] ?? null) === 'throw') {
					throw new \RuntimeException('no such register');
				}

				return ['register' => $register, 'schema' => $schema];
			}
		);
		$service->method('getStorageStatus')->willReturnCallback(
			static function ($reg, $sch) use (&$calls, $world): array {
				$calls[] = ['getStorageStatus', $reg->getId(), $sch->getId()];
				if (($world['status'] ?? null) === 'throw') {
					throw new \RuntimeException('status unavailable');
				}

				return ['blobStorage' => ['count' => 12], 'magicTable' => ['exists' => ($world['magicExists'] ?? true), 'count' => 3]];
			}
		);
		foreach (['migrateToBlobStorage', 'migrateToMagicTable'] as $method) {
			$service->method($method)->willReturnCallback(
				static function ($reg, $sch, $batchSize, $dryRun) use (&$calls, $world, $method): array {
					$calls[] = [$method, $reg->getId(), $sch->getId(), $batchSize, $dryRun];
					if (($world['migrate'] ?? null) === 'throw') {
						throw new \RuntimeException('table locked');
					}

					return $world['report'];
				}
			);
		}

		$command = new MigrateStorageCommand($service);
		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput($args, $command->getDefinition()), $output);
		$actual = ['code' => $code, 'output' => explode("\n", $output->fetch()), 'calls' => $calls];
		$actual = json_decode(json_encode($actual), true);

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
	}//end testExecuteMatchesGolden()
}//end class
