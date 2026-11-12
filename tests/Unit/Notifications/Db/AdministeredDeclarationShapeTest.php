<?php

/**
 * Characterisation tests for the administered timeline declarations.
 *
 * Pins, for ReferencePattern, TextBlock and TimelineKind, the column types
 * each entity registers (in order) and the API shape jsonSerialize() returns
 * (keys in order, values, and the ATOM spelling of the timestamps), so the
 * shared columns can move without the stored or served shape changing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions and entity setters.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Notifications\Db\ReferencePattern;
use OCA\OpenRegister\Notifications\Db\TextBlock;
use OCA\OpenRegister\Notifications\Db\TimelineKind;
use PHPUnit\Framework\TestCase;

class AdministeredDeclarationShapeTest extends TestCase {

	private static function at(string $when): DateTime {
		return new DateTime($when, new DateTimeZone('UTC'));
	}

	public function testReferencePatternColumnTypes(): void {
		$this->assertSame(
			[
				'id' => 'integer', 'uuid' => 'string', 'slug' => 'string', 'title' => 'string', 'pattern' => 'string',
				'register' => 'string', 'schema' => 'string', 'targetProperty' => 'string', 'urlTemplate' => 'string',
				'enabled' => 'boolean', 'created' => 'datetime', 'updated' => 'datetime',
			],
			(new ReferencePattern())->getFieldTypes()
		);
	}

	public function testReferencePatternShape(): void {
		$pattern = new ReferencePattern();
		$pattern->setUuid('u-1');
		$pattern->setSlug('zaak');
		$pattern->setTitle('Zaak');
		$pattern->setPattern('ZAAK-\d{4}-\d{4}');
		$pattern->setRegister('r');
		$pattern->setSchema('s');
		$pattern->setTargetProperty('code');
		$pattern->setUrlTemplate('/zaak/{code}');
		$pattern->setEnabled(false);
		$pattern->setCreated(self::at('2026-01-02T03:04:05'));

		$this->assertSame(
			[
				'id' => 'u-1', 'slug' => 'zaak', 'title' => 'Zaak', 'pattern' => 'ZAAK-\d{4}-\d{4}', 'register' => 'r',
				'schema' => 's', 'targetProperty' => 'code', 'urlTemplate' => '/zaak/{code}', 'enabled' => false,
				'created' => '2026-01-02T03:04:05+00:00', 'updated' => null,
			],
			$pattern->jsonSerialize()
		);
		$this->assertTrue((new ReferencePattern())->jsonSerialize()['enabled']);
	}

	public function testTextBlockColumnTypes(): void {
		$this->assertSame(
			[
				'id' => 'integer', 'uuid' => 'string', 'slug' => 'string', 'title' => 'string', 'body' => 'string',
				'register' => 'string', 'schema' => 'string', 'groupId' => 'string', 'created' => 'datetime',
				'updated' => 'datetime',
			],
			(new TextBlock())->getFieldTypes()
		);
	}

	public function testTextBlockShape(): void {
		$block = new TextBlock();
		$block->setUuid('u-2');
		$block->setSlug('groet');
		$block->setTitle('Groet');
		$block->setBody('Beste {naam},');
		$block->setGroupId('team');
		$block->setUpdated(self::at('2026-02-03T04:05:06'));

		$this->assertSame(
			[
				'id' => 'u-2', 'slug' => 'groet', 'title' => 'Groet', 'body' => 'Beste {naam},', 'register' => null,
				'schema' => null, 'groupId' => 'team', 'created' => null, 'updated' => '2026-02-03T04:05:06+00:00',
			],
			$block->jsonSerialize()
		);
	}

	public function testTimelineKindColumnTypes(): void {
		$this->assertSame(
			[
				'id' => 'integer', 'uuid' => 'string', 'slug' => 'string', 'title' => 'string', 'description' => 'string',
				'properties' => 'json', 'required' => 'json', 'followUp' => 'boolean', 'register' => 'string',
				'schema' => 'string', 'created' => 'datetime', 'updated' => 'datetime',
			],
			(new TimelineKind())->getFieldTypes()
		);
	}

	public function testTimelineKindShape(): void {
		$kind = new TimelineKind();
		$kind->setUuid('u-3');
		$kind->setSlug('contactmoment');
		$kind->setTitle('Contactmoment');
		$kind->setDescription('Een gesprek');
		$kind->setProperties(['kanaal' => ['type' => 'string']]);
		$kind->setRegister('r');
		$kind->setSchema('s');

		$this->assertSame(
			[
				'id' => 'u-3', 'slug' => 'contactmoment', 'title' => 'Contactmoment', 'description' => 'Een gesprek',
				'properties' => ['kanaal' => ['type' => 'string']], 'required' => [], 'followUp' => false,
				'register' => 'r', 'schema' => 's', 'created' => null, 'updated' => null,
			],
			$kind->jsonSerialize()
		);
	}
}
