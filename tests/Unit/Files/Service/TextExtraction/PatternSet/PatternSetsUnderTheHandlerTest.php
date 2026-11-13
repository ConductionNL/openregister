<?php

/**
 * The handler runs the enabled pattern sets the file settings name, and only those.
 *
 * Acceptance of detection-dutch-licence-plates: a file with `12-GBK-3` and a
 * valid BSN yields one LICENSE_PLATE and one SSN with the regex method, and an
 * instance with `entityPatternSets: []` yields neither.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction\PatternSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction\PatternSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityDetectionQuery;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionStores;
use OCA\OpenRegister\Service\Anonymisation\AnonymiserRequestInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionBackends;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler
 */
final class PatternSetsUnderTheHandlerTest extends TestCase {

	private const TEXT = 'Het voertuig met kenteken 12-GBK-3 van BSN 111222333.';

	private function detect(SettingsServiceInterface $settings, ?LoggerInterface $logger=null): array {
		$handler = new EntityRecognitionHandler(
			stores: new EntityRecognitionStores(
				chunkMapper: $this->createMock(ChunkMapper::class),
				entityMapper: $this->createMock(GdprEntityMapper::class),
				entityRelationMapper: $this->createMock(EntityRelationMapper::class),
				db: $this->createMock(IDBConnection::class)
			),
			backends: new EntityRecognitionBackends(
				settingsService: $settings,
				anonymisationBackendService: $this->createMock(AnonymiserRequestInterface::class)
			),
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);

		$detect = new ReflectionMethod(EntityRecognitionHandler::class, 'detectEntities');

		return array_values(array_map(
			static fn (array $e): string => $e['type'].':'.$e['value'],
			array_filter(
				$detect->invoke($handler, new EntityDetectionQuery(self::TEXT, null, 0.5), EntityRecognitionHandler::METHOD_REGEX),
				static fn (array $e): bool => in_array($e['type'], ['LICENSE_PLATE', 'SSN', 'PHONE'], true)
			)
		));
	}

	private function settings(array $fileSettings): SettingsServiceInterface {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->once())->method('getFileSettingsOnly')->willReturn($fileSettings);

		return $settings;
	}

	public function testTheDutchSetFindsOnePlateAndOneBsnWithTheRegexMethod(): void {
		$this->assertSame(['SSN:111222333', 'LICENSE_PLATE:12-GBK-3'], $this->detect($this->settings(['entityPatternSets' => ['nl']])));
	}

	public function testWithNoSetEnabledNeitherIsFound(): void {
		$found = $this->detect($this->settings(['entityPatternSets' => []]));

		$this->assertNotContains('LICENSE_PLATE:12-GBK-3', $found);
		$this->assertNotContains('SSN:111222333', $found);
	}

	public function testAnUnreadableSettingRunsEverySetRatherThanLeak(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getFileSettingsOnly')->willThrowException(new RuntimeException('settings unreadable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('running all'));

		$this->assertSame(['SSN:111222333', 'LICENSE_PLATE:12-GBK-3'], $this->detect($settings, $logger));
	}

	public function testTheCategoryOfAPlateIsPersonalData(): void {
		$this->assertSame(
			EntityRecognitionHandler::CATEGORY_PERSONAL_DATA,
			EntityRecognitionHandler::getCategoryForType(EntityRecognitionHandler::ENTITY_TYPE_LICENSE_PLATE)
		);
	}
}
