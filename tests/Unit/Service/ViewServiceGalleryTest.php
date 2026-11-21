<?php

/**
 * Unit tests for the gallery presentation of a saved view.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-gallery-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-records-as-a-gallery
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\Service\ViewAudience;
use OCA\OpenRegister\Search\Service\ViewDefinition;
use OCA\OpenRegister\Search\Service\ViewEdit;
use OCA\OpenRegister\Search\Service\ViewService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which gallery presentations a view save accepts, and why it refuses the others.
 *
 * @covers \OCA\OpenRegister\Search\Service\ViewService
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Search\Db\View
 * @uses \OCA\OpenRegister\Search\Service\PresentationCheck
 * @uses \OCA\OpenRegister\Search\Service\ViewAudience
 * @uses \OCA\OpenRegister\Search\Service\ViewDefinition
 * @uses \OCA\OpenRegister\Search\Service\ViewEdit
 */
final class ViewServiceGalleryTest extends TestCase {

	/**
	 * The service over the `panden` schema; the mapper inserts what it is given.
	 *
	 * @return ViewService
	 */
	private function service(): ViewService {
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('findAll')->willReturn([]);
		$schema = new Schema();
		$schema->setProperties(
			[
				'foto' => ['type' => 'file'],
				'fotos' => ['type' => 'array', 'items' => ['type' => 'file']],
				'plaatje' => ['type' => 'string', 'format' => 'image'],
				'adres' => ['type' => 'string'],
				'bouwjaar' => ['type' => 'integer'],
				'gebruiksdoel' => ['type' => 'string'],
			]
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		return new ViewService($mapper, $this->createMock(LoggerInterface::class), $schemas);
	}//end service()

	/**
	 * Gallery shapes and the outcome of saving them.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function galleries(): array {
		$base = ['titleField' => 'adres'];
		return [
			'the spec gallery' => [['coverField' => 'foto', 'titleField' => 'adres', 'cardFields' => ['bouwjaar', 'gebruiksdoel'], 'cardSize' => 'medium'], 'ok'],
			'no cover, large cards' => [$base + ['cardSize' => 'large'], 'ok'],
			'a list of files' => [$base + ['coverField' => 'fotos'], 'ok'],
			'an image format' => [$base + ['coverField' => 'plaatje'], 'ok'],
			'a text cover' => [$base + ['coverField' => 'adres'], 'Presentation gallery.coverField "adres" is not a file or image property of the view\'s schema'],
			'an unknown cover' => [$base + ['coverField' => 'nope'], 'Presentation gallery.coverField "nope" is not a property of the view\'s schema'],
			'an empty cover' => [$base + ['coverField' => ''], 'A gallery presentation requires a non-empty gallery.coverField'],
			'no title' => [['coverField' => 'foto'], 'A gallery presentation requires a non-empty gallery.titleField'],
			'an unknown title' => [['titleField' => 'nope'], 'Presentation gallery.titleField "nope" is not a property of the view\'s schema'],
			'an unknown card field' => [$base + ['cardFields' => ['bouwjaar', 'nope']], 'Presentation gallery.cardFields ""nope"" is not a property of the view\'s schema'],
			'card fields not a list' => [$base + ['cardFields' => 'bouwjaar'], 'Presentation gallery.cardFields must be a list of property names'],
			'a bad card size' => [$base + ['cardSize' => 'huge'], 'Presentation gallery.cardSize must be small, medium or large'],
		];
	}//end galleries()

	/**
	 * A save accepts a valid gallery as given and refuses an invalid one with its reason.
	 *
	 * @param array<string, mixed> $gallery  The gallery config.
	 * @param string               $expected 'ok' or the exact refusal.
	 *
	 * @return void
	 */
	#[DataProvider('galleries')]
	public function testGallerySave(array $gallery, string $expected): void {
		$presentation = ['viewType' => 'gallery', 'gallery' => $gallery];
		try {
			$view = $this->service()->create(
				new ViewEdit(definition: new ViewDefinition('Panden', '', ['schemas' => ['panden']], $presentation), audience: new ViewAudience('alice', false, false))
			);
			$this->assertSame($presentation, $view->getPresentation());
			$outcome = 'ok';
		} catch (InvalidArgumentException $e) {
			$outcome = $e->getMessage();
		}

		$this->assertSame($expected, $outcome);
	}//end testGallerySave()
}//end class
