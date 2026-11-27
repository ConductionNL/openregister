<?php

/**
 * Burns decided regions into the page images of a PDF.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use ddn\sapp\PDFDoc;
use ddn\sapp\PDFObject;
use ddn\sapp\pdfvalue\PDFValueObject;
use GdImage;
use Throwable;

/**
 * Replaces the image XObject of every page a region addresses with a burned raster.
 *
 * A region's `page` is the 1-based PDF page and its box is relative to that
 * page's image, so the page must hold exactly one image XObject; a page with
 * none or with several is refused rather than guessed at. The burned image is
 * written Flate-compressed in the image's own colour space under the same
 * object id, its XMP metadata stream is emptied, and the document is rebuilt
 * in one revision, so the original image stream is absent from the output.
 * No annotation or overlay is added.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */
class PdfImageBurner {

	/**
	 * The type this burner handles.
	 */
	public const MIME_TYPE = 'application/pdf';

	/**
	 * The colour components of each device colour space a burned raster can be written in.
	 */
	private const COMPONENTS = [
		'DeviceGray' => 1,
		'DeviceRGB' => 3,
	];

	/**
	 * Constructor.
	 *
	 * @param ImageBurner $burner The burner of one raster.
	 */
	public function __construct(
		private readonly ImageBurner $burner,
	) {
	}//end __construct()

	/**
	 * Whether a type is a PDF.
	 *
	 * @param string $mimeType The type.
	 *
	 * @return bool True for a PDF.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public static function handles(string $mimeType): bool {
		return $mimeType === self::MIME_TYPE;
	}//end handles()

	/**
	 * Burn the regions into the images of the pages they address.
	 *
	 * @param string            $bytes    The PDF.
	 * @param list<ImageRegion> $regions  The decided regions; `page` is the 1-based PDF page.
	 * @param string            $fileName The file, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When the PDF does not load, or a region addresses no single burnable image.
	 *
	 * @return string The PDF with the affected images replaced.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function burn(string $bytes, array $regions, string $fileName): string {
		$doc = $this->load(bytes: $bytes, fileName: $fileName);
		foreach ($this->regionsByPage(regions: $regions, pageCount: (int)$doc->get_page_count(), fileName: $fileName) as $page => $pageRegions) {
			$image = $this->pageImage(doc: $doc, page: $page, fileName: $fileName, region: $pageRegions[0]);
			$this->blankMetadata(doc: $doc, image: $image);
			$doc->add_object($this->burnedImage(doc: $doc, image: $image, regions: $pageRegions, fileName: $fileName));
		}

		$serialised = false;
		try {
			$serialised = $doc->to_pdf_file_s(rebuild: true);
		} catch (Throwable) {
			$serialised = false;
		}

		if (is_string($serialised) === false || $serialised === '') {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the burned PDF could not be written.');
		}

		return $serialised;
	}//end burn()

	/**
	 * Load the PDF.
	 *
	 * @param string $bytes    The PDF.
	 * @param string $fileName The file, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When it does not load.
	 *
	 * @return PDFDoc The document.
	 */
	private function load(string $bytes, string $fileName): PDFDoc {
		$doc = false;
		if ($bytes !== '') {
			try {
				$doc = @PDFDoc::from_string(buffer: $bytes);
			} catch (Throwable) {
				$doc = false;
			}
		}

		if (($doc instanceof PDFDoc) === false) {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the PDF is corrupt or encrypted.');
		}

		return $doc;
	}//end load()

	/**
	 * Group the regions by the page they address, as standalone regions.
	 *
	 * @param list<ImageRegion> $regions   The regions.
	 * @param int               $pageCount The pages of the PDF.
	 * @param string            $fileName  The file, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When a region names no page of the PDF.
	 *
	 * @return array<int, list<ImageRegion>> The regions per 1-based page, each with page null.
	 */
	private function regionsByPage(array $regions, int $pageCount, string $fileName): array {
		$byPage = [];
		foreach ($regions as $region) {
			$page = ($region->page ?? 0);
			if ($page < 1 || $page > $pageCount) {
				throw new ImageRegionNotBurnableException(
					fileName: $fileName,
					reason: sprintf('the PDF has %d pages, so the region addresses none of them.', $pageCount),
					region: $region
				);
			}

			$byPage[$page][] = new ImageRegion(
				page: null,
				box: $region->box,
				entityType: $region->entityType,
				confidence: $region->confidence,
				source: $region->source,
				relationId: $region->relationId
			);
		}

		return $byPage;
	}//end regionsByPage()

