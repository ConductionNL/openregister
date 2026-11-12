<?php

/**
 * Tests for ScheduleRelease: the slug falls back to the uuid, the organisation is trimmed, and flow uuids are stable name-based v3 uuids.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleRelease over a real object entity.
 */
class ScheduleReleaseTest extends TestCase {

	private const UUID = '0b6f3c1e-1111-4222-8333-944455556666';

	/**
	 * A release of an application object.
	 *
	 * @param array<string, mixed> $data         The object's data.
	 * @param string|null          $organisation The object's organisation.
	 *
	 * @return ScheduleRelease
	 */
	private function release(array $data, ?string $organisation = null): ScheduleRelease {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setObject($data);
		$object->setOrganisation($organisation);
		return new ScheduleRelease($object, [], 'admin');
	}

	public function testTheSlugIsTheObjectsSlugWhenItCarriesOne(): void {
		$this->assertSame('crm', $this->release(['slug' => 'crm'])->slug());
	}

	public function testTheSlugFallsBackToTheUuidWhenAbsent(): void {
		$this->assertSame(self::UUID, $this->release([])->slug());
	}

	public function testTheSlugFallsBackToTheUuidWhenEmptyOrNotAString(): void {
		$this->assertSame(self::UUID, $this->release(['slug' => ''])->slug());
		$this->assertSame(self::UUID, $this->release(['slug' => 42])->slug());
	}

	public function testTheOrganisationIsTrimmedAndBlankMeansNone(): void {
		$this->assertSame('org-1', $this->release([], '  org-1 ')->organisation());
		$this->assertNull($this->release([], '   ')->organisation());
		$this->assertNull($this->release([], null)->organisation());
	}

	public function testTheFlowUuidIsAStableVersionThreeUuidPerApplicationAndSchedule(): void {
		$release = $this->release([]);
		$uuid = $release->flowUuid('nightly');

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-3[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
		$this->assertSame($uuid, $this->release(['slug' => 'other'])->flowUuid('nightly'));
		$this->assertNotSame($uuid, $release->flowUuid('hourly'));
		$hash = md5('apphost-schedule:'.self::UUID.':nightly');
		$this->assertSame(substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-3'.substr($hash, 13, 3), substr($uuid, 0, 18));
	}
}
