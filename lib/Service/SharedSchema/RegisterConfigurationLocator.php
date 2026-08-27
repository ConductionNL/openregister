<?php

/**
 * OpenRegister RegisterConfigurationLocator
 *
 * Reads back the schema definitions a register's own app configuration declares
 * for it, so a shared schema entity can be attributed to the register whose
 * configuration actually describes it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SharedSchema;

use OCA\OpenRegister\Db\Register;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve "what does this register's app configuration say its schemas look like?".
 *
 * Discovery mirrors {@see \OCA\OpenRegister\AppHost\Service\AppHostSettingsService}:
 * a base `lib/Settings/<appId>_register.json` with `lib/Settings/register.d/*.json`
 * fragments deep-merged over it in sorted filename order. The glob is widened to
 * every `*_register.json` because OpenRegister itself ships several documents
 * rather than one monolith, and its own registers would otherwise resolve to
 * nothing.
 *
 * This is deliberately the ONLY evidence source used for attribution. The
 * alternatives were considered and rejected: the schema's `application` column
 * is what the last import stamped, so on a shared entity it names the register
 * that overwrote it rather than the one that owns it; and "lowest register id"
 * is not evidence at all. Both are what the existing
 * `openregister:schemas:dedup` command uses, and both silently pick a side.
 *
 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
 */
class RegisterConfigurationLocator {

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $appManager Resolves an app id to its on-disk path.
	 * @param LoggerInterface $logger     Records unreadable configuration documents.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the schema definitions a register's app configuration declares for it.
	 *
	 * @param Register $register The register to resolve configuration for.
	 *
	 * @return array<string, array<string, mixed>> Lowercased schema slug => definition.
	 *         Empty when the app ships no configuration naming this register.
	 *
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function schemasFor(Register $register): array {
		$appId = (string)$register->getApplication();
		if ($appId === '') {
			return [];
		}

		try {
			$appPath = $this->appManager->getAppPath($appId);
		} catch (Throwable $e) {
			unset($e);
			return [];
		}

		$settings = ($appPath . '/lib/Settings');
		$slug     = strtolower((string)$register->getSlug());

		foreach ($this->documents(directory: $settings) as $document) {
			$data = $this->readJson(path: $document);
			if ($data === null) {
				continue;
			}

			if (basename($document) === ($appId . '_register.json')) {
				$data = $this->mergeFragments(base: $data, directory: ($settings . '/register.d'));
			}

			$schemas = self::schemasForRegisterSlug(document: $data, registerSlug: $slug);
			if ($schemas !== []) {
				return $schemas;
			}
		}

		return [];
	}//end schemasFor()

	/**
	 * Pull one register's schema definitions out of a configuration document.
	 *
	 * @param array<string, mixed> $document     The merged configuration document.
	 * @param string               $registerSlug The lowercased register slug to look for.
	 *
	 * @return array<string, array<string, mixed>> Lowercased schema slug => definition.
	 */
	private static function schemasForRegisterSlug(array $document, string $registerSlug): array {
		$components = ($document['components'] ?? []);
		if (is_array($components) === false) {
			return [];
		}

		$registers = ($components['registers'] ?? []);
		$schemas   = ($components['schemas'] ?? []);
		if (is_array($registers) === false || is_array($schemas) === false) {
			return [];
		}

		$declared = self::findRegister(registers: $registers, registerSlug: $registerSlug);
		if ($declared === null) {
			return [];
		}

		return self::pickSchemas(schemas: $schemas, wanted: self::declaredSlugs(declared: $declared));
	}//end schemasForRegisterSlug()

	/**
	 * Find the register entry whose slug matches.
	 *
	 * The map key is accepted as a fallback slug because a fragment may declare a
	 * register by key alone.
	 *
	 * @param array<mixed> $registers    The `components.registers` map.
	 * @param string       $registerSlug The lowercased register slug.
	 *
	 * @return array<string, mixed>|null The register definition, or null.
	 */
	private static function findRegister(array $registers, string $registerSlug): ?array {
		foreach ($registers as $key => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			if (strtolower((string)($definition['slug'] ?? $key)) === $registerSlug) {
				return $definition;
			}
		}

		return null;
	}//end findRegister()

	/**
	 * The schema slugs a register definition declares.
	 *
	 * @param array<string, mixed> $declared The register definition.
	 *
	 * @return array<string, bool> Lowercased slug => true.
	 */
	private static function declaredSlugs(array $declared): array {
		$wanted = [];
		foreach (((array)($declared['schemas'] ?? [])) as $entry) {
			if (is_scalar($entry) === true) {
				$wanted[strtolower((string)$entry)] = true;
			}
		}

		return $wanted;
	}//end declaredSlugs()

	/**
	 * Filter the document's schema map down to the wanted slugs.
	 *
	 * @param array<mixed>        $schemas The `components.schemas` map.
	 * @param array<string, bool> $wanted  Lowercased slug => true.
	 *
	 * @return array<string, array<string, mixed>> Lowercased slug => definition.
	 */
	private static function pickSchemas(array $schemas, array $wanted): array {
		$result = [];
		foreach ($schemas as $key => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$slug = strtolower((string)($definition['slug'] ?? $key));
			if (isset($wanted[$slug]) === true) {
				$result[$slug] = $definition;
			}
		}

		return $result;
	}//end pickSchemas()

	/**
	 * List the candidate configuration documents in an app's settings directory.
	 *
	 * @param string $directory The `lib/Settings` directory.
	 *
	 * @return string[] The absolute paths, in glob order.
	 */
	private function documents(string $directory): array {
		$documents = glob($directory . '/*_register.json');
		if ($documents === false) {
			return [];
		}

		return $documents;
	}//end documents()

	/**
	 * Deep-merge every `register.d` fragment over a base document.
	 *
	 * @param array<string, mixed> $base      The base document.
	 * @param string               $directory The fragment directory.
	 *
	 * @return array<string, mixed> The merged document.
	 */
	private function mergeFragments(array $base, string $directory): array {
		$fragments = glob($directory . '/*.json');
		if ($fragments === false) {
			return $base;
		}

		sort($fragments);

		foreach ($fragments as $fragment) {
			$data = $this->readJson(path: $fragment);
			if ($data !== null) {
				$base = self::deepMerge(base: $base, overlay: $data);
			}
		}

		return $base;
	}//end mergeFragments()

	/**
	 * Merge an overlay over a base the way the settings loader does.
	 *
	 * Associative arrays merge key by key; list entries append; overlay scalars win.
	 *
	 * @param array<mixed> $base    The base array.
	 * @param array<mixed> $overlay The overlay array.
	 *
	 * @return array<mixed> The merged array.
	 */
	private static function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_int($key) === true) {
				$base[] = $value;
				continue;
			}

			if (isset($base[$key]) === true && is_array($base[$key]) === true && is_array($value) === true) {
				$base[$key] = self::deepMerge(base: $base[$key], overlay: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end deepMerge()

	/**
	 * Read and decode one JSON document.
	 *
	 * @param string $path The absolute path.
	 *
	 * @return array<string, mixed>|null The decoded document, or null when unreadable.
	 */
	private function readJson(string $path): ?array {
		if (is_readable($path) === false) {
			return null;
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			$this->logger->warning(
				message: '[SharedSchemaDedupe] Unreadable configuration document ' . $path,
				context: ['file' => __FILE__, 'line' => __LINE__],
			);
			return null;
		}

		return $data;
	}//end readJson()
}//end class
