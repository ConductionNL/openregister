<?php

/**
 * Golden master for DocumentProcessingHandler::anonymizeDocument() on a
 * plain-text document.
 *
 * Every case anonymises one text file and records the content written, the
 * calls made on the entity-relation mapper, the placeholder map and the
 * warnings logged. The recording lives in
 * `fixtures/document-anonymize-golden.json`; set `OR_GOLDEN_WRITE=1` to
 * rewrite it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingPlatform;
use OCA\OpenRegister\Files\Service\File\DocumentRedactionServices;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @uses   \OCA\OpenRegister\Files\Service\File\PlaceholderLookup
 * @uses   \OCA\OpenRegister\Files\Service\File\DocumentReplacement
 */
class DocumentProcessingHandlerAnonymizeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/document-anonymize-golden.json';

	private const TEXT = "Jan Jansen woont in De gemeente Amsterdam.\nBSN 123456789, tel 06-12345678.\nJan Jansen schreef aan Piet en aan Amsterdam.";

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$entities = [
			['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1'],
			['text' => 'Amsterdam', 'entityType' => 'LOCATION', 'key' => 'l1'],
			['text' => 'gemeente Amsterdam', 'entityType' => 'ORGANIZATION', 'key' => 'o1'],
			['text' => '123456789', 'entityType' => 'BSN', 'key' => 'b1'],
			['text' => '06-12345678 ', 'entityType' => 'PHONE', 'key' => 't1'],
			['text' => 'Piet', 'entityType' => 'PERSON', 'key' => 'p2'],
			['text' => '', 'entityType' => 'PERSON', 'key' => 'e0'],
			['text' => '   ', 'entityType' => 'PERSON', 'key' => 'e1'],
			['text' => 'schreef', 'key' => 'x1'],
		];
		$ids = [
			'Jan Jansen' => ['id' => 501, 'type' => 'PERSON'],
			'Piet' => ['id' => 777, 'type' => 'PERSON'],
			'06-12345678 ' => ['id' => 300, 'type' => 'PHONE'],
		];
		return [
			'no-file-id' => ['id' => 0, 'entities' => $entities],
			'plain' => ['entities' => $entities],
			'skipped' => ['entities' => $entities, 'skipped' => ['Piet', '123456789']],
			'skipped-numeric-lookalike' => ['entities' => $entities, 'skipped' => ['0123456789', ' Piet']],
			'stable-ids' => ['entities' => $entities, 'ids' => $ids],
			'stable-ids-dutch' => ['entities' => $entities, 'ids' => $ids, 'dutch' => true],
			'mark-fails' => ['entities' => $entities, 'ids' => $ids, 'markThrows' => true],
			'nothing-to-replace' => ['entities' => [['text' => 'absent', 'entityType' => 'PERSON', 'key' => 'k']], 'skipped' => ['absent']],
			'no-extension' => ['entities' => $entities, 'name' => 'letter'],
			'dossier-parent' => ['entities' => $entities, 'ids' => $ids, 'scope' => 'dossier'],
			'dossier-key' => ['entities' => $entities, 'ids' => $ids, 'scope' => 'dossier', 'fileKey' => ' 99 '],
			'dossier-empty' => ['entities' => $entities, 'ids' => $ids, 'scope' => 'dossier', 'emptyFolder' => true],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testAnonymizeMatchesGolden(string $name): void {
		$recorded = $this->record(scenario: self::scenarios()[$name]);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $recorded);
	}//end testAnonymizeMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$trace = [];
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * @param array<int, mixed> $trace
			 */
			public function __construct(private array &$trace) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};

		$mapper = $this->createMock(EntityRelationMapper::class);
		$mapper->method('findSkippedEntityValuesForFile')->willReturnCallback(
			function (int $fileId) use (&$trace, $scenario): array {
				$trace[] = ['findSkippedEntityValuesForFile' => $fileId];
				return $scenario['skipped'] ?? [];
			}
		);
		$mapper->method('findEntityIdsByValueForFile')->willReturnCallback(
			function (int $fileId) use (&$trace, $scenario): array {
				$trace[] = ['findEntityIdsByValueForFile' => $fileId];
				return $scenario['ids'] ?? [];
			}
		);
		$mapper->method('findEntityIdsByValueForFiles')->willReturnCallback(
			function (array $fileIds) use (&$trace): array {
				$trace[] = ['findEntityIdsByValueForFiles' => $fileIds];
				return [
					['entity_id' => 777, 'file_id' => 8, 'position_start' => 3],
					['entity_id' => 501, 'file_id' => 42, 'position_start' => 0],
					['entity_id' => 300, 'file_id' => 42, 'position_start' => 40],
				];
			}
		);
		$mapper->method('markAsAnonymizedWithPlaceholders')->willReturnCallback(
			function (int $fileId, array $map) use (&$trace, $scenario): int {
				$trace[] = ['markAsAnonymizedWithPlaceholders' => [$fileId, $map]];
				if (($scenario['markThrows'] ?? false) === true) {
					throw new RuntimeException('db gone');
				}

				return count($map);
			}
		);

		$l10n = null;
		if (($scenario['dutch'] ?? false) === true) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $text): string => strtoupper($text) . '-NL');
		}

		$sanitizer = $this->createMock(OfficeDocumentSanitizer::class);
		$sanitizer->method('isSanitizable')->willReturn(false);

		$keyed = $this->createMock(Folder::class);
		$keyed->method('getId')->willReturn(99);
		$sibling = $this->createMock(File::class);
		$sibling->method('getId')->willReturn(8);
		$keyed->method('getDirectoryListing')->willReturn([$sibling]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->willReturnCallback(
			function (int $id) use (&$trace, $keyed): array {
				$trace[] = ['getById' => $id];
				return [$keyed];
			}
		);

		$handler = new DocumentProcessingHandler(
			platform: new DocumentProcessingPlatform(
				rootFolder: $rootFolder,
				userSession: $this->createMock(IUserSession::class),
				l10n: $l10n
			),
			redaction: new DocumentRedactionServices(
				entityRelationMapper: $mapper,
				sanitizer: $sanitizer
			),
			logger: $logger
		);

		$written = [];
		$node = $this->createMock(File::class);
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn(7);
		$parent->method('nodeExists')->willReturn(false);
		$listing = [$node];
		if (($scenario['emptyFolder'] ?? false) === true) {
			$listing = [];
		}

		$parent->method('getDirectoryListing')->willReturn($listing);
		$parent->method('newFile')->willReturnCallback(
			function (string $path, $content) use (&$written): File {
				$written[] = ['path' => $path, 'content' => $content];
				$out = $this->createMock(File::class);
				$out->method('getPath')->willReturn('/files/' . $path);
				return $out;
			}
		);
		$node->method('getId')->willReturn($scenario['id'] ?? 42);
		$node->method('getName')->willReturn($scenario['name'] ?? 'letter.txt');
		$node->method('getPath')->willReturn('/files/letter.txt');
		$node->method('getMimeType')->willReturn('text/plain');
		$node->method('getContent')->willReturn(self::TEXT);
		$node->method('getParent')->willReturn($parent);

		$handler->anonymizeDocument(
			node: $node,
			entities: $scenario['entities'],
			scope: ($scenario['scope'] ?? 'document'),
			fileKey: ($scenario['fileKey'] ?? null)
		);

		return [
			'written' => $written,
			'placeholders' => $handler->getLastPlaceholderMap(),
			'residuals' => $handler->getLastResidualEntities(),
			'trace' => $trace,
		];
	}//end record()
}//end class