	/**
	 * The one image XObject of a page.
	 *
	 * @param PDFDoc      $doc      The document.
	 * @param int         $page     The 1-based page.
	 * @param string      $fileName The file, for the refusal.
	 * @param ImageRegion $region   A region on the page, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When the page holds no image or more than one.
	 *
	 * @return PDFObject The image.
	 */
	private function pageImage(PDFDoc $doc, int $page, string $fileName, ImageRegion $region): PDFObject {
		$images = [];
		$xobjects = $this->dictionary(doc: $doc, value: ($this->resources(doc: $doc, page: $doc->get_page($page - 1))['XObject'] ?? null));
		foreach ((array)($xobjects?->get_keys() ?? []) as $key) {
			$oid = $this->reference(value: $xobjects[$key]);
			$object = $this->objectOf(doc: $doc, oid: $oid);
			if (($object instanceof PDFObject) === true && $this->name(value: $object['Subtype']) === 'Image') {
				$images[$oid] = $object;
			}
		}

		if (count($images) !== 1) {
			throw new ImageRegionNotBurnableException(
				fileName: $fileName,
				reason: sprintf('page %d holds %d images, so the region\'s box addresses no single image.', $page, count($images)),
				region: $region
			);
		}

		return array_values($images)[0];
	}//end pageImage()

	/**
	 * The resource dictionary of a page, inherited from its parents when the page has none.
	 *
	 * @param PDFDoc $doc  The document.
	 * @param mixed  $page The page object, or false when the document has no such page.
	 *
	 * @return PDFValueObject|null The resources.
	 */
	private function resources(PDFDoc $doc, mixed $page): ?PDFValueObject {
		$node = $page;
		for ($depth = 0; $depth < 32 && ($node instanceof PDFObject) === true; $depth++) {
			$resources = $this->dictionary(doc: $doc, value: ($node['Resources'] ?? null));
			if ($resources !== null) {
				return $resources;
			}

			$parent = $this->reference(value: ($node['Parent'] ?? null));
			$node = $this->objectOf(doc: $doc, oid: $parent);
		}

		return null;
	}//end resources()

	/**
	 * Empty the XMP metadata stream an image carries, so its old metadata does not survive the rebuild.
	 *
	 * @param PDFDoc    $doc   The document.
	 * @param PDFObject $image The image XObject.
	 *
	 * @return void
	 */
	private function blankMetadata(PDFDoc $doc, PDFObject $image): void {
		$metadata = $this->objectOf(doc: $doc, oid: $this->reference(value: ($image['Metadata'] ?? null)));
		if ($metadata === false) {
			return;
		}

		$blank = new PDFObject($metadata->get_oid(), ['Type' => '/Metadata', 'Subtype' => '/XML', 'Length' => 0]);
		$blank->set_stream('', true);
		$doc->add_object($blank);
	}//end blankMetadata()

