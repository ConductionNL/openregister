<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Chat;

use Exception;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for ContextRetrievalHandler::retrieveContext().
 *
 * Each case is an agent, selected views, RAG settings and scripted search
 * results. The returned context, every search call and every log entry
 * (without its file and line) are compared with fixtures/context-retrieval-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class ContextRetrievalGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/context-retrieval-golden.json';

	/** @var list<array<int, mixed>> Every collaborator call and log entry, in order. */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$obj = static fn (string $id, array $extra = []): array => ['entity_type' => 'object', 'entity_id' => $id, 'chunk_text' => 'text ' . $id] + $extra;
		$file = static fn (string $id, array $extra = []): array => ['entity_type' => 'file', 'entity_id' => $id, 'text' => 'file ' . $id] + $extra;
		$mixed = [
			$obj('o1', ['title' => 'Object one', 'similarity' => 0.9, 'metadata' => ['uuid' => 'u1', 'register_id' => 3, 'schema' => 4, 'uri' => '/o1']]),
			$file('f1', ['score' => 0.8, 'metadata' => '{"file_id": 11, "file_path": "/a.pdf", "mime_type": "application/pdf", "file_name": "a.pdf"}']),
			$obj('o2', ['metadata' => '{"uuid": "u2", "register": 5, "schema_id": 6, "object_title": "Two"}']),
			$file('f2', ['filename' => 'b.txt']),
			$obj('o3'),
			$file('f3', ['metadata' => ['name' => 'Third file']]),
			'not an array',
			['entity_type' => 'note', 'entity_id' => 'n1'],
			[],
		];
		return [
			'no agent hybrid default' => [['keyword' => [['id' => 'k1', '_score' => 2.0], ['uuid' => 'k2', '_source' => ['data' => 'raw']]], 'hybrid' => ['results' => $mixed]]],
			'no agent hybrid without results key' => [['hybrid' => ['count' => 0]]],
			'hybrid non-array results' => [['hybrid' => ['results' => 'oops']]],
			'semantic mode' => [['agent' => ['mode' => 'semantic', 'num' => 2], 'semantic' => $mixed]],
			'keyword mode' => [['agent' => ['mode' => 'keyword', 'num' => 1], 'keyword' => [['id' => 'k1', 'title' => 'x'], ['id' => 'k2']]]],
			'unknown mode keeps keyword' => [['agent' => ['mode' => 'fuzzy'], 'keyword' => [['id' => 'k1']]]],
			'objects only' => [['agent' => ['mode' => 'semantic', 'files' => false], 'semantic' => $mixed]],
			'files only' => [['agent' => ['mode' => 'semantic', 'objects' => false], 'semantic' => $mixed]],
			'nothing included' => [['agent' => ['mode' => 'semantic', 'objects' => false, 'files' => false], 'semantic' => $mixed]],
			'rag settings override' => [['agent' => ['mode' => 'semantic', 'files' => false], 'rag' => ['includeFiles' => true, 'numSourcesFiles' => 1, 'numSourcesObjects' => 3], 'semantic' => $mixed]],
			'rag settings exclude objects' => [['rag' => ['includeObjects' => false, 'numSourcesFiles' => 2], 'hybrid' => ['results' => $mixed]]],
			'limits reached early' => [['agent' => ['mode' => 'semantic', 'num' => 1], 'semantic' => $mixed]],
			'agent views all' => [['agent' => ['mode' => 'semantic', 'views' => ['v1', 'v2']], 'semantic' => $mixed, 'inView' => ['u1']]],
			'agent views filtered by selection' => [['agent' => ['mode' => 'semantic', 'views' => ['v1', 'v2']], 'selected' => ['v2', 'v9'], 'semantic' => $mixed, 'inView' => ['u2', 'o3']]],
			'agent views none left' => [['agent' => ['mode' => 'semantic', 'views' => ['v1']], 'selected' => ['v9'], 'semantic' => $mixed]],
			'agent empty views with selection' => [['agent' => ['mode' => 'semantic', 'views' => []], 'selected' => ['v3'], 'semantic' => $mixed, 'inView' => ['u1', 'u2', 'o3']]],
			'no agent with selection' => [['selected' => ['v3'], 'hybrid' => ['results' => $mixed], 'inView' => []]],
			'view lookup fails' => [['agent' => ['mode' => 'semantic', 'views' => ['v1']], 'semantic' => $mixed, 'inViewThrows' => true]],
			'semantic throws' => [['agent' => ['mode' => 'semantic'], 'semanticThrows' => true]],
			'keyword throws' => [['keywordThrows' => true]],
			'source name fallbacks' => [[
				'agent' => ['mode' => 'semantic', 'num' => 10],
				'semantic' => [
					['entity_type' => 'object', 'entity_id' => 'abcdefghijkl', 'metadata' => ['title' => 'Meta title']],
					['entity_type' => 'object', 'entity_id' => 'abcdefghijkl'],
					['entity_type' => 'object', 'entity_id' => '', 'name' => 'Named'],
					['entity_type' => 'file'],
					['entity_type' => 'object', 'metadata' => '{"broken'],
				],
			]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testRetrieveContextMatchesGolden(array $case): void {
		$name = $this->dataName();
		$agent = null;
		if (isset($case['agent']) === true) {
			$agent = new Agent();
			$agent->setRagSearchMode($case['agent']['mode'] ?? null);
			$agent->setRagNumSources($case['agent']['num'] ?? null);
			$agent->setSearchFiles($case['agent']['files'] ?? null);
			$agent->setSearchObjects($case['agent']['objects'] ?? null);
			if (array_key_exists('views', $case['agent']) === true) {
				$agent->setViews($case['agent']['views']);
			}
		}

		$result = $this->handler($case)->retrieveContext('find the thing', $agent, $case['selected'] ?? [], $case['rag'] ?? []);
		$actual = json_decode(json_encode(['result' => $result, 'calls' => $this->calls]), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testRetrieveContextMatchesGolden()

	/**
	 * @param array<string, mixed> $case
	 */
	private function handler(array $case): ContextRetrievalHandler {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($case): array {
				$this->calls[] = ['keyword', $query];
				if (($case['keywordThrows'] ?? false) === true) {
					throw new Exception('index down');
				}

				return ['results' => ($case['keyword'] ?? [])];
			}
		);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query, ...$rest) use ($case): array {
				$this->calls[] = ['inView', $query, $rest];
				if (($case['inViewThrows'] ?? false) === true) {
					throw new Exception('view gone');
				}

				$found = [];
				foreach (($case['inView'] ?? []) as $uuid) {
					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$found[] = $entity;
				}

				return $found;
			}
		);

		$vectors = $this->createMock(VectorEmbeddings::class);
		$vectors->method('semanticSearch')->willReturnCallback(
			function (string $query, int $limit, array $filters) use ($case): array {
				$this->calls[] = ['semantic', $query, $limit, $filters];
				if (($case['semanticThrows'] ?? false) === true) {
					throw new Exception('vector store down');
				}

				return ($case['semantic'] ?? []);
			}
		);
		$vectors->method('hybridSearch')->willReturnCallback(
			function (string $query, array $keywordResults, int $limit) use ($case): array {
				$this->calls[] = ['hybrid', $query, $keywordResults, $limit];
				return ($case['hybrid'] ?? []);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log', $level, $message, $context];
				}
			);
		}

		return new ContextRetrievalHandler($vectors, $objects, $logger);
	}//end handler()
}//end class
