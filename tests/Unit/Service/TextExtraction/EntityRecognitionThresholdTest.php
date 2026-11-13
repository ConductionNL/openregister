<?php

/**
 * The detection confidence threshold is an organisation setting (REQ-ADI-007).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-confidence-threshold-is-an-organisation-setting-req-adi-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Exception\ConfidenceThresholdOutOfRangeException;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionStores;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every detection path reads the threshold from the file settings at request time.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 */
class EntityRecognitionThresholdTest extends TestCase {

	/**
	 * The stored fileManagement JSON the app config answers with.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * The file settings handler over an app config holding $stored.
	 *
	 * @return FileSettingsHandler
	 */
	private function fileSettings(): FileSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === 'fileManagement' ? $this->stored : $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === 'fileManagement') {
					$this->stored = $value;
				}

				return true;
			}
		);

		return new FileSettingsHandler($appConfig);
	}//end fileSettings()

	/**
	 * The handler under test, reading the threshold from the given settings.
	 *
	 * @param FileSettingsHandler|null $settings The file settings.
	 *
	 * @return EntityRecognitionHandler
	 */
	private function handler(?FileSettingsHandler $settings): EntityRecognitionHandler {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$expr->method('eq')->willReturn('1=1');
		$qb->method('createNamedParameter')->willReturn('?');
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$entityMapper = $this->createMock(GdprEntityMapper::class);
		$entityMapper->method('findEntitiesPublic')->willReturn([]);
		$stored = new GdprEntity();
		$stored->setId(1);
		$entityMapper->method('insert')->willReturn($stored);

		return new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $entityMapper,
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				db: $db
			),
			backends: new EntityRecognitionBackends(
				settingsService: $this->createMock(SettingsService::class),
				anonymisationBackendService: $this->createMock(AnonymisationBackendService::class)
			),
			logger: $this->createMock(LoggerInterface::class),
			fileSettings: $settings
		);
	}//end handler()

	/**
	 * A chunk holding an e-mail address (regex confidence 0.9) and a phone number (0.7).
	 *
	 * @return Chunk
	 */
	private function chunk(): Chunk {
		$chunk = $this->getMockBuilder(Chunk::class)
			->addMethods(['getId', 'getSourceType', 'getSourceId', 'getTextContent', 'getChunkIndex'])
			->getMock();
		$chunk->method('getId')->willReturn(1);
		$chunk->method('getSourceType')->willReturn('file');
		$chunk->method('getSourceId')->willReturn(7);
		$chunk->method('getTextContent')->willReturn('Mail jan@example.nl or call +31612345678.');
		$chunk->method('getChunkIndex')->willReturn(0);

		return $chunk;
	}//end chunk()

	/**
	 * The types the handler kept for the chunk.
	 *
	 * @param EntityRecognitionHandler $handler The handler.
	 *
	 * @return array<int, string>
	 */
	private function keptTypes(EntityRecognitionHandler $handler): array {
		$types = array_column(
			$handler->extractFromChunk(
				$this->chunk(),
				['method' => 'regex', 'entity_types' => [EntityRecognitionHandler::ENTITY_TYPE_EMAIL, EntityRecognitionHandler::ENTITY_TYPE_PHONE]]
			)['entities'],
			'type'
		);
		sort($types);

		return $types;
	}//end keptTypes()

	/**
	 * A raised threshold drops the weaker finding; without a setting the default 0.5 keeps both.
	 *
	 * @return void
	 */
	public function testTheSettingReplacesTheLiteral(): void {
		$settings = $this->fileSettings();

		$this->assertSame(['EMAIL', 'PHONE'], $this->keptTypes(handler: $this->handler(settings: $settings)));

		$settings->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => 0.8]]);
		$this->assertSame(0.8, $settings->confidenceThreshold());
		$this->assertSame(['EMAIL'], $this->keptTypes(handler: $this->handler(settings: $settings)));
	}//end testTheSettingReplacesTheLiteral()

	/**
	 * A caller that names a threshold still wins over the setting.
	 *
	 * @return void
	 */
	public function testACallersThresholdWinsOverTheSetting(): void {
		$settings = $this->fileSettings();
		$settings->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => 0.95]]);

		$types = array_column(
			$this->handler(settings: $settings)->extractFromChunk(
				$this->chunk(),
				['method' => 'regex', 'entity_types' => [EntityRecognitionHandler::ENTITY_TYPE_EMAIL], 'confidence_threshold' => 0.5]
			)['entities'],
			'type'
		);

		$this->assertSame(['EMAIL'], $types);
	}//end testACallersThresholdWinsOverTheSetting()

	/**
	 * A value outside 0 to 1, or not a number, is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnOutOfRangeValueIsRefused(): void {
		$settings = $this->fileSettings();
		$settings->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => 0.7]]);
		$before = $this->stored;

		foreach ([1.5, -0.1, 'high', true] as $value) {
			try {
				$settings->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => $value]]);
				$this->fail('Expected ' . var_export($value, true) . ' to be refused.');
			} catch (ConfidenceThresholdOutOfRangeException $e) {
				$this->assertSame('anonymisation.confidenceThreshold must be a number between 0 and 1', $e->getMessage());
			}
		}

		$this->assertSame($before, $this->stored);
		$this->assertSame(0.7, $settings->confidenceThreshold());
	}//end testAnOutOfRangeValueIsRefused()

	/**
	 * A save that does not name the threshold keeps the stored one; a read always carries it.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheThresholdKeepsIt(): void {
		$settings = $this->fileSettings();
		$this->assertSame(0.5, $settings->getFileSettingsOnly()['anonymisation']['confidenceThreshold']);

		$settings->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => '0.65']]);
		$settings->updateFileSettingsOnly(['ocrEnabled' => true]);

		$this->assertSame(0.65, $settings->getFileSettingsOnly()['anonymisation']['confidenceThreshold']);
	}//end testASaveWithoutTheThresholdKeepsIt()

	/**
	 * No detection path in lib/ carries the old literal any more.
	 *
	 * @return void
	 */
	public function testNoLiteralThresholdRemainsInLib(): void {
		$lib = dirname(__DIR__, 4) . '/lib';
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string) file_get_contents($file->getPathname());
			if (preg_match("/'confidence_threshold'\\]?\\s*(=>|\\?\\?)\\s*0\\.5/", $source) === 1) {
				$found[] = substr($file->getPathname(), strlen($lib) + 1);
			}
		}

		$this->assertSame([], $found);
	}//end testNoLiteralThresholdRemainsInLib()
}//end class
