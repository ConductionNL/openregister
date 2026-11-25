<?php

/**
 * One configuration component a promotion carries: its kind, slug, uuid and portable definition.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * A schema, mapping, source, webhook or endpoint as it travels between organisations.
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */
final class PromotionComponent {

	/**
	 * The component kinds a promotion carries, in the order they are drafted.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['schema', 'mapping', 'source', 'webhook', 'endpoint'];

	/**
	 * Constructor.
	 *
	 * @param string               $kind       One of KINDS.
	 * @param string               $slug       The component's slug, its name in the draft key.
	 * @param string|null          $uuid       The component's uuid in the source organisation.
	 * @param array<string, mixed> $definition The portable definition, without instance fields.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $slug,
		public readonly ?string $uuid,
		public readonly array $definition,
	) {
	}//end __construct()

	/**
	 * The draft key the component lands under: `schema.<slug>` or `integration.<kind>.<slug>`.
	 *
	 * Both are open prefixes of the configuration key registry, so a promotion
	 * drafts through the same address check as any hand-drafted value.
	 *
	 * @return string The configuration key.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public function configKey(): string {
		if ($this->kind === 'schema') {
			return 'schema.'.$this->slug;
		}

		return 'integration.'.$this->kind.'.'.$this->slug;
	}//end configKey()

	/**
	 * The same component with another definition.
	 *
	 * @param array<string, mixed> $definition The new definition.
	 *
	 * @return self The component.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public function withDefinition(array $definition): self {
		return new self(kind: $this->kind, slug: $this->slug, uuid: $this->uuid, definition: $definition);
	}//end withDefinition()
}//end class
