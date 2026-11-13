<?php

/**
 * The Search job handles lane M08 turned into an interface constant holder.
 *
 * Pins that the handle is an interface, that its value is still exactly the job
 * class string the job list received before, and that the caller outside
 * lib/Search names only the interface.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Search\BackgroundJob\SearchIndexRebuildJob;
use OCA\OpenRegister\Search\BackgroundJob\SearchJobHandlesInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class M08SearchJobHandlesInterfaceTest extends TestCase {

	/**
	 * The caller outside lib/Search.
	 *
	 * @var string
	 */
	private const CALLER = __DIR__ . '/../../../lib/Service/Operations/OperationsJobsService.php';

	/**
	 * The handle is an interface constant equal to the job class string.
	 *
	 * @return void
	 */
	public function testTheHandleIsAnInterfaceConstantNamingTheJob(): void {
		$this->assertTrue(interface_exists(SearchJobHandlesInterface::class));
		$this->assertSame(SearchIndexRebuildJob::class, SearchJobHandlesInterface::SEARCH_INDEX_REBUILD);
	}//end testTheHandleIsAnInterfaceConstantNamingTheJob()

	/**
	 * The operations console names the interface, not a concrete Search class.
	 *
	 * @return void
	 */
	public function testTheCallerNamesOnlyTheInterface(): void {
		$source = (string) file_get_contents(self::CALLER);
		$this->assertMatchesRegularExpression('/\bSearchJobHandlesInterface\b/', $source);
		$this->assertDoesNotMatchRegularExpression('/\bSearchJobHandles\b/', $source);
		$this->assertDoesNotMatchRegularExpression('/\bSearchIndexRebuildJob\b/', $source);
	}//end testTheCallerNamesOnlyTheInterface()
}//end class
