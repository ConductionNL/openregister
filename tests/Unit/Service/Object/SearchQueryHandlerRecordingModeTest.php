<?php

/**
 * OpenRegister SearchQueryHandlerRecordingModeTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the retention settings mean for recording a search, and the failures it survives.
 *
 * @covers \OCA\OpenRegister\Service\Object\SearchQueryHandler
 */
final class SearchQueryHandlerRecordingModeTest extends TestCase {
	/**
	 * Retention settings and the mode they stand for.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function settings(): array {
		return [
			'nothing set'        => [[], '_search'],
			'all'                => [['searchTrailRecordingMode' => 'all'], 'all'],
			'search only'        => [['searchTrailRecordingMode' => '_search'], '_search'],
			'none'               => [['searchTrailRecordingMode' => 'none'], 'none'],
			'an unknown mode'    => [['searchTrailRecordingMode' => 'everything'], '_search'],
			'not a text'         => [['searchTrailRecordingMode' => 1], '_search'],
			'disabled wins'      => [['searchTrailsEnabled' => false, 'searchTrailRecordingMode' => 'all'], 'none'],
			'enabled'            => [['searchTrailsEnabled' => true, 'searchTrailRecordingMode' => 'all'], 'all'],
			'only false is off'  => [['searchTrailsEnabled' => 0, 'searchTrailRecordingMode' => 'all'], 'all'],
		];
	}//end settings()

	/**
	 * The settings decide the mode.
	 *
	 * @param array<string, mixed> $settings The retention settings.
	 * @param string               $expected The mode.
	 *
	 * @return void
	 */
	#[DataProvider('settings')]
	public function testTheSettingsDecide(array $settings, string $expected): void {
		$service = $this->createMock(SettingsService::class);
		$service->method('getRetentionSettingsOnly')->willReturn($settings);

		$handler = $this->handler(settings: $service, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame($expected, $handler->getEffectiveRecordingMode());
	}//end testTheSettingsDecide()

	/**
	 * Settings that cannot be read default to `_search`, and say so.
	 *
	 * @return void
	 */
	public function testUnreadableSettingsDefaultAndWarn(): void {
		$service = $this->createMock(SettingsService::class);
		$service->method('getRetentionSettingsOnly')->willThrowException(new Exception('no table'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[SearchQueryHandler] Failed to read recording mode, defaulting to _search',
			$this->callback(static fn (array $context): bool => $context['error'] === 'no table' && isset($context['file'], $context['line']))
		);

		$this->assertSame('_search', $this->handler(settings: $service, logger: $logger)->getEffectiveRecordingMode());
	}//end testUnreadableSettingsDefaultAndWarn()

	/**
	 * A trail that cannot be written is logged, not counted and not thrown.
	 *
	 * @return void
	 */
	public function testAFailedTrailIsWarnedAboutAndNotCounted(): void {
		$service = $this->createMock(SettingsService::class);
		$service->method('getRetentionSettingsOnly')->willReturn(['searchTrailRecordingMode' => 'all']);
		$trails = $this->createMock(SearchTrailService::class);
		$trails->method('createSearchTrail')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('disk full')),
			$this->createStub(\OCA\OpenRegister\Db\SearchTrail::class)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[SearchQueryHandler] Failed to record search trail',
			$this->callback(static fn (array $context): bool => $context['error'] === 'disk full' && isset($context['file'], $context['line']))
		);

		$handler = $this->handler(settings: $service, logger: $logger, trails: $trails);
		$handler->logSearchTrail(['_search' => 'a'], 1, 1, 0.5);
		$handler->logSearchTrail(['_search' => 'b'], 2, 2, 0.5, 'async');

		$this->assertSame(1, $handler->flushSearchTrails());
	}//end testAFailedTrailIsWarnedAboutAndNotCounted()

	/**
	 * The handler over these collaborators.
	 *
	 * @param SettingsService         $settings The settings.
	 * @param LoggerInterface         $logger   The logger.
	 * @param SearchTrailService|null $trails   The trail writer, a silent one when left out.
	 *
	 * @return SearchQueryHandler The handler.
	 */
	private function handler(SettingsService $settings, LoggerInterface $logger, ?SearchTrailService $trails = null): SearchQueryHandler {
		return new SearchQueryHandler(
			$this->createMock(ViewScopeApplier::class),
			$this->createMock(SchemaMapper::class),
			$settings,
			$logger,
			$this->createMock(IRequest::class),
			$trails ?? $this->createMock(SearchTrailService::class)
		);
	}//end handler()
}//end class
