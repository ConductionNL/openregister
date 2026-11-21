<?php

/**
 * Unit tests for the hardening value objects: they carry exactly what they were given, a publication trims its text but not its author, and a switch read as 0 is off.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\HardeningChange;
use OCA\OpenRegister\Service\Hardening\HardeningControl;
use OCA\OpenRegister\Service\Hardening\HardeningControlLabel;
use OCA\OpenRegister\Service\Hardening\HardeningReading;
use OCA\OpenRegister\Service\Hardening\StatementPublication;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfaces;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningChange
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningControl
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningControlLabel
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningReading
 * @covers \OCA\OpenRegister\Service\Hardening\StatementPublication
 * @covers \OCA\OpenRegister\Service\Hardening\ThrottledSurfaces
 */
class HardeningValuesTest extends TestCase {

	public function testAChangeCarriesItsControlAndBothValues(): void {
		$change = new HardeningChange(control: 'origins.allowlistEntries', before: ['https://a.nl'], after: []);

		$this->assertSame('origins.allowlistEntries', $change->control);
		$this->assertSame(['https://a.nl'], $change->before);
		$this->assertSame([], $change->after);
	}

	public function testALabelAndAReadingCarryWhatTheyWereGiven(): void {
		$label = new HardeningControlLabel(id: 'tls.enforced', title: 'TLS verplicht', category: 'transport', source: 'platform');
		$reading = new HardeningReading(value: null, floor: 1, comparator: 'atLeast', unit: 'switch');

		$this->assertSame(['tls.enforced', 'TLS verplicht', 'transport', 'platform'], [$label->id, $label->title, $label->category, $label->source]);
		$this->assertSame([null, 1, 'atLeast', 'switch'], [$reading->value, $reading->floor, $reading->comparator, $reading->unit]);
	}

	public function testASwitchReadAsZeroIsOffAndAsOneIsOn(): void {
		$label = new HardeningControlLabel(id: 'tls.enforced', title: 'TLS', category: 'transport', source: 'platform');

		$this->assertSame('off', (new HardeningControl(label: $label, reading: new HardeningReading(value: 0, floor: 1, comparator: 'atLeast', unit: 'switch')))->state());
		$this->assertSame('on', (new HardeningControl(label: $label, reading: new HardeningReading(value: 1, floor: 1, comparator: 'atLeast', unit: 'switch')))->state());
		$this->assertSame('unknown', (new HardeningControl(label: $label, reading: new HardeningReading(value: null, floor: 1, comparator: 'atLeast', unit: 'switch')))->state());
	}

	public function testATrimmedPublicationTrimsItsTextButKeepsTheAuthor(): void {
		$trimmed = (new StatementPublication(version: ' 2 ', body: "\nWij beveiligen\n", title: '  Verklaring ', publishedBy: ' admin '))->trimmed();

		$this->assertSame(['2', 'Wij beveiligen', 'Verklaring', ' admin '], [$trimmed->version, $trimmed->body, $trimmed->title, $trimmed->publishedBy]);
	}

	public function testTheThrottledSurfaceCountIsTheNumberOfSurfaces(): void {
		$this->assertSame(count(ThrottledSurfaces::ALL), ThrottledSurfaces::count());
		$this->assertGreaterThan(0, ThrottledSurfaces::count());
	}
}
