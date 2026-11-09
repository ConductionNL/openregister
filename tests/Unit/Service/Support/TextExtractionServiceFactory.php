<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionAnalysis;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionObjects;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionSources;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Builds TextExtractionService from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TextExtractionServiceFactory {
	/**
	 * The constructor arguments of TextExtractionService: its four bundles.
	 *
	 * @param FileMapperInterface      $fileMapper           Mapper for Nextcloud files.
	 * @param ChunkMapper              $chunkMapper          Mapper for chunks.
	 * @param IRootFolder              $rootFolder           Nextcloud root folder.
	 * @param IDBConnection            $db                   Database connection.
	 * @param LoggerInterface          $logger               Logger.
	 * @param MagicMapperInterface     $objectEntityMapper   Mapper for object entities.
	 * @param SchemaMapperInterface    $schemaMapper         Mapper for schemas.
	 * @param RegisterMapperInterface  $registerMapper       Mapper for registers.
	 * @param EntityRecognitionHandler $entityHandler        Handler for entity recognition.
	 * @param SettingsServiceInterface $settingsService      Settings service.
	 * @param RiskLevelService         $riskLevelService     Risk level computation service.
	 * @param EmlParser                $emlParser            EML message parser.
	 * @param SpreadsheetExtractor     $spreadsheetExtractor Extractor for spreadsheet documents.
	 * @param PdfExtractor             $pdfExtractor         Extractor for PDF documents.
	 * @param WordExtractor            $wordExtractor        Extractor for Word documents.
	 *
	 * @return array<int, object>
	 */
	public static function args(
		FileMapperInterface $fileMapper,
		ChunkMapper $chunkMapper,
		IRootFolder $rootFolder,
		IDBConnection $db,
		LoggerInterface $logger,
		MagicMapperInterface $objectEntityMapper,
		SchemaMapperInterface $schemaMapper,
		RegisterMapperInterface $registerMapper,
		EntityRecognitionHandler $entityHandler,
		SettingsServiceInterface $settingsService,
		RiskLevelService $riskLevelService,
		EmlParser $emlParser,
		SpreadsheetExtractor $spreadsheetExtractor,
		PdfExtractor $pdfExtractor,
		WordExtractor $wordExtractor,
	): array {
		return [
			new TextExtractionSources(
				new TextExtractionStorage($fileMapper, $rootFolder, $db),
				new TextExtractionObjects($objectEntityMapper, $schemaMapper, $registerMapper)
			),
			$chunkMapper,
			new TextExtractionAnalysis($entityHandler, $settingsService, $riskLevelService, $logger),
			new TextExtractionReaders($emlParser, $spreadsheetExtractor, $pdfExtractor, $wordExtractor),
		];
	}//end args()

	/**
	 * Build TextExtractionService; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TextExtractionService
	 */
	public static function create(mixed ...$args): TextExtractionService {
		return new TextExtractionService(...self::args(...$args));
	}//end create()
}//end class
