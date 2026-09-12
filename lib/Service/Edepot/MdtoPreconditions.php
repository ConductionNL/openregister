<?php

/**
 * OpenRegister MDTO Preconditions
 *
 * What must be true before a document is generated, and what MDTO requires
 * that the object cannot supply.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The checks that stand between an object and a document.
 *
 * Separate from {@see MdtoXmlGenerator} because they answer a different
 * question: that one knows how to write MDTO, this one knows what must hold
 * before it is worth writing, and what the standard requires that openregister
 * cannot answer. Keeping them apart is also what lets the transfer rule and
 * the serialising rules differ, which is the distinction the TMLO export
 * endpoint depends on.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoPreconditions {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration for organisation settings.
	 * @param LoggerInterface $logger Logger for error and warning messages.
	 * @param MdtoSourceReader $sourceReader Resolver for the declared archival values.
	 * @param MdtoBestandGenerator $bestandGenerator Owner of the per-file input rules.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly MdtoSourceReader $sourceReader,
		private readonly MdtoBestandGenerator $bestandGenerator,
	) {
	}//end __construct()

	/**
	 * Refuse to TRANSFER a record whose retention period is unknown.
	 *
	 * This is openregister's own policy, not MDTO's. The standard marks
	 * `bewaartermijn` "Verplicht: Ja, indien bekend", so {@see MdtoXmlGenerator::generate()}
	 * omits the element when there is no period, which keeps an export honest
	 * and is what the `/export/mdto` endpoint needs. Handing a record to an
	 * e-Depot without saying how long it must be kept is a different act, and
	 * the packaging path refuses it by calling this first.
	 *
	 * Separating the two is what lets one generator serve both callers. While
	 * the rule lived inside `generate()`, exporting the metadata of a record
	 * with no retention period was impossible.
	 *
	 * @param ObjectEntity $object The object about to be packaged.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the object has no retention period.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function assertTransferPreconditions(ObjectEntity $object): void {
		if ($this->sourceReader->coreFacts(object: $object)['retentionPeriod'] === null) {
			throw new InvalidArgumentException(
				'Cannot transfer object ' . $object->getUuid()
				. ' to an e-Depot: it has no retention period. MDTO allows the element to be absent;'
				. ' openregister refuses the transfer.'
			);
		}
	}//end assertTransferPreconditions()


	/**
	 * List the MDTO-required elements this document fills with a default.
	 *
	 * The output validates, so no required element is ABSENT. What this
	 * reports is the required element whose value is the standard's "not
	 * recorded" term rather than something the object says, so a reader of
	 * the log can tell a default from a fact.
	 *
	 * @param ObjectEntity $object The object to inspect.
	 *
	 * @return list<string> One line per defaulted element; empty when there is none.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function collectMdtoRequiredElementGaps(ObjectEntity $object): array {
		$gaps = [];

		if ($this->sourceReader->useRestriction(object: $object) === null) {
			$gaps[] = 'beperkingGebruik: MDTO-XML1.0.1 declares minOccurs="1", and this object declares no '
				. 'use restriction and carries no active legal hold, so it is emitted as "'
				. MdtoXmlGenerator::USE_RESTRICTION_UNRECORDED . '"';
		}

		return $gaps;
	}//end collectMdtoRequiredElementGaps()


	/**
	 * Check the inputs the generator needs, and refuse what the XSD would reject.
	 *
	 * A pass here means the generator can build a document the XSD accepts
	 * for the elements it emits. It is not a judgement of the record, and it
	 * does not replace the schema: `MdtoXmlGeneratorXsdTest` is what shows the
	 * output validates.
	 *
	 * - `uuid` and the `organisation_identifier` setting fill `identificatie`.
	 * - a non-empty `naam`.
	 * - the appraisal, which must map onto the CLOSED Waarderingen list; see
	 *   {@see MdtoXmlGenerator::APPRAISAL_MAP}.
	 * - the retention period, WHEN the object has one, which must then be an
	 *   `xsd:duration`. Its absence is not refused here: MDTO marks
	 *   `bewaartermijn` "Verplicht: Ja, indien bekend" and `minOccurs="0"`, so
	 *   a record whose period is unknown is exported without the element.
	 *   Refusing to TRANSFER such a record is a separate, local policy, and it
	 *   lives at the transfer boundary in {@see EdepotTransferService}.
	 *
	 * Both are read through {@see MdtoSourceReader}, so they are found in the
	 * `retention` block or the `tmlo` block, whichever the object carries.
	 * - every file's inputs; see {@see MdtoBestandGenerator::missingInputs()}.
	 *
	 * @param ObjectEntity $object The object to check.
	 * @param array $files Associated file metadata.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If an input is missing or malformed.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function assertGeneratorPreconditions(ObjectEntity $object, array $files): void {
		$missing = [];

		if (empty($object->getUuid()) === true) {
			$missing[] = 'uuid';
		}

		if ($this->sourceReader->name(object: $object) === '') {
			$missing[] = 'naam';
		}

		$facts = $this->sourceReader->coreFacts(object: $object);
		$nominatie = $facts['appraisal'];
		if ($nominatie === null || isset(MdtoXmlGenerator::APPRAISAL_MAP[$nominatie]) === false) {
			$missing[] = 'archiefnominatie (one of: ' . implode(', ', array_keys(MdtoXmlGenerator::APPRAISAL_MAP)) . ')';
		}

		$period = $facts['retentionPeriod'];
		if ($period !== null && self::isXsdDuration(value: $period) === false) {
			$missing[] = 'bewaartermijn (an ISO-8601 / xsd:duration such as P20Y)';
		}

		if ($this->appConfig->getValueString('openregister', 'organisation_identifier', '') === '') {
			$missing[] = 'app_setting:organisation_identifier';
		}

		$missing = array_merge($missing, $this->bestandGenerator->missingInputs(files: $files));

		if (empty($missing) === false) {
			$missingStr = implode(', ', $missing);
			$this->logger->error(
				message: '[MdtoXmlGenerator] Cannot generate MDTO XML, inputs missing: ' . $missingStr,
				context: ['objectUuid' => $object->getUuid()]
			);
			throw new InvalidArgumentException(
				'Cannot generate MDTO XML for object ' . $object->getUuid()
				. '. These generator inputs are missing: ' . $missingStr
				. '. This check covers the generator inputs only and is not an MDTO validity check.'
			);
		}
	}//end assertGeneratorPreconditions()


	/**
	 * Log the MDTO-required elements the document fills with a default.
	 *
	 * @param ObjectEntity $object The object being exported.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function reportMdtoRequiredElementGaps(ObjectEntity $object): void {
		$gaps = $this->collectMdtoRequiredElementGaps(object: $object);
		if (empty($gaps) === true) {
			return;
		}

		$this->logger->warning(
			message: '[MdtoXmlGenerator] Required MDTO elements carry the standard\'s "not recorded" term',
			context: ['objectUuid' => $object->getUuid(), 'gaps' => $gaps]
		);
	}//end reportMdtoRequiredElementGaps()


	/**
	 * Whether a value is in the lexical space of `xsd:duration`.
	 *
	 * Stricter than PHP's DateInterval, which also accepts weeks (`P2W`);
	 * `xsd:duration` has no week designator.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	private static function isXsdDuration(mixed $value): bool {
		if (is_string($value) === false) {
			return false;
		}

		$pattern = '/^-?P(?=\d|T\d)(\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?$/';
		return preg_match($pattern, $value) === 1;
	}//end isXsdDuration()
}//end class
