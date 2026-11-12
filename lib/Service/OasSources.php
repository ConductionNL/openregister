<?php

/**
 * The mappers, URL generator and optional logger an OpenAPI document is generated with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The mappers, URL generator and optional logger an OpenAPI document is generated with.
 *
 * @spec openspec/specs/oas-generation/spec.md
 */
final class OasSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper.
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper.
	 * @param IURLGenerator           $urlGenerator   URL generator for absolute URLs.
	 * @param ?LoggerInterface        $logger         PSR-3 logger for surfacing validation issues; null is no logging.
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly IURLGenerator $urlGenerator,
		public readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()
}//end class
