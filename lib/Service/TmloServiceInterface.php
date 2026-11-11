<?php

/**
 * TmloServiceInterface: the part of TmloService that the TMLO controller uses, and the archiefstatus values it lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tmlo-validation/spec.md#scenario-valid-iso-8601-duration-accepted
 */

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;

/**
 * The methods of TmloService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface TmloServiceInterface {

	/**
	 * Valid values for archiefstatus field.
	 *
	 * TmloService inherits them, so TmloService::ARCHIEFSTATUS_* still resolves.
	 */
	public const ARCHIEFSTATUS_ACTIEF = 'actief';
	public const ARCHIEFSTATUS_SEMI_STATISCH = 'semi_statisch';
	public const ARCHIEFSTATUS_OVERGEBRACHT = 'overgebracht';
	public const ARCHIEFSTATUS_VERNIETIGD = 'vernietigd';

	/**
	 * Check if TMLO is enabled for a given register.
	 *
	 * @param Register $register The register to check
	 *
	 * @return bool True if TMLO is enabled
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-2
	 */
	public function isTmloEnabled(Register $register): bool;

	/**
	 * Generate the MDTO document for a single object.
	 *
	 * ## One implementation of the format, not two
	 *
	 * This used to build its own DOM, and the result did not validate: its
	 * root was `mdto:informatieobject` where the XSD declares `MDTO`, and it
	 * emitted `archiefactiedatum`, `archiefstatus` and `vernietigingsCategorie`,
	 * none of which is an MDTO element. It now delegates to
	 * {@see MdtoXmlGenerator}, which is held to the vendored XSD by a test, so
	 * this endpoint and the e-Depot export cannot drift apart again.
	 *
	 * The TMLO facts are not lost. {@see \OCA\OpenRegister\Service\Edepot\MdtoSourceReader}
	 * reads the `tmlo` block as well as the `retention` block, so
	 * `bewaarTermijn` becomes `bewaartermijn/termijnLooptijd`,
	 * `archiefactiedatum` becomes `bewaartermijn/termijnEinddatum`,
	 * `vernietigingsCategorie` becomes `informatiecategorie` and
	 * `classification` becomes `classificatie`. Only `archiefstatus` has no
	 * MDTO element, and MDTO expresses that lifecycle through `event`
	 * instead, which the generator derives from the audit trail.
	 *
	 * @param ObjectEntity $object The object to export
	 *
	 * @return string The MDTO XML string
	 *
	 * @throws InvalidArgumentException If the object has no TMLO metadata
	 *
	 * @spec openspec/specs/tmlo-export/spec.md#requirement-mdto-compliant-xml-export
	 */
	public function generateMdtoXml(ObjectEntity $object): string;

	/**
	 * Generate MDTO documents for multiple objects, in one envelope.
	 *
	 * MDTO has no batch container: an `MDTO` document holds exactly one
	 * informatieobject or one bestand. So the envelope element is openregister's
	 * own, in openregister's own namespace, and every child of it is a
	 * complete, valid MDTO document. Nothing here claims the envelope is MDTO,
	 * which is the mistake the previous `mdto:informatieobjecten` wrapper made
	 * by putting a made-up element in the MDTO namespace.
	 *
	 * An object without TMLO metadata is skipped rather than failing the batch.
	 *
	 * @param ObjectEntity[] $objects Array of objects to export
	 *
	 * @return string The envelope XML
	 *
	 * @spec openspec/specs/tmlo-export/spec.md#requirement-mdto-compliant-xml-export
	 */
	public function generateBatchMdtoXml(array $objects): string;
}//end interface