	/**
	 * Burn the regions into an image XObject and answer its replacement.
	 *
	 * @param PDFDoc            $doc      The document.
	 * @param PDFObject         $image    The image XObject.
	 * @param list<ImageRegion> $regions  The regions, relative to the image.
	 * @param string            $fileName The file, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When the image's encoding or colour space cannot be burned.
	 *
	 * @return PDFObject The replacement under the same object id.
	 */
	private function burnedImage(PDFDoc $doc, PDFObject $image, array $regions, string $fileName): PDFObject {
		$width = (int)(string)$image['Width'];
		$height = (int)(string)$image['Height'];
		$components = $this->components(doc: $doc, image: $image, fileName: $fileName, region: $regions[0]);
		$raster = $this->raster(image: $image, size: [$width, $height, $components], fileName: $fileName, region: $regions[0]);

		ob_start();
		imagepng($raster);
		$burned = imagecreatefromstring($this->burner->burn(bytes: (string)ob_get_clean(), mimeType: 'image/png', regions: $regions, fileName: $fileName));
		if (($burned instanceof GdImage) === false) {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the burned image could not be read back.', region: $regions[0]);
		}

		$fields = [
			'Type' => '/XObject',
			'Subtype' => '/Image',
			'Width' => $width,
			'Height' => $height,
			'ColorSpace' => $image['ColorSpace'],
			'BitsPerComponent' => 8,
			'Filter' => '/FlateDecode',
		];
		foreach (['Decode', 'SMask', 'Interpolate', 'Intent'] as $kept) {
			if (isset($image[$kept]) === true) {
				$fields[$kept] = $image[$kept];
			}
		}

		$stream = (string)gzcompress($this->samples(image: $burned, components: $components));
		$fields['Length'] = strlen($stream);
		$replacement = new PDFObject($image->get_oid(), $fields);
		$replacement->set_stream($stream, true);

		return $replacement;
	}//end burnedImage()

	/**
	 * The colour components of an image, refusing what cannot be written back.
	 *
	 * @param PDFDoc      $doc      The document.
	 * @param PDFObject   $image    The image XObject.
	 * @param string      $fileName The file, for the refusal.
	 * @param ImageRegion $region   The region, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When it is a mask, not 8 bits, or not gray or RGB.
	 *
	 * @return int 1 or 3.
	 */
	private function components(PDFDoc $doc, PDFObject $image, string $fileName, ImageRegion $region): int {
		if (strtolower(trim((string)($image['ImageMask'] ?? ''))) === 'true') {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the image is a stencil mask.', region: $region);
		}

		$space = (string)($image['ColorSpace'] ?? '');
		$components = (self::COMPONENTS[$this->name(value: $space) ?? ''] ?? null);
		if (preg_match('/^\[\s*\/ICCBased\b/', $space) === 1) {
			$components = $this->iccComponents(doc: $doc, space: $space);
		}

		$bits = (int)(string)($image['BitsPerComponent'] ?? '8');
		if (in_array($components, [1, 3], true) === false || $bits !== 8) {
			throw new ImageRegionNotBurnableException(
				fileName: $fileName,
				reason: sprintf('the image\'s colour space %s at %d bits per component cannot be burned.', $this->orNone(text: $space), $bits),
				region: $region
			);
		}

		return $components;
	}//end components()

	/**
	 * The component count an ICC-based colour space's profile stream declares.
	 *
	 * @param PDFDoc $doc   The document.
	 * @param string $space The colour space, `[/ICCBased n 0 R]`.
	 *
	 * @return int|null The profile's `/N`, or null when the profile cannot be read.
	 */
	private function iccComponents(PDFDoc $doc, string $space): ?int {
		if (preg_match('/\/ICCBased\s+(\d+)\s+\d+\s+R/', $space, $match) !== 1) {
			return null;
		}

		$profile = $doc->get_object((int)$match[1]);
		if (($profile instanceof PDFObject) === false || isset($profile['N']) === false) {
			return null;
		}

		return (int)(string)$profile['N'];
	}//end iccComponents()

