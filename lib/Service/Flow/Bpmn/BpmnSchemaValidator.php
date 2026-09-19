<?php

/**
 * The boundary check: is this document BPMN 2.0 at all.
 *
 * 🔴 IT VALIDATES AGAINST THE VENDORED OMG SCHEMA SET, UNMODIFIED. The five
 * files under `schema/` are the normative machine-readable documents of BPMN
 * 2.0.2, byte for byte as OMG publishes them, with their checksums and their
 * provenance recorded in `schema/PROVENANCE.md` and asserted by a test. Camunda
 * and Flowable both widen `calledElement` from `xsd:QName` to `xsd:string` in
 * their copies; we do not. If our own export ever produces something the
 * unmodified schema rejects, that is a finding about the export, and the export
 * is what changes.
 *
 * 🔑 WHY A SEPARATE ANSWER MATTERS. Before this, a malformed file was walked
 * into a flow with missing nodes and read as a successful import. "Your file is
 * malformed, at this element, on this line" is `BpmnSchemaInvalid`; "we cannot
 * express this construct" is the mapping report. A mapping report over a
 * malformed document attributes XML problems to process constructs, which is
 * the confusion this class exists to end.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

use DOMDocument;
use OCA\OpenRegister\Exception\BpmnSchemaInvalid;

/**
 * Validates a BPMN document against the vendored, version-pinned OMG XSD set.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
class BpmnSchemaValidator {

	/**
	 * The BPMN version the vendored set describes.
	 *
	 * @var string
	 */
	public const BPMN_VERSION = '2.0.2';

	/**
	 * The root schema; the other four are reached from it by relative
	 * `schemaLocation`, which is why all five sit in one directory.
	 *
	 * @var string
	 */
	public const ROOT_SCHEMA = 'BPMN20.xsd';

	/**
	 * The vendored files and their SHA-256 sums, as fetched on 2026-09-19.
	 *
	 * 🔴 A SILENT EDIT TO A VENDORED SCHEMA MUST REDDEN. That is what these are
	 * for: `BpmnSchemaProvenanceTest` hashes the files on disk against this
	 * list, so "we just relaxed one type to make our export pass" cannot happen
	 * without a failing test saying so by name.
	 *
	 * @var array<string, string>
	 */
	public const CHECKSUMS = [
		'BPMN20.xsd'   => 'a07c159cb0594573dd7c97b1370dd116112378f377e43c89a8bf512ac5030705',
		'BPMNDI.xsd'   => 'f0dff1cd559d1514d8ebfc8c646f58402bcaced27ec22e2aa6456c2dcc80b038',
		'DC.xsd'       => 'a2f90e5ad9bb48c6915e4e034b4e27ac838264a1d4f27bfc70dbdfc69351312d',
		'DI.xsd'       => '8220b179c175572df74e08a51bffabe957867962035cee7b5fee0b6acb4c4498',
		'Semantic.xsd' => 'c4318842f7d2bbc262d7954c9452c501db16f0868eac0b8732ec5d7fb384d9a7',
	];

	/**
	 * The directory holding the five vendored schema files.
	 *
	 * @return string The absolute path.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function schemaDirectory(): string {
		return (__DIR__ . DIRECTORY_SEPARATOR . 'schema');
	}//end schemaDirectory()

	/**
	 * The root schema file the validation runs against.
	 *
	 * @return string The absolute path.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function rootSchema(): string {
		return ($this->schemaDirectory() . DIRECTORY_SEPARATOR . self::ROOT_SCHEMA);
	}//end rootSchema()

	/**
	 * The first schema violation in a document, or null when it validates.
	 *
	 * 🔑 FIRST, NOT ALL. libxml reports a cascade after one structural mistake,
	 * and a list of forty consequences of one misplaced element is not a thing
	 * an author can act on. The first one names the place to look.
	 *
	 * @param DOMDocument $document The parsed document.
	 *
	 * @return array{message: string, line: int, element: string}|null The violation.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function firstViolation(DOMDocument $document): ?array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();

		// 🔴 NO NETWORK. The schema set is on disk precisely so that validation
		// never reaches omg.org from inside a request: an air-gapped install
		// would otherwise skip validation silently or hang on it.
		$valid = $this->validateAgainstVendoredSet(document: $document);

		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($valid === true) {
			return null;
		}

		// `libxml_get_errors()` is typed `LibXMLError[]`, so the guard this
		// loop used to carry could only ever be true and both analysers said
		// so. An EMPTY list is the real case to answer: a validation that
		// failed while libxml recorded nothing.
		$first = ($errors[0] ?? null);

		if ($first === null) {
			return [
				'message' => 'The document does not validate against the BPMN 2.0 schema.',
				'line'    => 0,
				'element' => '',
			];
		}

		return [
			'message' => trim((string)$first->message),
			'line'    => (int)$first->line,
			'element' => $this->elementIn(message: (string)$first->message),
		];
	}//end firstViolation()

	/**
	 * Validate against the vendored set, with the schema files reachable.
	 *
	 * 🔴 NEXTCLOUD'S XXE GUARD BLOCKS OUR OWN SCHEMA FILES. `lib/base.php`
	 * installs `libxml_set_external_entity_loader(static fn () => null)`, and
	 * that resolver answers for the PRIMARY document too, not only for
	 * entities a document references. `DOMDocument::schemaValidate($path)`
	 * therefore cannot read `BPMN20.xsd` off the local disk on any running
	 * instance: it returns false with "Failed to load external entity because
	 * the resolver function returned null", every export was refused as
	 * invalid BPMN and every import was refused as malformed. A bare PHP
	 * process installs no such loader, which is why the suite was green while
	 * the feature could not run at all. `MdtoElementCatalogue` carried the
	 * same bug before this one.
	 *
	 * 🔑 WHY A SCOPED LOADER AND NOT `schemaValidateSource()`. The MDTO schema
	 * imports nothing, so reading its bytes and validating the source is
	 * enough there. `BPMN20.xsd` includes `Semantic.xsd` and imports
	 * `BPMNDI.xsd`, which imports `DI.xsd` and `DC.xsd`, and libxml resolves
	 * every one of those through the same loader, asking for them by their
	 * bare relative name. So the loader is swapped for one that serves the
	 * five vendored files and nothing else, and the previous one is put back
	 * before returning, including when validation throws.
	 *
	 * @param DOMDocument $document The parsed document.
	 *
	 * @return bool Whether the document validates.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	private function validateAgainstVendoredSet(DOMDocument $document): bool {
		$restore = $this->installVendoredSchemaLoader();

		try {
			return $document->schemaValidate($this->rootSchema(), LIBXML_NONET);
		} finally {
			$restore();
		}
	}//end validateAgainstVendoredSet()

	/**
	 * The vendored file a schema reference names, or null when it names another.
	 *
	 * 🔴 THIS IS THE WHOLE OF THE WIDENING, SO IT IS AS NARROW AS IT CAN BE.
	 * Only the five files in the vendored directory resolve, by name and after
	 * `realpath()`, so `../../config/config.php`, a symlink out of the
	 * directory and `http://omg.org/...` all come back null and libxml is told
	 * nothing could be loaded. Validation reaches no network and no file the
	 * schema set does not consist of.
	 *
	 * libxml asks for the root by absolute path and for the includes and
	 * imports by their bare relative name, so a relative reference resolves
	 * against the vendored directory rather than the working directory.
	 *
	 * @param string $systemId The system id libxml asks for.
	 *
	 * @return string|null The absolute path, or null when it is not ours.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function resolveSchemaReference(string $systemId): ?string {
		$path = $systemId;
		if (str_starts_with($path, 'file://') === true) {
			$path = substr($path, strlen('file://'));
		}

		$path = rawurldecode($path);
		if ($path === '') {
			return null;
		}

		$directory = realpath($this->schemaDirectory());
		if ($directory === false) {
			return null;
		}

		if (str_starts_with($path, DIRECTORY_SEPARATOR) === false) {
			$path = ($directory . DIRECTORY_SEPARATOR . $path);
		}

		$resolved = realpath($path);
		if ($resolved === false || dirname($resolved) !== $directory) {
			return null;
		}

		if (array_key_exists(basename($resolved), self::CHECKSUMS) === false) {
			return null;
		}

		return $resolved;
	}//end resolveSchemaReference()

	/**
	 * Install the scoped loader and answer how to put the previous one back.
	 *
	 * @return callable(): void The restore.
	 */
	private function installVendoredSchemaLoader(): callable {
		$restore = $this->entityLoaderRestore();

		libxml_set_external_entity_loader(
			function (?string $publicId, string $systemId) {
				$path = $this->resolveSchemaReference(systemId: $systemId);
				if ($path === null) {
					return null;
				}

				$handle = fopen($path, 'rb');
				if ($handle === false) {
					return null;
				}

				return $handle;
			}
		);

		return $restore;
	}//end installVendoredSchemaLoader()

	/**
	 * How to put back the entity loader that was in force.
	 *
	 * PHP 8.4 hands the current resolver back, so it goes back exactly. Below
	 * that there is no way to read it, and restoring the wrong thing is worse
	 * than restoring the equivalent: the BEHAVIOUR is probed instead, and a
	 * process that was refusing to load a local file is left refusing it,
	 * which is the state Nextcloud installs.
	 *
	 * @return callable(): void The restore.
	 */
	private function entityLoaderRestore(): callable {
		if (function_exists('libxml_get_external_entity_loader') === true) {
			$previous = libxml_get_external_entity_loader();

			return static function () use ($previous): void {
				libxml_set_external_entity_loader($previous);
			};
		}

		$blocked = $this->entityLoadingIsBlocked();

		return static function () use ($blocked): void {
			if ($blocked === true) {
				libxml_set_external_entity_loader(static fn (): mixed => null);
				return;
			}

			libxml_set_external_entity_loader(null);
		};
	}//end entityLoaderRestore()

	/**
	 * Whether the current loader refuses a readable local file.
	 *
	 * The probe reads the root schema, which is 2 KB and certainly present;
	 * its own libxml errors are cleared so they cannot be mistaken for a
	 * violation of the document under validation.
	 *
	 * @return bool True when a loader is blocking local reads.
	 */
	private function entityLoadingIsBlocked(): bool {
		$probe    = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded   = $probe->load($this->rootSchema(), LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return ($loaded === false);
	}//end entityLoadingIsBlocked()

	/**
	 * Refuse a document that does not validate, naming the first violation.
	 *
	 * @param DOMDocument $document The parsed document.
	 * @param string      $subject  What the document is, for the sentence.
	 *
	 * @return void
	 *
	 * @throws BpmnSchemaInvalid When it does not validate.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function assertValid(DOMDocument $document, string $subject = 'The file'): void {
		$violation = $this->firstViolation(document: $document);
		if ($violation === null) {
			return;
		}

		$where = '';
		if ($violation['line'] > 0) {
			$where = sprintf(' on line %d', $violation['line']);
		}

		throw new BpmnSchemaInvalid(
			message: sprintf(
				'%s is not valid BPMN %s%s: %s',
				$subject,
				self::BPMN_VERSION,
				$where,
				$violation['message']
			),
			violationLine: $violation['line'],
			element: $violation['element']
		);
	}//end assertValid()

	/**
	 * The element a libxml schema message names, or an empty string.
	 *
	 * A libxml message reads `Element '{ns}local': ...`, and the namespace is
	 * noise to an author looking at their own file, so only the local name
	 * comes back.
	 *
	 * @param string $message The libxml message.
	 *
	 * @return string The element name.
	 */
	private function elementIn(string $message): string {
		$matched = [];
		if (preg_match("/Element '([^']+)'/", $message, $matched) !== 1) {
			return '';
		}

		$name = $matched[1];
		$brace = strrpos($name, '}');
		if ($brace !== false) {
			$name = substr($name, ($brace + 1));
		}

		return $name;
	}//end elementIn()
}//end class
