<?php

/**
 * OpenRegister Mapping Twig Installer
 *
 * Adds the mapping extension and a runtime loader over one mapping service
 * to a Twig environment, so callers outside lib/Twig name neither.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
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

namespace OCA\OpenRegister\Twig;

use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Service\MappingServiceInterface;
use Twig\Environment;

/**
 * Installs the mapping extension and its runtime loader on a Twig environment.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 */
final class MappingTwigInstaller implements MappingTwigInstallerInterface {
	/**
	 * Constructor.
	 *
	 * @param MappingServiceInterface $mappingService The mapping service the runtime's functions call.
	 * @param MappingMapperInterface  $mappingMapper  The mapping mapper the runtime reads mappings from.
	 */
	public function __construct(
		private readonly MappingServiceInterface $mappingService,
		private readonly MappingMapperInterface $mappingMapper,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param Environment $twig The environment to extend.
	 *
	 * @return void
	 */
	public function installOn(Environment $twig): void {
		$twig->addExtension(new MappingExtension());
		$twig->addRuntimeLoader(
			new MappingRuntimeLoader(mappingService: $this->mappingService, mappingMapper: $this->mappingMapper)
		);
	}//end installOn()
}//end class
