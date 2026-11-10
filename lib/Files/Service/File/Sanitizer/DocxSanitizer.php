<?php

/**
 * DocxSanitizer
 *
 * XML-level sanitiser for OOXML Word documents (.docx). Operates on a ZIP
 * container copy: strips comments, accepts tracked changes (insert) / drops
 * deletions, strips revision attributes, removes custom XML parts, scrubs
 * document metadata to a sentinel, strips person-identity field codes, and
 * flattens hyperlinks (dropping URL + relationship).
 *
 * Each surgical pass also reconciles `[Content_Types].xml` Override entries and
 * `_rels/*.rels` Relationship entries for removed parts so the output opens
 * cleanly in Word and LibreOffice (no "found unreadable content" recovery).
 *
 * The inline (body-part) passes are consolidated into a single load/save per
 * part: ZipArchive does not surface a pending in-session write to a subsequent
 * getFromName(), so each body part is read once, mutated by every inline pass,
 * and written back exactly once.
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

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts;
use OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts;
use ZipArchive;

/**
 * Sanitises a .docx ZIP container in-place.
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
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     OOXML surgery spans comments / tracked-changes / customXml / metadata / fields / hyperlinks.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Each OOXML structure needs its own DOM/XPath pass.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   DOM / ZipArchive collaborators are intrinsic to ZIP-and-XML surgery.
 * @SuppressWarnings(PHPMD.TooManyMethods)           One private helper per OOXML structure keeps each pass readable.
 */
class DocxSanitizer implements SanitizerInterface {

