<?php

/**
 * OpenRegister OOXML parts every package format sanitises alike
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

/**
 * The package metadata and the custom XML parts, as the DOCX sanitiser
 * treats them: identity and descriptive fields replaced by the sentinel,
 * custom XML parts removed.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */
final class OoxmlCommonParts {

	/**
	 * The Dublin Core namespace.
	 */
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';

	/**
	 * The OPC core-properties namespace.
	 */
	private const NS_CP = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';

	/**
	 * The extended (app) properties namespace.
	 */
	private const NS_APP = 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties';

	/**
	 * The custom properties namespace.
	 */
	private const NS_CUSTOM = 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties';

	/**
	 * Constructor.
	 *
	 * @param OfficePartSurgery $surgery The shared scrub steps, with the sentinel.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function __construct(
		private readonly OfficePartSurgery $surgery,
	) {
	}//end __construct()

	/**
	 * Scrub `docProps/core.xml`, `docProps/app.xml` and `docProps/custom.xml`.
	 *
	 * @param OoxmlPackage $package The package.
	 *
	 * @return int The number of fields scrubbed.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function scrubMetadata(OoxmlPackage $package): int {
		$parts = [
			'docProps/core.xml' => [
				[self::NS_DC, 'creator'],
				[self::NS_CP, 'lastModifiedBy'],
				[self::NS_DC, 'title'],
				[self::NS_DC, 'subject'],
				[self::NS_CP, 'keywords'],
				[self::NS_DC, 'description'],
				[self::NS_CP, 'category'],
				[self::NS_CP, 'contentStatus'],
			],
			'docProps/app.xml' => [[self::NS_APP, 'Company'], [self::NS_APP, 'Manager']],
		];

		$count = 0;
		foreach ($parts as $name => $fields) {
			$dom = $package->dom(name: $name);
			if ($dom === null) {
				continue;
			}

			$count += $this->surgery->scrubElements(dom: $dom, fields: $fields);
			$package->putDom(name: $name, dom: $dom);
		}

		$custom = $package->dom(name: 'docProps/custom.xml');
		if ($custom !== null) {
			$count += $this->surgery->scrubNodes(nodes: $custom->getElementsByTagNameNS(self::NS_CUSTOM, 'property'));
			$package->putDom(name: 'docProps/custom.xml', dom: $custom);
		}

		return $count;
	}//end scrubMetadata()

	/**
	 * Remove every custom XML part, with whatever points to it.
	 *
	 * @param OoxmlPackage $package The package.
	 *
	 * @return int The number of custom XML items removed (their properties parts not counted).
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function removeCustomXml(OoxmlPackage $package): int {
		$items = $package->names(pattern: '#^customXml/item\d+\.xml$#');
		$this->removeRelationshipsTo(package: $package, targets: $package->names(pattern: '#^customXml/[^/]+\.xml$#'));
		foreach ($package->names(pattern: '#^customXml/#') as $name) {
			$package->removeTree(name: $name);
		}

		return count($items);
	}//end removeCustomXml()

	/**
	 * Drop, from every relationships file, the relationships pointing at the given parts.
	 *
	 * @param OoxmlPackage       $package The package.
	 * @param array<int, string> $targets The part names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function removeRelationshipsTo(OoxmlPackage $package, array $targets): void {
		if ($targets === []) {
			return;
		}

		foreach ($package->names(pattern: '#(^|/)_rels/[^/]+\.rels$#') as $relsName) {
			$directory = dirname(dirname($relsName));
			$source    = substr(basename($relsName), 0, -strlen('.rels'));
			if ($directory !== '.') {
				$source = $directory . '/' . $source;
			}

			$ids = [];
			foreach ($package->relationships(part: $source) as $relationship) {
				if (in_array($relationship['target'], $targets, true) === true) {
					$ids[] = $relationship['id'];
				}
			}

			$package->removeRelationships(part: $source, ids: $ids);
		}
	}//end removeRelationshipsTo()
}//end class
