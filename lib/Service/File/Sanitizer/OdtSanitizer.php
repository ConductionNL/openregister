<?php

/**
 * OdtSanitizer
 *
 * XML-level sanitiser for OpenDocument Text documents (.odt). Operates on the
 * ZIP container copy: removes annotations (comments), accepts tracked changes,
 * scrubs meta.xml fields to a sentinel, flattens hyperlinks, and removes
 * person-identity placeholders.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Sanitizer;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Service\File\SanitizationReport;
use ZipArchive;

/**
 * Sanitises an .odt ZIP container in-place.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) DOM / ZipArchive collaborators are intrinsic to ZIP-and-XML surgery.
 */
class OdtSanitizer implements SanitizerInterface {

	/**
	 * The ODT MIME type this strategy supports.
	 *
	 * @var string
	 */
	private const MIME = 'application/vnd.oasis.opendocument.text';

	/**
	 * ODF namespace URIs used by the surgery passes.
	 *
	 * @var array<string, string>
	 */
	private const NS = [
		'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
		'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
		'meta' => 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0',
		'dc' => 'http://purl.org/dc/elements/1.1/',
		'xlink' => 'http://www.w3.org/1999/xlink',
	];

	/**
	 * Constructor.
	 *
	 * @param string $sentinel The sentinel string applied to scrubbed metadata.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		private readonly string $sentinel = 'DocuDesk Anonymisation',
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $mimeType The file MIME type.
	 *
	 * @return bool True for the ODT MIME type.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function supports(string $mimeType): bool {
		return $mimeType === self::MIME;
	}//end supports()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sourcePath Path to the source .odt.
	 * @param string $destPath Path to write the sanitised .odt.
	 *
	 * @throws SanitizationException On encryption / corrupt-zip / internal failure.
	 *
	 * @return SanitizationReport Per-category counts.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function sanitize(string $sourcePath, string $destPath): SanitizationReport {
		if ($sourcePath !== $destPath) {
			if (copy($sourcePath, $destPath) === false) {
				throw new SanitizationException(
					reason: SanitizationException::REASON_INTERNAL,
					message: 'Could not copy source document to destination'
				);
			}
		}

		$zip = new ZipArchive();
		$code = $zip->open($destPath);
		if ($code !== true) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_CORRUPT_ZIP,
				message: sprintf('Could not open ODT ZIP (code %d)', $code)
			);
		}

		if ($zip->locateName('content.xml') === false) {
			$zip->close();
			throw new SanitizationException(
				reason: SanitizationException::REASON_ENCRYPTED,
				message: 'Document is encrypted or not a valid ODT package'
			);
		}

		$counts = [
			'commentsRemoved' => 0,
			'trackedChangesAccepted' => 0,
			'trackedChangesDropped' => 0,
			'hyperlinksFlattened' => 0,
			'metadataFieldsScrubbed' => 0,
			'fieldCodesStripped' => 0,
		];

		$contentXml = $zip->getFromName('content.xml');
		if ($contentXml !== false) {
			$dom = $this->loadXml(xml: $contentXml);
			$xpath = $this->xpath(dom: $dom);

			$this->removeAnnotations(xpath: $xpath, counts: $counts);
			$this->acceptTrackedChanges(xpath: $xpath, counts: $counts);
			$this->flattenHyperlinks(xpath: $xpath, counts: $counts);
			$this->removeIdentityPlaceholders(xpath: $xpath, counts: $counts);

			$zip->addFromString('content.xml', $dom->saveXML());
		}

		$this->scrubMetadata(zip: $zip, counts: $counts);

		$zip->close();

		return new SanitizationReport(
			commentsRemoved: $counts['commentsRemoved'],
			trackedChangesAccepted: $counts['trackedChangesAccepted'],
			trackedChangesDropped: $counts['trackedChangesDropped'],
			revisionAttributesStripped: 0,
			hyperlinksFlattened: $counts['hyperlinksFlattened'],
			metadataFieldsScrubbed: $counts['metadataFieldsScrubbed'],
			customXmlPartsDropped: 0,
			fieldCodesStripped: $counts['fieldCodesStripped'],
			sentinelApplied: $this->sentinel
		);
	}//end sanitize()

	/**
	 * Remove office:annotation comments.
	 *
	 * @param DOMXPath $xpath The content xpath context.
	 * @param array<string, int> $counts Mutable counter map.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function removeAnnotations(DOMXPath $xpath, array &$counts): void {
		foreach (iterator_to_array($xpath->query('//office:annotation')) as $node) {
			$node->parentNode?->removeChild($node);
			$counts['commentsRemoved']++;
		}

		foreach (iterator_to_array($xpath->query('//office:annotation-end')) as $node) {
			$node->parentNode?->removeChild($node);
		}
	}//end removeAnnotations()

	/**
	 * Accept tracked changes: keep inserted content, drop deletions, remove container.
	 *
	 * @param DOMXPath $xpath The content xpath context.
	 * @param array<string, int> $counts Mutable counter map.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function acceptTrackedChanges(DOMXPath $xpath, array &$counts): void {
		// Inline change-start/change-end markers wrap inserted content; remove
		// the markers but keep the content between them.
		foreach (iterator_to_array($xpath->query('//text:change-start')) as $node) {
			$node->parentNode?->removeChild($node);
			$counts['trackedChangesAccepted']++;
		}

		foreach (iterator_to_array($xpath->query('//text:change-end')) as $node) {
			$node->parentNode?->removeChild($node);
		}

		// Inline text:change markers reference deleted ranges; drop them.
		foreach (iterator_to_array($xpath->query('//text:change')) as $node) {
			$node->parentNode?->removeChild($node);
			$counts['trackedChangesDropped']++;
		}

		// Remove the metadata container itself.
		foreach (iterator_to_array($xpath->query('//text:tracked-changes')) as $node) {
			$node->parentNode?->removeChild($node);
		}
	}//end acceptTrackedChanges()

	/**
	 * Flatten text:a hyperlinks to their inner content.
	 *
	 * @param DOMXPath $xpath The content xpath context.
	 * @param array<string, int> $counts Mutable counter map.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function flattenHyperlinks(DOMXPath $xpath, array &$counts): void {
		foreach (iterator_to_array($xpath->query('//text:a')) as $link) {
			$this->unwrap(node: $link);
			$counts['hyperlinksFlattened']++;
		}
	}//end flattenHyperlinks()

	/**
	 * Remove inline person-identity placeholders.
	 *
	 * @param DOMXPath $xpath The content xpath context.
	 * @param array<string, int> $counts Mutable counter map.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function removeIdentityPlaceholders(DOMXPath $xpath, array &$counts): void {
		foreach (['//text:author-name', '//text:author-initials', '//text:initial-creator'] as $query) {
			foreach (iterator_to_array($xpath->query($query)) as $node) {
				$node->parentNode?->removeChild($node);
				$counts['fieldCodesStripped']++;
			}
		}
	}//end removeIdentityPlaceholders()

	/**
	 * Scrub meta.xml fields to the sentinel.
	 *
	 * @param ZipArchive $zip The open archive.
	 * @param array<string, int> $counts Mutable counter map.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubMetadata(ZipArchive $zip, array &$counts): void {
		$metaXml = $zip->getFromName('meta.xml');
		if ($metaXml === false) {
			return;
		}

		$dom = $this->loadXml(xml: $metaXml);
		$fields = [
			[self::NS['dc'], 'creator'],
			[self::NS['meta'], 'initial-creator'],
			[self::NS['dc'], 'title'],
			[self::NS['dc'], 'subject'],
			[self::NS['meta'], 'keyword'],
			[self::NS['dc'], 'description'],
		];

		$counts['metadataFieldsScrubbed'] += $this->scrubNamedFields(dom: $dom, fields: $fields);
		$counts['metadataFieldsScrubbed'] += $this->scrubUserDefinedFields(dom: $dom);

		$zip->addFromString('meta.xml', $dom->saveXML());
	}//end scrubMetadata()

	/**
	 * Scrub each present, non-empty namespaced meta field to the sentinel.
	 *
	 * @param DOMDocument $dom The meta DOM.
	 * @param array<int, array{0:string,1:string}> $fields Namespace-URI / local-name pairs.
	 *
	 * @return int The number of fields scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubNamedFields(DOMDocument $dom, array $fields): int {
		$count = 0;
		foreach ($fields as [$namespace, $local]) {
			$nodes = $dom->getElementsByTagNameNS($namespace, $local);
			foreach (iterator_to_array($nodes) as $node) {
				assert($node instanceof DOMNode);
				if (trim($node->textContent) !== '') {
					$node->textContent = $this->sentinel;
					$count++;
				}
			}
		}

		return $count;
	}//end scrubNamedFields()

	/**
	 * Scrub string-typed (or untyped) meta:user-defined fields to the sentinel.
	 *
	 * @param DOMDocument $dom The meta DOM.
	 *
	 * @return int The number of fields scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubUserDefinedFields(DOMDocument $dom): int {
		$count = 0;
		$userDefined = $dom->getElementsByTagNameNS(self::NS['meta'], 'user-defined');
		foreach (iterator_to_array($userDefined) as $node) {
			assert($node instanceof DOMElement);
			$valueType = $node->getAttributeNS(self::NS['meta'], 'value-type');
			if (($valueType === '' || $valueType === 'string') && trim($node->textContent) !== '') {
				$node->textContent = $this->sentinel;
				$count++;
			}
		}

		return $count;
	}//end scrubUserDefinedFields()

	/**
	 * Replace a node with its own child nodes (unwrap).
	 *
	 * @param DOMNode $node The node to unwrap.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function unwrap(DOMNode $node): void {
		$parent = $node->parentNode;
		if ($parent === null) {
			return;
		}

		while ($node->firstChild !== null) {
			$parent->insertBefore($node->firstChild, $node);
		}

		$parent->removeChild($node);
	}//end unwrap()

	/**
	 * Load XML into a namespace-aware DOMDocument (XXE-safe).
	 *
	 * @param string $xml The XML string.
	 *
	 * @throws SanitizationException When the part is not well-formed XML.
	 *
	 * @return DOMDocument The loaded document.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function loadXml(string $xml): DOMDocument {
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput = false;
		$loaded = $dom->loadXML($xml, (LIBXML_NONET | LIBXML_COMPACT));
		if ($loaded === false) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_CORRUPT_ZIP,
				message: 'An ODT part was not well-formed XML'
			);
		}

		return $dom;
	}//end loadXml()

	/**
	 * Build an xpath context with the ODF namespaces registered.
	 *
	 * @param DOMDocument $dom The document.
	 *
	 * @return DOMXPath The namespace-aware xpath context.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function xpath(DOMDocument $dom): DOMXPath {
		$xpath = new DOMXPath($dom);
		foreach (self::NS as $prefix => $uri) {
			$xpath->registerNamespace($prefix, $uri);
		}

		return $xpath;
	}//end xpath()
}//end class