	/**
	 * Decode an image XObject into a truecolour raster.
	 *
	 * @param PDFObject                         $image    The image XObject.
	 * @param array{0: int, 1: int, 2: int}     $size     Width, height and colour components.
	 * @param string                            $fileName The file, for the refusal.
	 * @param ImageRegion                       $region   The region, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When the encoding is not JPEG, Flate or none, or the samples do not decode.
	 *
	 * @return GdImage The raster.
	 */
	private function raster(PDFObject $image, array $size, string $fileName, ImageRegion $region): GdImage {
		[$width, $height, $components] = $size;
		$filters = $this->filters(image: $image);
		if ($filters === ['DCTDecode']) {
			$raster = @imagecreatefromstring((string)$image->get_stream(true));
			if (($raster instanceof GdImage) === false || imagesx($raster) !== $width || imagesy($raster) !== $height) {
				throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the JPEG image of the page does not decode.', region: $region);
			}

			imagepalettetotruecolor($raster);
			return $raster;
		}

		$samples = false;
		if ($filters === [] || $filters === ['FlateDecode']) {
			$samples = $image->get_stream(false);
		}

		if (is_string($samples) === false || strlen($samples) !== ($width * $height * $components) || $width < 1 || $height < 1) {
			throw new ImageRegionNotBurnableException(
				fileName: $fileName,
				reason: sprintf('the image\'s encoding %s cannot be burned.', $this->orNone(text: implode(', ', $filters))),
				region: $region
			);
		}

		$raster = imagecreatetruecolor($width, $height);
		$offset = 0;
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$red = ord($samples[$offset]);
				$green = $red;
				$blue = $red;
				if ($components === 3) {
					$green = ord($samples[($offset + 1)]);
					$blue = ord($samples[($offset + 2)]);
				}

				imagesetpixel($raster, $x, $y, (($red << 16) | ($green << 8) | $blue));
				$offset += $components;
			}
		}

		return $raster;
	}//end raster()

	/**
	 * The samples of a raster, row by row, in the image's own component count.
	 *
	 * @param GdImage $image      The raster.
	 * @param int     $components 1 or 3.
	 *
	 * @return string The samples.
	 */
	private function samples(GdImage $image, int $components): string {
		$samples = '';
		$width = imagesx($image);
		$height = imagesy($image);
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$colour = imagecolorat($image, $x, $y);
				$red = (($colour >> 16) & 0xFF);
				if ($components === 1) {
					$samples .= chr($red);
					continue;
				}

				$samples .= chr($red) . chr((($colour >> 8) & 0xFF)) . chr(($colour & 0xFF));
			}
		}

		return $samples;
	}//end samples()

	/**
	 * The filter names of an image's stream, in order.
	 *
	 * @param PDFObject $image The image XObject.
	 *
	 * @return list<string> The names, without slashes.
	 */
	private function filters(PDFObject $image): array {
		preg_match_all('/\/([A-Za-z0-9]+)/', (string)($image['Filter'] ?? ''), $matches);

		return $matches[1];
	}//end filters()

	/**
	 * A dictionary value, inline or behind a reference.
	 *
	 * @param PDFDoc $doc   The document.
	 * @param mixed  $value The value.
	 *
	 * @return PDFValueObject|null The dictionary.
	 */
	private function dictionary(PDFDoc $doc, mixed $value): ?PDFValueObject {
		if ($value instanceof PDFValueObject) {
			return $value;
		}

		$oid = $this->reference(value: $value);
		$object = $this->objectOf(doc: $doc, oid: $oid);
		if (($object instanceof PDFObject) === true && $object->get_value() instanceof PDFValueObject) {
			return $object->get_value();
		}

		return null;
	}//end dictionary()

	/**
	 * The object behind an id.
	 *
	 * @param PDFDoc   $doc The document.
	 * @param int|null $oid The id, or none.
	 *
	 * @return PDFObject|false The object, or false when there is none.
	 */
	private function objectOf(PDFDoc $doc, ?int $oid): PDFObject|false {
		if ($oid === null) {
			return false;
		}

		$object = $doc->get_object($oid);
		if (($object instanceof PDFObject) === false) {
			return false;
		}

		return $object;
	}//end objectOf()

	/**
	 * A text for a refusal, `none` when it is empty.
	 *
	 * @param string $text The text.
	 *
	 * @return string The text or `none`.
	 */
	private function orNone(string $text): string {
		if ($text === '') {
			return 'none';
		}

		return $text;
	}//end orNone()

	/**
	 * The object id a value references.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The id.
	 */
	private function reference(mixed $value): ?int {
		if (is_object($value) === false || method_exists($value, 'get_object_referenced') === false) {
			return null;
		}

		$oid = $value->get_object_referenced();
		if ($oid === false || is_array($oid) === true) {
			return null;
		}

		return (int)$oid;
	}//end reference()

	/**
	 * A name value without its slash.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The name.
	 */
	private function name(mixed $value): ?string {
		if ($value === null || $value === false) {
			return null;
		}

		return ltrim(trim((string)$value), '/');
	}//end name()
}//end class
