<?php

/**
 * Characterisation of ViewFormatting::managing().
 *
 * Pins which configuration manages a view: none without configurations or
 * without a view id, the first whose views list the id, and a strict
 * comparison of that id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Db;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Search\Db\ViewFormatting;
use PHPUnit\Framework\TestCase;

class ViewFormattingManagingTest extends TestCase {

	private static function configuration(array $views): Configuration {
		$configuration = new Configuration();
		$configuration->setViews($views);
		return $configuration;
	}//end configuration()

	public function testNoConfigurationsOrNoIdManageNothing(): void {
		$this->assertNull(ViewFormatting::managing(viewId: 5, configurations: []));
		$this->assertNull(ViewFormatting::managing(viewId: null, configurations: [self::configuration([5])]));
	}//end testNoConfigurationsOrNoIdManageNothing()

	public function testTheFirstListingConfigurationManages(): void {
		$first = self::configuration([5, 6]);
		$second = self::configuration([6]);
		$other = self::configuration([7]);

		$this->assertSame($first, ViewFormatting::managing(viewId: 6, configurations: [$other, $first, $second]));
		$this->assertSame($second, ViewFormatting::managing(viewId: 6, configurations: [$other, $second, $first]));
		$this->assertNull(ViewFormatting::managing(viewId: 8, configurations: [$other, $first, $second]));
	}//end testTheFirstListingConfigurationManages()

	public function testTheIdIsComparedStrictly(): void {
		$configuration = self::configuration([5, 'u-1']);

		$this->assertNull(ViewFormatting::managing(viewId: '5', configurations: [$configuration]));
		$this->assertSame($configuration, ViewFormatting::managing(viewId: 'u-1', configurations: [$configuration]));
		$this->assertSame($configuration, ViewFormatting::managing(viewId: 5, configurations: [$configuration]));
	}//end testTheIdIsComparedStrictly()
}//end class
