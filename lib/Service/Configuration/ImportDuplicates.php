<?php

/**
 * Refuses an import that meets a duplicate register or schema slug.
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

use Exception;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Names the rows behind a duplicate slug, so an admin can find and resolve them.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportDuplicates
{

	/**
	 * Build the reporter.
	 *
	 * @param RegisterMapperInterface $registerMapper The register mapper.
	 * @param SchemaMapperInterface   $schemaMapper   The schema mapper.
	 * @param LoggerInterface         $logger         The logger.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stop an import on a register slug with several rows.
	 *
	 * @param string $slug   The duplicate slug.
	 * @param string $source The app and version, as ImportDuplicates::source() words them.
	 *
	 * @return never
	 *
	 * @throws Exception Always.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function refuseRegister(string $slug, string $source): never
	{
		$this->refuse(kind: 'register', facts: [$source, $slug, $this->describeRegisters(slug: $slug)]);
	}//end refuseRegister()

	/**
	 * Stop an import on a schema slug with several rows.
	 *
	 * @param string $slug   The duplicate slug.
	 * @param string $source The app and version, as ImportDuplicates::source() words them.
	 *
	 * @return never
	 *
	 * @throws Exception Always.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function refuseSchema(string $slug, string $source): never
	{
		$this->refuse(kind: 'schema', facts: [$source, $slug, $this->describeSchemas(slug: $slug)]);
	}//end refuseSchema()

	/**
	 * Word the app and version an import came from.
	 *
	 * @param string|null $appId   The app, when known.
	 * @param string|null $version The version, when known.
	 *
	 * @return string The source as the error names it.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function source(?string $appId, ?string $version): string
	{
		return sprintf("'%s' (version %s)", ($appId ?? 'unknown'), ($version ?? 'unknown'));
	}//end source()

	/**
	 * Describe the registers sharing a slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string One entry per register, or why there is none.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function describeRegisters(string $slug): string
	{
		try {
			return $this->describe(slug: $slug, rows: $this->registerMapper->findAll());
		} catch (Exception $e) {
			return 'Unable to retrieve duplicate information: '.$e->getMessage();
		}
	}//end describeRegisters()

	/**
	 * Describe the schemas sharing a slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string One entry per schema, or why there is none.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function describeSchemas(string $slug): string
	{
		try {
			return $this->describe(slug: $slug, rows: $this->schemaMapper->findAll());
		} catch (Exception $e) {
			return 'Unable to retrieve duplicate information: '.$e->getMessage();
		}
	}//end describeSchemas()

	/**
	 * Log and throw the duplicate error.
	 *
	 * @param string            $kind  The word for the entity: register or schema.
	 * @param array<int,string> $facts The source, the slug and the description of the rows.
	 *
	 * @return never
	 *
	 * @throws Exception Always.
	 */
	private function refuse(string $kind, array $facts): never
	{
		$message  = "Duplicate {$kind} detected during import from app {$facts[0]}. ";
		$message .= ucfirst($kind)." with slug '{$facts[1]}' has multiple entries in the database: {$facts[2]}. ";
		$message .= "Please resolve this by removing duplicate entries or updating the {$kind} ";
		$message .= "slugs to be unique. You can identify duplicates by checking {$kind}s with ";
		$message .= 'the same slug, uuid, or id.';

		$this->logger->error(message: ImportLogTag::PREFIX.$message, context: ['file' => __FILE__, 'line' => __LINE__]);
		throw new Exception($message);
	}//end refuse()

	/**
	 * Describe the rows that carry a slug, ignoring case.
	 *
	 * @param string $slug The slug.
	 * @param array  $rows The registers or schemas to look through.
	 *
	 * @return string One entry per row, or a notice when the slug is not duplicated.
	 */
	private function describe(string $slug, array $rows): string
	{
		$matches = array_filter($rows, static fn ($row): bool => strtolower($row->getSlug() ?? '') === strtolower($slug));
		if (count($matches) <= 1) {
			return 'Unable to retrieve detailed duplicate information';
		}

		return implode('; ', array_map(fn ($row): string => $this->entry(row: $row), $matches));
	}//end describe()

	/**
	 * One row as the error lists it.
	 *
	 * @param mixed $row The register or schema.
	 *
	 * @return string The row's id, uuid, title and creation time.
	 */
	private function entry(mixed $row): string
	{
		return sprintf(
			"ID: %s, UUID: %s, Title: '%s', Created: %s",
			$row->getId(),
			($row->getUuid() ?? ''),
			($row->getTitle() ?? ''),
			($row->getCreated()?->format('Y-m-d H:i:s') ?? 'unknown')
		);
	}//end entry()
}//end class
