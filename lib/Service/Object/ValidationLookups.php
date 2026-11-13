<?php

/**
 * OpenRegister validation lookups
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * What a validation looks existing objects and users up in.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ValidationLookups {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface    $objectMapper Object mapper.
	 * @param IUserManager            $userManager  Backend consulted by the `user` string format.
	 * @param ContainerInterface|null $container    Hands out the organisation service on first use, which
	 *                                              depends on the settings that depend on this validator.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly IUserManager $userManager,
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * The language this request resolved to, or null without a container or a resolver.
	 *
	 * @return string|null The BCP 47 tag.
	 *
	 * @spec openspec/changes/modelling-validation-messages/specs/schema-validation-messages/spec.md#requirement-a-failed-rule-returns-the-declared-message-in-the-request-language
	 */
	public function preferredLanguage(): ?string {
		if ($this->container === null) {
			return null;
		}

		try {
			return $this->container->get(LanguageService::class)->getPreferredLanguage();
		} catch (Throwable $e) {
			return null;
		}
	}//end preferredLanguage()

	/**
	 * The organisation service, or null without a container.
	 *
	 * @return OrganisationServiceInterface|null The service.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function organisations(): ?OrganisationServiceInterface {
		if ($this->container === null) {
			return null;
		}

		return $this->container->get(OrganisationServiceInterface::class);
	}//end organisations()
}//end class
