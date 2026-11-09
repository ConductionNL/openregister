<?php

/**
 * OpenRegister MDTO Value Sources
 *
 * Where the values of an MDTO informatieobject document come from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCP\IAppConfig;

/**
 * Where the values of an MDTO informatieobject document come from: the
 * organisation settings, the object's event history and its declared
 * archival values.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
 */
final class MdtoValueSources {

	/**
	 * Bundle the sources.
	 *
	 * @param IAppConfig       $appConfig    The app configuration for organisation settings.
	 * @param MdtoEventMapper  $eventMapper  Source of the MDTO event history.
	 * @param MdtoSourceReader $sourceReader Resolver for the declared archival values.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly MdtoEventMapper $eventMapper,
		public readonly MdtoSourceReader $sourceReader,
	) {
	}//end __construct()
}//end class
