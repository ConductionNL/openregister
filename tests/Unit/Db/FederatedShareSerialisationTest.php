<?php

/**
 * FederatedShare serialises its timestamps as ISO 8601 and an unset timestamp as null.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\FederatedShare;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of FederatedShare::jsonSerialize() for its timestamps.
 */
class FederatedShareSerialisationTest extends TestCase {

	public function testTimestampsAreSerialisedAsIso8601InTheirOwnZone(): void {
		$share = new FederatedShare();
		$share->setUuid('s-1');
		$share->setStatus('active');
		$share->setCreated(new DateTime('2026-03-01 08:15:00', new DateTimeZone('UTC')));
		$share->setUpdated(new DateTime('2026-03-02 09:30:00', new DateTimeZone('+02:00')));

		$json = $share->jsonSerialize();

		$this->assertSame('2026-03-01T08:15:00+00:00', $json['created']);
		$this->assertSame('2026-03-02T09:30:00+02:00', $json['updated']);
		$this->assertSame(['s-1', 'active'], [$json['uuid'], $json['status']]);
	}

	public function testUnsetTimestampsAreNull(): void {
		$json = (new FederatedShare())->jsonSerialize();

		$this->assertArrayHasKey('created', $json);
		$this->assertArrayHasKey('updated', $json);
		$this->assertNull($json['created']);
		$this->assertNull($json['updated']);
	}

	public function testOnlyTheSetTimestampIsFilled(): void {
		$share = new FederatedShare();
		$share->setUpdated(new DateTime('2026-03-02 09:30:00', new DateTimeZone('UTC')));

		$json = $share->jsonSerialize();

		$this->assertNull($json['created']);
		$this->assertSame('2026-03-02T09:30:00+00:00', $json['updated']);
	}
}
