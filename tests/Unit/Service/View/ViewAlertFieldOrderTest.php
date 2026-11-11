<?php

/**
 * Pins WHICH field a malformed view alert names when several are wrong.
 *
 * The field checks run in a fixed order and each one throws, so the order
 * decides the field an administrator is sent to fix.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\View
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\View;

use OCA\OpenRegister\Exception\InvalidViewAlertException;
use OCA\OpenRegister\Service\View\ViewAlert;
use PHPUnit\Framework\TestCase;

/**
 * The first wrong field in check order is the one named.
 */
class ViewAlertFieldOrderTest extends TestCase {

	/**
	 * The field the refusal of one alert names.
	 *
	 * @param array<string, mixed> $alert The declared alert.
	 *
	 * @return string The field it names.
	 */
	private static function refusal(array $alert): string {
		try {
			ViewAlert::parse($alert);
		} catch (InvalidViewAlertException $e) {
			return $e->getField();
		}

		return 'accepted';
	}//end refusal()

	public function testTheFirstWrongFieldInCheckOrderIsNamed(): void {
		$valid = ['operator' => 'gte', 'threshold' => 3, 'recipients' => ['user:anna']];

		$this->assertSame('alert.channels', self::refusal($valid + ['channels' => 5, 'every' => 'never']));
		$this->assertSame('alert.channels', self::refusal($valid + ['channels' => [7], 'every' => -1]));
		$this->assertSame('alert.recipients', self::refusal(['recipients' => 5, 'channels' => 5] + $valid));
	}//end testTheFirstWrongFieldInCheckOrderIsNamed()
}//end class