	/**
	 * The DOCX MIME type this strategy supports.
	 *
	 * @var string
	 */
	private const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * WordprocessingML main namespace URI.
	 *
	 * @var string
	 */
	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * Relationships namespace URI (document.xml r:id references).
	 *
	 * @var string
	 */
	private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/**
	 * Person-identity field codes to strip (case-insensitive).
	 *
	 * @var string[]
	 */
	private const FIELD_STRIP_LIST = [
		'AUTHOR',
		'USERNAME',
		'USERINITIALS',
		'LASTSAVEDBY',
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
	 * @return bool True for the DOCX MIME type.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function supports(string $mimeType): bool {
		return $mimeType === self::MIME;
	}//end supports()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sourcePath Path to the source .docx.
	 * @param string $destPath Path to write the sanitised .docx.
	 *
	 * @throws SanitizationException On encryption / corrupt-zip / internal failure.
	 *
	 * @return SanitizationReport Per-category counts.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function sanitize(string $sourcePath, string $destPath): SanitizationReport {
		$zip = $this->openPackage(sourcePath: $sourcePath, destPath: $destPath);
		$run = new DocxSanitizationRun(zip: $zip);

		// Snapshot the body-part names BEFORE any part deletion mutates the
		// central directory indices.
		$bodyParts = $this->bodyParts(zip: $zip);

		// [Content_Types].xml and word/_rels/document.xml.rels are each touched
		// by several passes. ZipArchive does not surface a pending in-session
		// write to a later getFromName(), so every removal target is collected
		// on the run and the two index parts are reconciled exactly once at the end.

		// Part-level removals (each deletes a distinct part — safe in-session).
		$this->removeCommentParts(run: $run);
		$this->stripCustomXmlParts(run: $run);

		// Metadata parts (each touched exactly once).
		$this->scrubMetadata(run: $run);

		// Inline body-part surgery: read once, run every inline pass, write once.
		$documentRelIds = $this->processBodyParts(run: $run, bodyParts: $bodyParts);

		// Single reconciliation of the two heavily-shared index parts.
		$this->reconcileContentTypes(zip: $zip, partNames: $run->overridePartNames());
		$this->reconcileRels(
			zip: $zip,
			prune: new RelsPrune(
				relsName: 'word/_rels/document.xml.rels',
				targetBasenames: $run->documentRelTargets(),
				hyperlinkIds: $documentRelIds
			)
		);

		$zip->close();

		return $this->buildReport(counts: $run->counts());
	}//end sanitize()

	/**
	 * Copy the source to the destination (when they differ) and open it.
	 *
	 * Encryption probe: an OOXML CFB (encrypted) package exposes an
	 * "EncryptedPackage" stream rather than the OPC part names. If the
	 * central directory does not contain [Content_Types].xml, treat it as
	 * encrypted/corrupt rather than producing garbage output.
	 *
	 * @param string $sourcePath Path to the source DOCX.
	 * @param string $destPath Path the sanitized DOCX is written to.
	 *
	 * @return ZipArchive The open destination archive.
	 *
	 * @throws SanitizationException When the copy fails or the package cannot be opened.
	 */
	private function openPackage(string $sourcePath, string $destPath): ZipArchive {
		$this->copyToDestination(sourcePath: $sourcePath, destPath: $destPath);

		$zip = new ZipArchive();
		$code = $zip->open($destPath);
		if ($code !== true) {
			throw $this->mapOpenFailure(code: $code);
		}

		if ($zip->locateName('[Content_Types].xml') === false) {
			$zip->close();
			throw new SanitizationException(
				reason: SanitizationException::REASON_ENCRYPTED,
				message: 'Document is encrypted or not a valid OOXML package'
			);
		}

		return $zip;
	}//end openPackage()

	/**
	 * Copy the source document to the destination when the paths differ.
	 *
	 * @param string $sourcePath Path to the source DOCX.
	 * @param string $destPath Path the sanitized DOCX is written to.
	 *
	 * @return void
	 *
	 * @throws SanitizationException When the copy fails.
	 */
	private function copyToDestination(string $sourcePath, string $destPath): void {
		if ($sourcePath === $destPath) {
			return;
		}

		if (copy($sourcePath, $destPath) === false) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_INTERNAL,
				message: 'Could not copy source document to destination'
			);
		}
	}//end copyToDestination()

	/**
	 * Run the inline passes over every body part.
	 *
	 * Non-document body parts keep their own rels file, reconciled here in a
	 * single touch; the document part's hyperlink ids are returned for the
	 * shared reconciliation at the end.
	 *
	 * @param DocxSanitizationRun $run The sanitisation run.
	 * @param string[] $bodyParts Body part names.
	 *
	 * @return string[] Hyperlink relationship ids flattened in word/document.xml.
	 */
	private function processBodyParts(DocxSanitizationRun $run, array $bodyParts): array {
		$documentRelIds = [];
		foreach ($bodyParts as $part) {
			$relIds = $this->processBodyPart(run: $run, part: $part);
			if ($part === 'word/document.xml') {
				$documentRelIds = array_merge($documentRelIds, $relIds);
			} elseif ($relIds !== []) {
				$relsName = 'word/_rels/' . basename($part) . '.rels';
				$this->reconcileRels(zip: $run->zip, prune: new RelsPrune(relsName: $relsName, targetBasenames: [], hyperlinkIds: $relIds));
			}
		}

		return $documentRelIds;
	}//end processBodyParts()

	/**
	 * Build the per-category report from the counter map.
	 *
	 * @param array<string, int> $counts Counter map.
	 *
	 * @return SanitizationReport
	 */
	private function buildReport(array $counts): SanitizationReport {
		return new SanitizationReport(
			revisions: new SanitizationRevisionCounts(
				commentsRemoved: $counts['commentsRemoved'],
				trackedChangesAccepted: $counts['trackedChangesAccepted'],
				trackedChangesDropped: $counts['trackedChangesDropped'],
				revisionAttributesStripped: $counts['revisionAttributesStripped']
			),
			structure: new SanitizationStructureCounts(
				hyperlinksFlattened: $counts['hyperlinksFlattened'],
				metadataFieldsScrubbed: $counts['metadataFieldsScrubbed'],
				customXmlPartsDropped: $counts['customXmlPartsDropped'],
				fieldCodesStripped: $counts['fieldCodesStripped']
			),
			sentinelApplied: $this->sentinel
		);
	}//end buildReport()

	/**
	 * Body-bearing XML parts that may contain inline markup.
	 *
	 * @param ZipArchive $zip The open archive.
	 *
	 * @return string[] Part names present in the archive.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function bodyParts(ZipArchive $zip): array {
		$parts = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = $zip->getNameIndex($index);
			if ($name === false) {
				continue;
			}

			if (preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#', $name) === 1) {
				$parts[] = $name;
			}
		}

		return $parts;
	}//end bodyParts()

	/**
	 * Run all inline passes on a single body part (one load / one save).
	 *
	 * @param DocxSanitizationRun $run The sanitisation run.
	 * @param string $part The body part name.
	 *
	 * @return string[] Relationship IDs of flattened external hyperlinks in this part.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function processBodyPart(DocxSanitizationRun $run, string $part): array {
		$xml = $run->zip->getFromName($part);
		if ($xml === false || $xml === '') {
			return [];
		}

		$dom = $this->loadXml(xml: $xml);
		$xpath = $this->wXpath(dom: $dom);

		$this->removeCommentMarkers(xpath: $xpath);
		$this->resolveTrackedChanges(xpath: $xpath, run: $run);
		$this->unwrapDataBoundSdt(xpath: $xpath);
		$run->count(category: 'fieldCodesStripped', amount: $this->stripSimpleFields(dom: $dom));
		$run->count(category: 'fieldCodesStripped', amount: $this->stripComplexFields(dom: $dom));
		$relIds = $this->flattenHyperlinkElements(xpath: $xpath, run: $run);

		$run->zip->addFromString($part, $dom->saveXML());

		return $relIds;
	}//end processBodyPart()

	/**
	 * Remove comment parts; record content-types + rels removal targets.
	 *
	 * @param DocxSanitizationRun $run The sanitisation run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function removeCommentParts(DocxSanitizationRun $run): void {
		$zip = $run->zip;
		$commentsXml = $zip->getFromName('word/comments.xml');
		if ($commentsXml !== false && $commentsXml !== '') {
			$dom = $this->loadXml(xml: $commentsXml);
			$run->count(category: 'commentsRemoved', amount: $this->wXpath(dom: $dom)->query('//w:comment')->length);
		}

		$commentParts = ['word/comments.xml', 'word/commentsExtended.xml', 'word/commentsIds.xml', 'word/people.xml'];
		foreach ($commentParts as $part) {
			if ($zip->locateName($part) !== false) {
				$zip->deleteName($part);
			}

			$run->dropOverride(partName: $part);
		}

		foreach (['comments.xml', 'commentsExtended.xml', 'commentsIds.xml', 'people.xml'] as $target) {
			$run->dropDocumentRelTarget(basename: $target);
		}
	}//end removeCommentParts()

	/**
	 * Remove inline comment markers from a body-part DOM.
	 *
	 * @param DOMXPath $xpath The body-part xpath context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function removeCommentMarkers(DOMXPath $xpath): void {
		foreach (['//w:commentRangeStart', '//w:commentRangeEnd', '//w:commentReference'] as $query) {
			foreach (iterator_to_array($xpath->query($query)) as $node) {
				$node->parentNode?->removeChild($node);
			}
		}
	}//end removeCommentMarkers()

	/**
	 * Accept tracked-change inserts, drop deletions, strip revision attributes.
	 *
	 * @param DOMXPath $xpath The body-part xpath context (its document is the body-part DOM).
	 * @param DocxSanitizationRun $run The sanitisation run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function resolveTrackedChanges(DOMXPath $xpath, DocxSanitizationRun $run): void {
		// Unwrap <w:ins> (accept insert).
		foreach (iterator_to_array($xpath->query('//w:ins')) as $ins) {
			$this->unwrap(node: $ins);
			$run->count(category: 'trackedChangesAccepted');
		}

		// Remove <w:del> (drop deletion).
		foreach (iterator_to_array($xpath->query('//w:del')) as $del) {
			$del->parentNode?->removeChild($del);
			$run->count(category: 'trackedChangesDropped');
		}

		// Strip revision attributes from every element.
		$run->count(category: 'revisionAttributesStripped', amount: $this->stripRevisionAttributes(dom: $xpath->document));
	}//end resolveTrackedChanges()

	/**
	 * Remove w:rsid* revision attributes from all elements.
	 *
	 * @param DOMDocument $dom The document.
	 *
	 * @return int The number of attributes removed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function stripRevisionAttributes(DOMDocument $dom): int {
		$removed = 0;
		$rsidAttrs = ['rsidR', 'rsidRPr', 'rsidDel', 'rsidTr', 'rsidP', 'rsidRDefault'];
		$elements = iterator_to_array($dom->getElementsByTagName('*'));
		foreach ($elements as $element) {
			foreach ($rsidAttrs as $attr) {
				if ($element->hasAttributeNS(self::NS_W, $attr) === true) {
					$element->removeAttributeNS(self::NS_W, $attr);
					$removed++;
				}
			}
		}

		return $removed;
	}//end stripRevisionAttributes()

	/**
	 * Remove all custom XML parts; record content-types + rels removal targets.
	 *
	 * @param DocxSanitizationRun $run The sanitisation run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function stripCustomXmlParts(DocxSanitizationRun $run): void {
		$zip = $run->zip;
		$toDelete = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = $zip->getNameIndex($index);
			if ($name === false) {
				continue;
			}

			if (preg_match('#^customXml/item\d*\.xml$#', $name) === 1) {
				$run->count(category: 'customXmlPartsDropped');
				$toDelete[] = $name;
				$run->dropOverride(partName: $name);
				// Document.xml.rels targets customXml as "../customXml/itemN.xml".
				$run->dropDocumentRelTarget(basename: basename($name));
			} elseif (preg_match('#^customXml/(itemProps\d*\.xml|_rels/.*)$#', $name) === 1) {
				$toDelete[] = $name;
			}
		}

		foreach ($toDelete as $part) {
			$zip->deleteName($part);
		}
	}//end stripCustomXmlParts()

	/**
	 * Unwrap data-bound <w:sdt> in a body-part DOM (preserve visible content).
	 *
	 * Custom XML part counting happens in {@see stripCustomXmlParts}; this pass
	 * only reshapes the body so no bound binding survives.
	 *
	 * @param DOMXPath $xpath The body-part xpath context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function unwrapDataBoundSdt(DOMXPath $xpath): void {
		foreach (iterator_to_array($xpath->query('//w:sdt[.//w:dataBinding]')) as $sdt) {
			$contentNodes = $xpath->query('./w:sdtContent', $sdt);
			$content = null;
			if ($contentNodes->length > 0) {
				$content = $contentNodes->item(0);
			}

			if ($content instanceof DOMNode) {
				$this->replaceWithChildren(node: $sdt, source: $content);
				continue;
			}

			$sdt->parentNode?->removeChild($sdt);
		}
	}//end unwrapDataBoundSdt()

	/**
	 * Scrub document metadata fields to the sentinel.
	 *
	 * @param DocxSanitizationRun $run The sanitisation run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubMetadata(DocxSanitizationRun $run): void {
		$run->count(category: 'metadataFieldsScrubbed', amount: $this->scrubCorePart(zip: $run->zip));
		$run->count(category: 'metadataFieldsScrubbed', amount: $this->scrubAppPart(zip: $run->zip));
		$run->count(category: 'metadataFieldsScrubbed', amount: $this->scrubCustomPart(zip: $run->zip));
	}//end scrubMetadata()

	/**
	 * Scrub docProps/core.xml fields.
	 *
	 * @param ZipArchive $zip The open archive.
	 *
	 * @return int The number of fields scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubCorePart(ZipArchive $zip): int {
		$xml = $zip->getFromName('docProps/core.xml');
		if ($xml === false || $xml === '') {
			return 0;
		}

		$dom = $this->loadXml(xml: $xml);
		$fields = [
			['http://purl.org/dc/elements/1.1/', 'creator'],
			['http://schemas.openxmlformats.org/package/2006/metadata/core-properties', 'lastModifiedBy'],
			['http://purl.org/dc/elements/1.1/', 'title'],
			['http://purl.org/dc/elements/1.1/', 'subject'],
			['http://schemas.openxmlformats.org/package/2006/metadata/core-properties', 'keywords'],
			['http://purl.org/dc/elements/1.1/', 'description'],
			['http://schemas.openxmlformats.org/package/2006/metadata/core-properties', 'category'],
			['http://schemas.openxmlformats.org/package/2006/metadata/core-properties', 'contentStatus'],
		];

		$count = $this->scrubElements(dom: $dom, fields: $fields);
		$zip->addFromString('docProps/core.xml', $dom->saveXML());
		return $count;
	}//end scrubCorePart()

	/**
	 * Scrub docProps/app.xml fields (Company, Manager).
	 *
	 * @param ZipArchive $zip The open archive.
	 *
	 * @return int The number of fields scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubAppPart(ZipArchive $zip): int {
		$xml = $zip->getFromName('docProps/app.xml');
		if ($xml === false || $xml === '') {
			return 0;
		}

		$dom = $this->loadXml(xml: $xml);
		$count = 0;
		foreach (['Company', 'Manager'] as $tag) {
			$nodes = $dom->getElementsByTagName($tag);
			foreach (iterator_to_array($nodes) as $node) {
				assert($node instanceof DOMNode);
				if (trim($node->textContent) !== '') {
					$node->textContent = $this->sentinel;
					$count++;
				}
			}
		}

		$zip->addFromString('docProps/app.xml', $dom->saveXML());
		return $count;
	}//end scrubAppPart()

	/**
	 * Scrub string-typed custom document properties.
	 *
	 * @param ZipArchive $zip The open archive.
	 *
	 * @return int The number of properties scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubCustomPart(ZipArchive $zip): int {
		$xml = $zip->getFromName('docProps/custom.xml');
		if ($xml === false || $xml === '') {
			return 0;
		}

		$dom = $this->loadXml(xml: $xml);
		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('vt', 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes');
		$count = 0;
		$values = $xpath->query('//vt:lpwstr | //vt:lpstr');
		if ($values === false) {
			$values = [];
		}

		foreach (iterator_to_array($values) as $value) {
			assert($value instanceof DOMNode);
			if (trim($value->textContent) !== '') {
				$value->textContent = $this->sentinel;
				$count++;
			}
		}

		$zip->addFromString('docProps/custom.xml', $dom->saveXML());
		return $count;
	}//end scrubCustomPart()

	/**
	 * Replace each present, non-empty namespaced element's text with the sentinel.
	 *
	 * @param DOMDocument $dom The document.
	 * @param array<int, array{0:string,1:string}> $fields Namespace-URI / local-name pairs.
	 *
	 * @return int The number of elements scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function scrubElements(DOMDocument $dom, array $fields): int {
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
	}//end scrubElements()

	/**
	 * Strip simple-form <w:fldSimple> person-identity fields.
	 *
	 * @param DOMDocument $dom The document.
	 *
	 * @return int The number of fields removed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function stripSimpleFields(DOMDocument $dom): int {
		$count = 0;
		$xpath = $this->wXpath(dom: $dom);
		foreach (iterator_to_array($xpath->query('//w:fldSimple')) as $field) {
			if (($field instanceof DOMElement) === false) {
				continue;
			}

			$instr = $field->getAttributeNS(self::NS_W, 'instr');
			if ($this->instructionMatches(instr: $instr) === true) {
				$field->parentNode?->removeChild($field);
				$count++;
			}
		}

		return $count;
	}//end stripSimpleFields()

	/**
	 * Strip complex-form <w:fldChar> person-identity fields.
	 *
	 * Walks each paragraph's runs sequentially, collecting <w:instrText> from
	 * begin → separate, and removes begin → end inclusive when the normalised
	 * instruction is in the strip list.
	 *
	 * @param DOMDocument $dom The document.
	 *
	 * @return int The number of fields removed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function stripComplexFields(DOMDocument $dom): int {
		$count = 0;
		$xpath = $this->wXpath(dom: $dom);
		foreach (iterator_to_array($xpath->query('//w:p')) as $paragraph) {
			$runs = iterator_to_array($xpath->query('./w:r', $paragraph));
			$count += $this->stripParagraphComplexFields(xpath: $xpath, runs: $runs);
		}

		return $count;
	}//end stripComplexFields()

	/**
	 * Strip the matching complex fields among one paragraph's runs.
	 *
	 * @param DOMXPath $xpath The xpath context.
	 * @param DOMNode[] $runs The paragraph's <w:r> runs, in order.
	 *
	 * @return int The number of fields removed.
	 */
	private function stripParagraphComplexFields(DOMXPath $xpath, array $runs): int {
		$count = 0;
		$runsCount = count($runs);
		$cursor = 0;
		while ($cursor < $runsCount) {
			if ($this->fldCharType(xpath: $xpath, run: $runs[$cursor]) !== 'begin') {
				$cursor++;
				continue;
			}

			[$instr, $span] = $this->scanComplexField(xpath: $xpath, runs: array_slice($runs, $cursor));
			$end = ($cursor + $span);

			if ($this->instructionMatches(instr: $instr) === true) {
				for ($remove = $cursor; $remove <= $end; $remove++) {
					$runs[$remove]->parentNode?->removeChild($runs[$remove]);
				}

				$count++;
			}

			$cursor = ($end + 1);
		}//end while

		return $count;
	}//end stripParagraphComplexFields()

	/**
	 * Collect a complex field's instruction text and find its matching end.
	 *
	 * Without a matching end the field spans only its begin run.
	 *
	 * @param DOMXPath $xpath The xpath context.
	 * @param DOMNode[] $runs The paragraph's <w:r> runs from the field's begin run on, in order.
	 *
	 * @return array{0: string, 1: int} The instruction text and the end-run offset from the begin run.
	 */
	private function scanComplexField(DOMXPath $xpath, array $runs): array {
		$instr = '';
		$depth = 0;
		$runsCount = count($runs);
		for ($scan = 0; $scan < $runsCount; $scan++) {
			$type = $this->fldCharType(xpath: $xpath, run: $runs[$scan]);
			if ($type === 'begin') {
				$depth++;
			} elseif ($type === 'end' && --$depth === 0) {
				return [$instr, $scan];
			}

			$instr .= $this->runInstructionText(xpath: $xpath, run: $runs[$scan]);
		}

		return [$instr, 0];
	}//end scanComplexField()

	/**
	 * Concatenated <w:instrText> content of one run.
	 *
	 * @param DOMXPath $xpath The xpath context.
	 * @param DOMNode $run The <w:r> run node.
	 *
	 * @return string
	 */
	private function runInstructionText(DOMXPath $xpath, DOMNode $run): string {
		$instr = '';
		foreach (iterator_to_array($xpath->query('.//w:instrText', $run)) as $instrNode) {
			$instr .= $instrNode->textContent;
		}

		return $instr;
	}//end runInstructionText()

	/**
	 * Read the w:fldCharType of a run, or empty string if it has no fldChar.
	 *
	 * @param DOMXPath $xpath The xpath context.
	 * @param DOMNode $run The <w:r> run node.
	 *
	 * @return string The fldChar type (begin / separate / end) or ''.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function fldCharType(DOMXPath $xpath, DOMNode $run): string {
		$nodes = $xpath->query('./w:fldChar', $run);
		if ($nodes->length === 0) {
			return '';
		}

		$fldChar = $nodes->item(0);
		if (($fldChar instanceof DOMElement) === false) {
			return '';
		}

		return $fldChar->getAttributeNS(self::NS_W, 'fldCharType');
	}//end fldCharType()

	/**
	 * Whether a normalised field instruction names a person-identity field.
	 *
	 * @param string $instr The raw instruction text.
	 *
	 * @return bool True when the field name is in the strip list.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function instructionMatches(string $instr): bool {
		$normalised = strtoupper(trim($instr));
		// Field name is the first whitespace-delimited token.
		$parts = preg_split('/\s+/', $normalised);
		if ($parts === false || count($parts) === 0) {
			return false;
		}

		return in_array($parts[0], self::FIELD_STRIP_LIST, true);
	}//end instructionMatches()

	/**
	 * Flatten hyperlink elements in a body-part DOM; return seen relationship IDs.
	 *
	 * @param DOMXPath $xpath The body-part xpath context.
	 * @param DocxSanitizationRun $run The sanitisation run.
	 *
	 * @return string[] The relationship IDs of flattened external hyperlinks.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function flattenHyperlinkElements(DOMXPath $xpath, DocxSanitizationRun $run): array {
		$relIds = [];
		foreach (iterator_to_array($xpath->query('//w:hyperlink')) as $link) {
			if (($link instanceof DOMElement) === true) {
				$relId = $link->getAttributeNS(self::NS_R, 'id');
				if ($relId !== '') {
					$relIds[$relId] = true;
				}
			}

			$this->unwrap(node: $link);
			$run->count(category: 'hyperlinksFlattened');
		}

		return array_keys($relIds);
	}//end flattenHyperlinkElements()

	/**
	 * Reconcile [Content_Types].xml: drop all collected Override PartNames once.
	 *
	 * @param ZipArchive $zip The open archive.
	 * @param string[] $partNames Override PartNames to drop (with leading slash).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function reconcileContentTypes(ZipArchive $zip, array $partNames): void {
		if ($partNames === []) {
			return;
		}

		$xml = $zip->getFromName('[Content_Types].xml');
		if ($xml === false || $xml === '') {
			return;
		}

		$dom = $this->loadXml(xml: $xml);
		$changed = false;
		foreach (iterator_to_array($dom->getElementsByTagName('Override')) as $override) {
			if (in_array($override->getAttribute('PartName'), $partNames, true) === true) {
				$override->parentNode?->removeChild($override);
				$changed = true;
			}
		}

		if ($changed === true) {
			$zip->addFromString('[Content_Types].xml', $dom->saveXML());
		}
	}//end reconcileContentTypes()

	/**
	 * Reconcile a rels part once: drop Relationship entries by Target basename
	 * and drop hyperlink Relationship entries by Id.
	 *
	 * @param ZipArchive $zip The open archive.
	 * @param RelsPrune $prune The rels part and the entries to drop from it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function reconcileRels(ZipArchive $zip, RelsPrune $prune): void {
		if ($prune->isEmpty() === true) {
			return;
		}

		$xml = $zip->getFromName($prune->relsName);
		if ($xml === false || $xml === '') {
			return;
		}

		$dom = $this->loadXml(xml: $xml);
		if ($prune->applyTo(dom: $dom) === true) {
			$zip->addFromString($prune->relsName, $dom->saveXML());
		}
	}//end reconcileRels()

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
	 * Replace a node with the child nodes of a designated source element.
	 *
	 * @param DOMNode $node The node to replace.
	 * @param DOMNode $source The node whose children become the replacement.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function replaceWithChildren(DOMNode $node, DOMNode $source): void {
		$parent = $node->parentNode;
		if ($parent === null) {
			return;
		}

		foreach (iterator_to_array($source->childNodes) as $child) {
			$parent->insertBefore($child, $node);
		}

		$parent->removeChild($node);
	}//end replaceWithChildren()

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
		// LIBXML_NONET blocks network access; PHP 8 + libxml >= 2.9 disables
		// external entity loading by default, keeping this XXE-safe.
		$loaded = $dom->loadXML($xml, (LIBXML_NONET | LIBXML_COMPACT));
		if ($loaded === false) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_CORRUPT_ZIP,
				message: 'A document part was not well-formed XML'
			);
		}

		return $dom;
	}//end loadXml()

	/**
	 * Build an xpath context with the w: and r: namespaces registered.
	 *
	 * @param DOMDocument $dom The document.
	 *
	 * @return DOMXPath The namespace-aware xpath context.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function wXpath(DOMDocument $dom): DOMXPath {
		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('w', self::NS_W);
		$xpath->registerNamespace('r', self::NS_R);
		return $xpath;
	}//end wXpath()

	/**
	 * Map a ZipArchive::open failure code to a SanitizationException.
	 *
	 * @param int $code The ZipArchive error code.
	 *
	 * @return SanitizationException The mapped exception.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function mapOpenFailure(int $code): SanitizationException {
		return new SanitizationException(
			reason: SanitizationException::REASON_CORRUPT_ZIP,
			message: sprintf('Could not open document ZIP (code %d)', $code)
		);
	}//end mapOpenFailure()
}//end class
