<?php

/**
 * SourceJson: the JSON shape of a source entity.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Source;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\Source\SourceJson;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Source\SourceJson
 */
class SourceJsonTest extends TestCase {

	/**
	 * A complete source field map.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(array $overrides=[]): array {
		return array_merge([
			'id' => 4,
			'uuid' => 'b6c0',
			'title' => 'Mainframe',
			'version' => '1.2.0',
			'description' => 'Legacy store',
			'databaseUrl' => 'pgsql://db/legacy',
			'type' => 'database',
			'organisation' => 'org-1',
			'syncEnabled' => true,
			'syncSchedule' => 'hourly',
			'syncInterval' => 60,
			'lastSyncDate' => new DateTime('2026-11-16T10:00:00+00:00'),
			'lastSyncStatus' => 'ok',
			'lastSyncToken' => 'tok-9',
			'authType' => 'basic',
			'authConfig' => ['username' => 'u'],
			'mappingId' => 12,
			'targetRegister' => 3,
			'targetSchema' => 8,
			'conflictStrategy' => 'source-wins',
			'deleteStrategy' => 'soft',
			'batchSize' => 250,
			'updated' => new DateTime('2026-11-15T09:30:00+00:00'),
			'created' => new DateTime('2026-11-01T08:00:00+00:00'),
		], $overrides);
	}

	public function testSerialiseEmitsEveryKeyInOrder(): void {
		$json = (new SourceJson())->serialise($this->fields());

		$this->assertSame([
			'id' => 4,
			'uuid' => 'b6c0',
			'title' => 'Mainframe',
			'version' => '1.2.0',
			'description' => 'Legacy store',
			'databaseUrl' => 'pgsql://db/legacy',
			'type' => 'database',
			'organisation' => 'org-1',
			'syncEnabled' => true,
			'syncSchedule' => 'hourly',
			'syncInterval' => 60,
			'lastSyncDate' => '2026-11-16T10:00:00+00:00',
			'lastSyncStatus' => 'ok',
			'lastSyncToken' => 'tok-9',
			'authType' => 'basic',
			'authConfigured' => true,
			'mappingId' => 12,
			'targetRegister' => 3,
			'targetSchema' => 8,
			'conflictStrategy' => 'source-wins',
			'deleteStrategy' => 'soft',
			'batchSize' => 250,
			'updated' => '2026-11-15T09:30:00+00:00',
			'created' => '2026-11-01T08:00:00+00:00',
			'managedByConfiguration' => null,
			'application' => null,
			'config' => null,
			'runAs' => null,
			'schedule' => null,
			'flowId' => null,
			'identityProperty' => null,
			'protectedFields' => null,
			'provenance' => null,
			'conflictRules' => null,
			'maxItemsPerRun' => null,
		], $json);
	}

	public function testHarvestFieldsAreEmittedAsGiven(): void {
		$json = (new SourceJson())->serialise($this->fields([
			'application' => 'pipelinq',
			'config' => ['url' => 'https://feed'],
			'runAs' => 'harvester',
			'schedule' => '0 * * * *',
			'flowId' => 7,
			'identityProperty' => 'externalId',
			'protectedFields' => ['notes'],
			'provenance' => true,
			'conflictRules' => ['title' => 'source-wins'],
			'maxItemsPerRun' => 500,
		]));

		$this->assertSame('pipelinq', $json['application']);
		$this->assertSame(['url' => 'https://feed'], $json['config']);
		$this->assertSame('harvester', $json['runAs']);
		$this->assertSame('0 * * * *', $json['schedule']);
		$this->assertSame(7, $json['flowId']);
		$this->assertSame('externalId', $json['identityProperty']);
		$this->assertSame(['notes'], $json['protectedFields']);
		$this->assertTrue($json['provenance']);
		$this->assertSame(['title' => 'source-wins'], $json['conflictRules']);
		$this->assertSame(500, $json['maxItemsPerRun']);
	}

	public function testTheAuthConfigItselfIsNeverEmitted(): void {
		$json = (new SourceJson())->serialise($this->fields(['authConfig' => ['password' => 'secret']]));

		$this->assertArrayNotHasKey('authConfig', $json);
		$this->assertStringNotContainsString('secret', (string) json_encode($json));
	}

	public function testNullOrEmptyAuthConfigIsNotConfigured(): void {
		$this->assertFalse((new SourceJson())->serialise($this->fields(['authConfig' => null]))['authConfigured']);
		$this->assertFalse((new SourceJson())->serialise($this->fields(['authConfig' => []]))['authConfigured']);
	}

	public function testMissingDatesSerialiseAsNull(): void {
		$json = (new SourceJson())->serialise($this->fields(['lastSyncDate' => null, 'updated' => null, 'created' => null]));

		$this->assertNull($json['lastSyncDate']);
		$this->assertNull($json['updated']);
		$this->assertNull($json['created']);
	}
}
