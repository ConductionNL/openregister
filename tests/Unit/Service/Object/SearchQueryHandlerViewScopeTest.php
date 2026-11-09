<?php

/**
 * OpenRegister SearchQueryHandlerViewScopeTest
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

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A caller whose only bound is the view reaches the scope that fails closed.
 *
 * @covers \OCA\OpenRegister\Service\Object\SearchQueryHandler
 */
final class SearchQueryHandlerViewScopeTest extends TestCase {
	/**
	 * The required scope answers for a caller that needs the view.
	 *
	 * @return void
	 */
	public function testARequiredScopeFailsClosed(): void {
		$scope = $this->createMock(ViewScopeApplier::class);
		$scope->expects($this->never())->method('apply');
		$scope->expects($this->once())->method('applyRequired')->with(['a' => 1], [7])->willReturn(['b' => 2]);

		$this->assertSame(['b' => 2], $this->handler($scope)->applyViewsToQuery(['a' => 1], [7], _viewScopeRequired: true));
	}//end testARequiredScopeFailsClosed()

	/**
	 * The plain scope answers for every other caller.
	 *
	 * @return void
	 */
	public function testAPlainScopeStaysTolerant(): void {
		$scope = $this->createMock(ViewScopeApplier::class);
		$scope->expects($this->never())->method('applyRequired');
		$scope->expects($this->once())->method('apply')->with(['a' => 1], [7])->willReturn(['b' => 2]);

		$this->assertSame(['b' => 2], $this->handler($scope)->applyViewsToQuery(['a' => 1], [7]));
	}//end testAPlainScopeStaysTolerant()

	/**
	 * The handler over one scope.
	 *
	 * @param ViewScopeApplier $scope The scope.
	 *
	 * @return SearchQueryHandler The handler.
	 */
	private function handler(ViewScopeApplier $scope): SearchQueryHandler {
		return new SearchQueryHandler(
			$scope,
			$this->createMock(SchemaMapper::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SearchTrailService::class)
		);
	}//end handler()
}//end class
