<?php

/**
 * The Configuration entity an app import creates and keeps up to date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Creates the Configuration of an app import and records what the import brought in.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class AppConfigurationRecord
{

	/**
	 * Build the record keeper.
	 *
	 * @param ConfigurationMapperInterface $configurationMapper The configuration mapper.
	 * @param LoggerInterface     $logger              The logger.
	 */
	public function __construct(
		private readonly ConfigurationMapperInterface $configurationMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create the Configuration of an app that has none yet.
	 *
	 * @param AppImport $import The app import.
	 *
	 * @return Configuration The stored configuration.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function create(AppImport $import): Configuration
	{
		$configuration = $this->configurationFor(import: $import);
		$this->applySource(configuration: $configuration, extension: $import->extension());
		$configuration->setRegisters([]);
		$configuration->setSchemas([]);
		$configuration->setObjects([]);

		$configuration = $this->configurationMapper->insert($configuration);

		$this->logger->debug(
			message: "[ImportHandler] Created new configuration for app {$import->appId}",
			context: ['file' => __FILE__, 'line' => __LINE__, 'configurationId' => $configuration->getId(), 'version' => $import->version]
		);

		return $configuration;
	}//end create()

	/**
	 * Record what the import brought in on its Configuration.
	 *
	 * @param AppImport                   $import The app import.
	 * @param ImportedConfigurationUpdate $update The tracked configuration and the import result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function track(AppImport $import, ImportedConfigurationUpdate $update): void
	{
		$configuration = $update->configuration;
		$this->addImportedEntities(update: $update);
		$configuration->setVersion($import->version);
		$this->applyDeclaredText(configuration: $configuration, import: $import);
		$this->applySource(configuration: $configuration, extension: $import->extension());

		$this->configurationMapper->update($configuration);

		$this->logTracked(import: $import, configuration: $configuration);
	}//end track()

	/**
	 * Log the totals a tracked Configuration holds.
	 *
	 * @param AppImport     $import        The app import.
	 * @param Configuration $configuration The configuration.
	 *
	 * @return void
	 */
	private function logTracked(AppImport $import, Configuration $configuration): void
	{
		$this->logger->debug(
			message: "[ImportHandler] Updated configuration entity for app {$import->appId}",
			context: [
				'file'            => __FILE__,
				'line'            => __LINE__,
				'configurationId' => $configuration->getId(),
				'totalRegisters'  => count($configuration->getRegisters()),
				'totalSchemas'    => count($configuration->getSchemas()),
				'totalObjects'    => count($configuration->getObjects()),
			]
		);
	}//end logTracked()

	/**
	 * A new Configuration with what the import declares and what a local one always is.
	 *
	 * @param AppImport $import The app import.
	 *
	 * @return Configuration
	 */
	private function configurationFor(AppImport $import): Configuration
	{
		$configuration = $this->declared(import: $import);
		$configuration->setApp($import->appId);
		$configuration->setVersion($import->version);

		// A local configuration is maintained by the app.
		$configuration->setIsLocal(true);
		$configuration->setSyncEnabled(false);
		$configuration->setSyncStatus('never');

		$openregister = ($import->extension()['openregister'] ?? null);
		if ($openregister !== null) {
			$configuration->setOpenregister($openregister);
		}

		return $configuration;
	}//end configurationFor()

	/**
	 * A new Configuration with the title, description and type the import declares.
	 *
	 * @param AppImport $import The app import.
	 *
	 * @return Configuration
	 */
	private function declared(AppImport $import): Configuration
	{
		$appId     = $import->appId;
		$data      = $import->data;
		$info      = $import->info();
		$extension = $import->extension();

		$configuration = new Configuration();
		$configuration->setTitle($info['title'] ?? $extension['title'] ?? $data['title'] ?? "Configuration for {$appId}");
		$configuration->setDescription(
			$info['description'] ?? $extension['description'] ?? $data['description'] ?? "Configuration imported by application {$appId}"
		);
		$configuration->setType($extension['type'] ?? $data['type'] ?? 'app');

		return $configuration;
	}//end declared()

	/**
	 * Add the entities of the import result to its Configuration.
	 *
	 * @param ImportedConfigurationUpdate $update The tracked configuration and the import result.
	 *
	 * @return void
	 */
	private function addImportedEntities(ImportedConfigurationUpdate $update): void
	{
		$configuration = $update->configuration;
		$result        = $update->result;

		$registers = array_filter($result['registers'], static fn ($entity): bool => $entity instanceof Register);
		$schemas   = array_filter($result['schemas'], static fn ($entity): bool => $entity instanceof Schema);
		$objects   = array_filter($result['objects'], static fn ($entity): bool => $entity instanceof ObjectEntity);

		$configuration->setRegisters($this->withNewIds(ids: ($configuration->getRegisters() ?? []), entities: $registers));
		$configuration->setSchemas($this->withNewIds(ids: ($configuration->getSchemas() ?? []), entities: $schemas));
		$configuration->setObjects($this->withNewIds(ids: ($configuration->getObjects() ?? []), entities: $objects));
	}//end addImportedEntities()

	/**
	 * Append the ids of entities a list does not hold yet.
	 *
	 * @param array<int, mixed> $ids      The ids so far.
	 * @param array             $entities The entities.
	 *
	 * @return array<int, mixed>
	 */
	private function withNewIds(array $ids, array $entities): array
	{
		foreach ($entities as $entity) {
			if (in_array($entity->getId(), $ids, true) === false) {
				$ids[] = $entity->getId();
			}
		}

		return $ids;
	}//end withNewIds()

	/**
	 * Take over the title and description the import declares, when it does.
	 *
	 * @param Configuration $configuration The configuration.
	 * @param AppImport     $import        The app import.
	 *
	 * @return void
	 */
	private function applyDeclaredText(Configuration $configuration, AppImport $import): void
	{
		$info      = $import->info();
		$extension = $import->extension();

		$title = ($info['title'] ?? $extension['title'] ?? null);
		if ($title !== null) {
			$configuration->setTitle($title);
		}

		$description = ($info['description'] ?? $extension['description'] ?? null);
		if ($description !== null) {
			$configuration->setDescription($description);
		}
	}//end applyDeclaredText()

	/**
	 * Take over where the configuration comes from, when the import says so.
	 *
	 * @param Configuration $configuration The configuration.
	 * @param mixed         $extension     The x-openregister block.
	 *
	 * @return void
	 */
	private function applySource(Configuration $configuration, mixed $extension): void
	{
		if (($extension['sourceType'] ?? null) !== null) {
			$configuration->setSourceType($extension['sourceType']);
		}

		if (($extension['sourceUrl'] ?? null) !== null) {
			$configuration->setSourceUrl($extension['sourceUrl']);
		}

		$this->applyGithub(configuration: $configuration, github: $this->githubSource(extension: $extension));
	}//end applySource()

	/**
	 * The GitHub source: the nested `github` block, or else the flat keys.
	 *
	 * @param mixed $extension The x-openregister block.
	 *
	 * @return array{repo: mixed, branch: mixed, path: mixed}
	 */
	private function githubSource(mixed $extension): array
	{
		if (is_array($extension['github'] ?? null) === true) {
			return [
				'repo'   => ($extension['github']['repo'] ?? null),
				'branch' => ($extension['github']['branch'] ?? null),
				'path'   => ($extension['github']['path'] ?? null),
			];
		}

		return [
			'repo'   => ($extension['githubRepo'] ?? null),
			'branch' => ($extension['githubBranch'] ?? null),
			'path'   => ($extension['githubPath'] ?? null),
		];
	}//end githubSource()

	/**
	 * Set the GitHub repository, branch and path that are known.
	 *
	 * @param Configuration                                  $configuration The configuration.
	 * @param array{repo: mixed, branch: mixed, path: mixed} $github        The GitHub source.
	 *
	 * @return void
	 */
	private function applyGithub(Configuration $configuration, array $github): void
	{
		if ($github['repo'] !== null) {
			$configuration->setGithubRepo($github['repo']);
		}

		if ($github['branch'] !== null) {
			$configuration->setGithubBranch($github['branch']);
		}

		if ($github['path'] !== null) {
			$configuration->setGithubPath($github['path']);
		}
	}//end applyGithub()
}//end class
