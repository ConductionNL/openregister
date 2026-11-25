<?php

/**
 * OpenRegister PromotionComponent
 *
 * One piece of configuration a promotion carries: its kind, its uuid in the source organisation and its exported definition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * One promoted component, as the source organisation holds it.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
final class PromotionComponent {

	/**
	 * A register.
	 *
	 * @var string
	 */
	public const TYPE_REGISTER = 'register';

	/**
	 * A schema.
	 *
	 * @var string
	 */
	public const TYPE_SCHEMA = 'schema';

	/**
	 * A mapping.
	 *
	 * @var string
	 */
	public const TYPE_MAPPING = 'mapping';

	/**
	 * A source.
	 *
	 * @var string
	 */
	public const TYPE_SOURCE = 'source';

	/**
	 * A webhook.
	 *
	 * @var string
	 */
	public const TYPE_WEBHOOK = 'webhook';

	/**
	 * An endpoint.
	 *
	 * @var string
	 */
	public const TYPE_ENDPOINT = 'endpoint';

	/**
	 * Constructor.
	 *
	 * @param string               $type       One of the TYPE_ constants.
	 * @param string|null          $sourceUuid Its uuid in the source organisation, when it has one.
	 * @param array<string, mixed> $definition The exported definition.
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $sourceUuid,
		public readonly array $definition,
	) {

	}//end __construct()
}//end class
