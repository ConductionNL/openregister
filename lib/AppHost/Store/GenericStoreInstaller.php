<?php

/**
 * OpenRegister AppHost — Generic Store Installer
 *
 * Writes the components a resolved store item declares into the calling app's
 * register, refusing every schema its manifest does not allow.
 *
 * WHY THIS IS GENERIC AT ALL, GIVEN ADR-080 DECISION 3.
 * ------------------------------------------------------
 * That decision said install "does not generalise and SHALL NOT be pushed into
 * AppHost", because cloning an application template, enabling a connector
 * adapter and instantiating an agent template are different operations. Its
 * three worked examples of what went wrong are all about a cross-app
 * controller BASE CLASS: `extends` is resolved by the autoloader rather than
 * the container, so an absent OpenRegister 500s every route in the consuming
 * app, leaf tests cannot load the subclass, and phpstan refuses "extends
 * unknown class".
 *
 * None of that applies here. This is a service the engine owns, reached
 * through a route the leaf app ALIASES (the same mechanism GenericHealth and
 * GenericMetrics already use), with no inheritance anywhere.
 *
 * The other half of D3 — that the semantics genuinely differ — is answered by
 * making them DATA. What actually differs between apps is which schemas an
 * install may write; the operation itself was identical in every
 * implementation: refuse what the allowlist does not name, strip the remote
 * identity so the write CREATES, save, and report per component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
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

namespace OCA\OpenRegister\AppHost\Store;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Declarative install of a store item's components.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-an-install-must-refuse-every-schema-the-manifest-does-not-allow
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-an-install-must-create-a-new-object-never-replace-one
 */
class GenericStoreInstaller {
	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService The OpenRegister write path.
	 * @param LoggerInterface $logger        PSR logger, server-side detail only.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Install every allowed component and report every refused one.
	 *
	 * A refusal does NOT abort the install. The remaining components still
	 * arrive and the report names what did not: an item that is half
	 * configuration and half records is the registry's mistake, not a reason to
	 * deny an administrator the half they may have.
	 *
	 * @param StoreManifest        $store The calling app's declared store config.
	 * @param array<string, mixed> $item  The resolved remote item.
	 *
	 * @return array{success: bool, components: array<int, array<string, string>>}
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-an-install-must-refuse-every-schema-the-manifest-does-not-allow
	 */
	public function install(StoreManifest $store, array $item): array {
		$report = [];
		$failed = false;

		foreach ($this->components(item: $item) as $component) {
			$slug = (string)($component['schema'] ?? '');
			$object = ($component['object'] ?? null);

			if ($store->isInstallable(slug: $slug) === false) {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'refused',
					'message' => 'This app does not allow the store to write into that schema.',
				];
				continue;
			}

			if (is_array($object) === false) {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'refused',
					'message' => 'The component declares no object to install.',
				];
				continue;
			}

			try {
				$this->objectService->saveObject(
					object: $this->asNewObject(object: $object),
					register: $store->localRegister,
					schema: $slug
				);
				$report[] = ['schema' => $slug, 'status' => 'installed', 'message' => ''];
			} catch (Throwable $e) {
				$failed = true;
				$this->logger->error(
					message: sprintf(
						'[AppHost\\Store] installing %s for %s failed: %s',
						$slug,
						$store->appId,
						$e->getMessage()
					),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				$report[] = [
					'schema' => $slug,
					'status' => 'error',
					'message' => 'The component could not be written.',
				];
			}
		}

		return ['success' => ($failed === false && $report !== []), 'components' => $report];
	}//end install()

	/**
	 * Read the components a resolved item declares.
	 *
	 * A registry may ship the list as a JSON string rather than an array, the
	 * same way a workflow template stores its steps.
	 *
	 * @param array<string, mixed> $item The resolved remote object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function components(array $item): array {
		$components = ($item['components'] ?? null);
		if (is_string($components) === true) {
			$components = json_decode($components, associative: true);
		}

		if (is_array($components) === false) {
			return [];
		}

		return array_values(array_filter($components, static fn ($c): bool => is_array($c) === true));
	}//end components()

	/**
	 * Strip every identity the remote payload carries, so an install CREATES.
	 *
	 * 🔴 WITHOUT THIS, "INSTALL" IS AN OVERWRITE PRIMITIVE.
	 *
	 * `ObjectService::saveObject()` resolves the object it is writing FROM the
	 * payload: `extractUuidAndNormalizeObject()` reads
	 * `$object['@self']['id'] ?? $object['id']` and treats a match as the uuid
	 * to UPDATE. So a store item whose component carried the uuid of this
	 * instance's live configuration would replace it rather than add one, and
	 * the write is PUT-semantic, so keys the payload omits are nulled rather
	 * than left alone. The object would not merely change, it would be gutted.
	 *
	 * The schema allowlist does not cover this. It governs WHICH schema a
	 * component may write, never whether the write creates or replaces, so an
	 * entirely legitimate component is the attack.
	 *
	 * Identity is not a remote registry's to supply. An installed item is a NEW
	 * local object, and if install ever needs to be idempotent it must key on
	 * something the app controls rather than on a remote id.
	 *
	 * @param array<string, mixed> $object The component's object.
	 *
	 * @return array<string, mixed> The object with every identity key removed.
	 */
	private function asNewObject(array $object): array {
		unset($object['id'], $object['uuid'], $object['@self']);

		return $object;
	}//end asNewObject()
}//end class
