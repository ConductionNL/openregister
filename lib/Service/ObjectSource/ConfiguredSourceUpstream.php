<?php

/**
 * ConfiguredSourceUpstream — the integration identity a sourced schema calls
 * its OpenConnector source under.
 *
 * The integration router dispatches on an IntegrationProvider and reads the
 * source id from it. A sourced schema names its source in
 * `x-openregister-object-source.config.source`, so this read-only identity
 * carries that id to the router; it lists nothing and writes nothing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;

/**
 * The external, read-only integration identity of one configured source.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
final class ConfiguredSourceUpstream extends AbstractIntegrationProvider {

	/**
	 * Constructor.
	 *
	 * @param string $sourceId The OpenConnector source id the schema names.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function __construct(
		private readonly string $sourceId,
	) {
	}//end __construct()

	/**
	 * The id, naming the source in log lines.
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'openconnector-http:' . $this->sourceId;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string
	 */
	public function getLabel(): string {
		return 'OpenConnector source ' . $this->sourceId;
	}//end getLabel()

	/**
	 * The icon.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return 'Web';
	}//end getIcon()

	/**
	 * The app the source lives in.
	 *
	 * @return string
	 */
	public function getRequiredApp(): string {
		return 'openconnector';
	}//end getRequiredApp()

	/**
	 * Always external: the record lives at the source.
	 *
	 * @return string
	 */
	public function getStorageStrategy(): string {
		return 'external';
	}//end getStorageStrategy()

	/**
	 * The configured source.
	 *
	 * @return string
	 */
	public function getOpenConnectorSource(): string {
		return $this->sourceId;
	}//end getOpenConnectorSource()

	/**
	 * Enabled; the router reports a missing app or source itself.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Lists nothing: records are read through the object-source provider.
	 *
	 * @param ObjectRef            $ref     The owning object.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		return [];
	}//end list()
}//end class
