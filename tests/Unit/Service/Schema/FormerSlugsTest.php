<?php

/**
 * Unit tests for FormerSlugs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schema\FormerSlugs;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schema\FormerSlugs
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#2.2
 */
class FormerSlugsTest extends TestCase {

	/**
	 * A schema with an id, a current slug and former slugs.
	 *
	 * @param int        $id     The id.
	 * @param string     $slug   The current slug.
	 * @param array|null $former The former slugs.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug, ?array $former = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setFormerSlugs($former);
		return $schema;
	}

	public function testAmongFindsTheSchemaThatAnsweredToTheSlug(): void {
		$signaal = $this->schema(7, 'signaal', ['melding']);
		$found = FormerSlugs::among(schemas: [$this->schema(3, 'document'), $signaal], slug: 'melding');

		self::assertSame($signaal, $found);
	}

	public function testAmongFindsNothingWhenNoSchemaListsTheSlug(): void {
		self::assertNull(FormerSlugs::among(schemas: [$this->schema(3, 'document', ['dossier'])], slug: 'melding'));
		self::assertNull(FormerSlugs::among(schemas: [$this->schema(3, 'document', ['melding'])], slug: ''));
	}

	public function testAmongNeverTakesASchemaWhoseCurrentSlugIsTheSlug(): void {
		// Renamed away and back: the slug is current again, so it is not a former match.
		$back = $this->schema(4, 'Melding', ['melding']);

		self::assertNull(FormerSlugs::among(schemas: [$back], slug: 'melding'));
	}

	public function testAmongTakesTheLowestIdWhatEverTheReadOrder(): void {
		$older = $this->schema(5, 'signaal', ['melding']);
		$newer = $this->schema(9, 'notitie', ['melding']);

		self::assertSame($older, FormerSlugs::among(schemas: [$newer, $older], slug: 'melding'));
		self::assertSame($older, FormerSlugs::among(schemas: [$older, $newer], slug: 'melding'));
	}

	public function testAmongSkipsWhatIsNotASchema(): void {
		self::assertNull(FormerSlugs::among(schemas: ['melding', 7, null], slug: 'melding'));
	}

	public function testServedThroughIsTrueOnlyForAFormerSlug(): void {
		$signaal = $this->schema(7, 'signaal', ['melding']);

		self::assertTrue(FormerSlugs::servedThrough(schemaRef: 'melding', schema: $signaal));
		self::assertFalse(FormerSlugs::servedThrough(schemaRef: 'signaal', schema: $signaal));
		self::assertFalse(FormerSlugs::servedThrough(schemaRef: 'SIGNAAL', schema: $signaal));
		self::assertFalse(FormerSlugs::servedThrough(schemaRef: 'other', schema: $signaal));
		self::assertFalse(FormerSlugs::servedThrough(schemaRef: 'melding', schema: null));
	}

	public function testServedThroughIsFalseForAnIdOrAUuid(): void {
		$schema = $this->schema(7, 'signaal', ['7', '0f6b8a52-3c1e-4d2a-9b7e-5f4c3d2e1a0b']);

		self::assertFalse(FormerSlugs::servedThrough(schemaRef: 7, schema: $schema));
		self::assertFalse(FormerSlugs::servedThrough(schemaRef: '7', schema: $schema));
		self::assertFalse(FormerSlugs::servedThrough(schemaRef: '0f6b8a52-3c1e-4d2a-9b7e-5f4c3d2e1a0b', schema: $schema));
	}

	public function testSuccessorPathReplacesOnlyTheSchemaSegment(): void {
		self::assertSame(
			'/index.php/apps/openregister/api/objects/meldingen-register/signaal',
			FormerSlugs::successorPath(
				requestUri: '/index.php/apps/openregister/api/objects/meldingen-register/melding?_limit=5',
				register: 'meldingen-register',
				former: 'melding',
				current: 'signaal'
			)
		);
		self::assertSame(
			'/apps/openregister/api/objects/meldingen-register/signaal/abc-1',
			FormerSlugs::successorPath(
				requestUri: '/apps/openregister/api/objects/meldingen-register/melding/abc-1',
				register: 'meldingen-register',
				former: 'melding',
				current: 'signaal'
			)
		);
	}

	public function testSuccessorPathFallsBackToTheListPathOfTheCurrentSlug(): void {
		$fallback = '/index.php/apps/openregister/api/objects/meldingen-register/signaal';

		self::assertSame($fallback, FormerSlugs::successorPath(requestUri: '', register: 'meldingen-register', former: 'melding', current: 'signaal'));
		// `meldingen` starts with `melding` but is another segment.
		self::assertSame(
			$fallback,
			FormerSlugs::successorPath(
				requestUri: '/index.php/apps/openregister/api/objects/meldingen-register/meldingen',
				register: 'meldingen-register',
				former: 'melding',
				current: 'signaal'
			)
		);
	}

	public function testHeadersCarryDeprecationAndTheSuccessorLink(): void {
		self::assertSame(
			[
				'Deprecation' => 'true',
				'Link'        => '</api/objects/r/signaal>; rel="successor-version"',
			],
			FormerSlugs::headers(successorPath: '/api/objects/r/signaal')
		);
	}
}//end class
