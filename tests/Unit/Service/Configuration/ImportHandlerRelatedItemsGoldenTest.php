<?php

/**
 * Golden characterization test for ImportHandler::processRelatedItems().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\LinkedTaskDraft;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SeedRelatedTarget;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TaskService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs processRelatedItems() over a fixed corpus and compares the counts it
 * adds to the result and every file, note, task and log call with
 * `fixtures/related-items-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Service\Configuration\SeedRelatedTarget
 * @uses \OCA\OpenRegister\Service\Configuration\ImportDuplicates
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline
 * @uses \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedSchemaShape
 * @uses \OCA\OpenRegister\Service\Configuration\RejectedSchemaReport
 * @uses \OCA\OpenRegister\Service\Configuration\ForeignSeedSchema
 * @uses \OCA\OpenRegister\Service\Configuration\ArrayStructure
 */
class ImportHandlerRelatedItemsGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/related-items-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [items, user context, services present, starting result].
	 *
	 * @return array<string, array{0: array, 1: bool, 2: bool, 3: array}>
	 */
	private static function corpus(): array {
		$all = [
			'files' => [
				['name' => 'plain.txt', 'content' => 'hello', 'tags' => ['a'], 'share' => 1],
				['name' => 'b64.bin', 'content' => 'base64:'.base64_encode('bytes')],
				['name' => 'bad.bin', 'content' => 'base64:***'],
				['name' => '', 'content' => 'no name'],
				['name' => 'array.txt', 'content' => ['x']],
				['name' => 'fails.txt', 'content' => 'boom'],
				['name' => 7, 'content' => '', 'tags' => 'single'],
			],
			'notes' => [['message' => 'First'], ['message' => ''], ['message' => 'fail'], ['nothing' => 1], ['message' => 5]],
			'tasks' => [
				['summary' => 'Call back', 'description' => 'D', 'status' => 'in-process', 'priority' => '3', 'due' => '2026-01-01'],
				['summary' => ''],
				['summary' => 'fail'],
				['summary' => 'Minimal'],
			],
		];
		return [
			'everything' => [$all, true, true, []],
			'no-user' => [$all, false, true, ['relatedFiles' => 2, 'relatedNotes' => 1, 'relatedTasks' => 4]],
			'no-services' => [$all, true, false, []],
			'empty' => [[], true, true, ['relatedFiles' => 1]],
			'scalars' => [['files' => 'x', 'notes' => 'y', 'tasks' => null], true, true, []],
			'only-notes' => [['notes' => [['message' => 'Only']]], true, true, ['other' => true]],
		];
	}//end corpus()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each case creates, skips and counts as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @covers \OCA\OpenRegister\Service\Configuration\SeedRelatedItems
	 *
	 * @dataProvider cases
	 */
	public function testProcessRelatedItemsMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testProcessRelatedItemsMatchesGolden()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: array, 1: bool, 2: bool, 3: array} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$items, $hasUserContext, $withServices, $result] = $case;

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log.'.$level, (string)$message, $context];
				}
			);
		}

		$handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->createMock(RegisterMapper::class),
				schemas: $this->createMock(SchemaMapper::class),
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: $logger,
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);

		if ($withServices === true) {
			$fileService = $this->createMock(FileService::class);
			$fileService->method('addFile')->willReturnCallback(
				function (FileTarget $target, FileUpload $upload) {
					[$object, $name, $content, $share, $tags] = [$target->object, $upload->fileName, $upload->content, $upload->share, $upload->tags];
					$this->calls[] = ['addFile', $object->getUuid(), $name, $content, $share, $tags];
					if ($name === 'fails.txt') {
						throw new \RuntimeException('disk full');
					}

					return $this->createMock(\OCP\Files\File::class);
				}
			);
			$noteService = $this->createMock(NoteService::class);
			$noteService->method('createNote')->willReturnCallback(
				function (string $uuid, string $message): array {
					$this->calls[] = ['createNote', $uuid, $message];
					if ($message === 'fail') {
						throw new \RuntimeException('note failed');
					}

					return [];
				}
			);
			$taskService = $this->createMock(TaskService::class);
			$taskService->method('createObjectTask')->willReturnCallback(
				function (ObjectRef $ref, LinkedTaskDraft $draft): array {
					$args = [$ref->register, $ref->schema, $ref->id, $draft->objectTitle, $draft->data];
					$this->calls[] = ['createTask', $args];
					if ($args[4]['summary'] === 'fail') {
						throw new \RuntimeException('task failed');
					}

					return [];
				}
			);
			$handler->setFileService($fileService);
			$handler->setNoteService($noteService);
			$handler->setTaskService($taskService);
		}//end if

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');

		$target = new SeedRelatedTarget(object: $object);
		$target->items = $items;
		$target->registerId = 4;
		$target->schemaId = 9;
		$target->title = 'Seed title';
		$target->hasUserContext = $hasUserContext;
		(new \ReflectionProperty(ImportHandler::class, 'relatedItems'))->getValue($handler)->create($target, $result);

		return json_decode((string)json_encode(['result' => $result, 'calls' => $this->calls]), true);
	}//end runCase()
}//end class
