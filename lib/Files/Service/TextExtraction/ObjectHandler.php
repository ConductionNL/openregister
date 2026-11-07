<?php

/**
 * Object Text Extraction Handler
 *
 * Handles text extraction from OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use Exception;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Handler for extracting text from OpenRegister objects.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
class ObjectHandler implements TextExtractionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $objectMapper Object mapper.
	 * @param ChunkMapper $chunkMapper Chunk mapper.
	 * @param SchemaMapperInterface $schemaMapper Schema mapper.
	 * @param RegisterMapperInterface $registerMapper Register mapper.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly ChunkMapper $chunkMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the source type this handler supports.
	 *
	 * @return string Source type identifier.
	 *
	 * @psalm-return 'object'
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function getSourceType(): string {
		return 'object';
	}//end getSourceType()

	/**
	 * Extract text from an object.
	 *
	 * @param int $sourceId Object ID.
	 * @param array<string, mixed> $sourceMeta Object metadata.
	 * @param bool $force Force re-extraction.
	 *
	 * @return array{
	 *     source_type: string,
	 *     source_id: int,
	 *     text: string,
	 *     length: int,
	 *     checksum: string,
	 *     method: string,
	 *     owner: string|null,
	 *     organisation: string|null,
	 *     language: string|null,
	 *     language_level: string|null,
	 *     language_confidence: float|null,
	 *     detection_method: string|null,
	 *     metadata: array<string, mixed>
	 * }
	 *
	 * @throws Exception When extraction fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Force parameter follows interface contract
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $sourceMeta and $force kept to honour interface contract
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function extractText(int $sourceId, array $sourceMeta, bool $force = false): array {
		$this->logger->info(
			message: '[ObjectHandler] Extracting text from object',
			context: ['file' => __FILE__, 'line' => __LINE__, 'objectId' => $sourceId]
		);

		$object = $this->objectMapper->find($sourceId);
		$text = implode("\n", $this->textParts(object: $object, sourceId: $sourceId));

		return [
			'source_type' => 'object',
			'source_id' => $sourceId,
			...$this->textFields(text: $text),
			...$this->objectFields(object: $object),
		];
	}//end extractText()

	/**
	 * The lines of an object's text: id, version, schema, register, content and organisation.
	 *
	 * The `Object ID:` line is always there, so the text is never empty.
	 *
	 * @param ObjectEntity $object   The object.
	 * @param int          $sourceId The object id asked for, for the log.
	 *
	 * @return array<int, string>
	 */
	private function textParts(ObjectEntity $object, int $sourceId): array {
		$textParts = ['Object ID: ' . $object->getUuid()];
		if ($object->getVersion() !== null) {
			$textParts[] = 'Version: ' . $object->getVersion();
		}

		return array_merge(
			$textParts,
			$this->schemaParts(object: $object, sourceId: $sourceId),
			$this->registerParts(object: $object, sourceId: $sourceId),
			$this->contentParts(object: $object),
			$this->organisationParts(object: $object)
		);
	}//end textParts()

	/**
	 * The schema lines of an object's text; a schema that cannot be loaded is logged and left out.
	 *
	 * Uses getTitle() only. Schema has no `name` property, so the getName()
	 * fallback that used to sit here threw "name is not a valid attribute" out
	 * of Entity::__call for any schema without a title. The test doubles
	 * declared getName() with addMethods(), which invents it on the mock.
	 *
	 * @param ObjectEntity $object   The object.
	 * @param int          $sourceId The object id asked for, for the log.
	 *
	 * @return array<int, string>
	 */
	private function schemaParts(ObjectEntity $object, int $sourceId): array {
		try {
			if ($object->getSchema() === null) {
				return [];
			}

			$schema = $this->schemaMapper->find($object->getSchema());
			return $this->describedParts(entity: $schema, labels: ['Type', 'Schema Description']);
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[ObjectHandler] Could not load schema',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $sourceId, 'schema_id' => $object->getSchema()]
			);
			return [];
		}
	}//end schemaParts()

	/**
	 * The register lines of an object's text; a register that cannot be loaded is logged and left out.
	 *
	 * Uses getTitle() only, for the same reason as the schema: Register
	 * declares no `name` property either.
	 *
	 * @param ObjectEntity $object   The object.
	 * @param int          $sourceId The object id asked for, for the log.
	 *
	 * @return array<int, string>
	 */
	private function registerParts(ObjectEntity $object, int $sourceId): array {
		try {
			if ($object->getRegister() === null) {
				return [];
			}

			$register = $this->registerMapper->find($object->getRegister());
			return $this->describedParts(entity: $register, labels: ['Register', 'Register Description']);
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[ObjectHandler] Could not load register',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $sourceId, 'register_id' => $object->getRegister()]
			);
			return [];
		}
	}//end registerParts()

	/**
	 * A schema's or register's title line, plus its description line when it has one.
	 *
	 * @param Schema|Register              $entity The schema or register.
	 * @param array{0: string, 1: string} $labels The title and description labels.
	 *
	 * @return array<int, string>
	 */
	private function describedParts(Schema|Register $entity, array $labels): array {
		$parts = [$labels[0] . ': ' . ($entity->getTitle() ?? 'Unknown')];
		if ($entity->getDescription() !== null && $entity->getDescription() !== '') {
			$parts[] = $labels[1] . ': ' . $entity->getDescription();
		}

		return $parts;
	}//end describedParts()

	/**
	 * The `Content:` line of an object's text, when its data yields any text.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, string>
	 */
	private function contentParts(ObjectEntity $object): array {
		$objectData = $object->getObject();
		if (is_array($objectData) === false) {
			return [];
		}

		$extractedText = $this->extractTextFromArray(data: $objectData, path: new ObjectTextPath(prefix: '', depth: 0));
		if (empty($extractedText) === true) {
			return [];
		}

		return ['Content: ' . $extractedText];
	}//end contentParts()

	/**
	 * The `Organisation:` line of an object's text, when it has an organisation.
	 *
	 * NOTE the spelling: the entity's property, column and accessor are all
	 * `organisation`. `getOrganization()` does not exist and throws
	 * "organization is not a valid attribute" out of Entity::__call.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, string>
	 */
	private function organisationParts(ObjectEntity $object): array {
		$organisation = $object->getOrganisation();
		if ($organisation !== null && $organisation !== '') {
			return ['Organisation: ' . $organisation];
		}

		return [];
	}//end organisationParts()

	/**
	 * The text fields of the extraction result.
	 *
	 * @param string $text The extracted text.
	 *
	 * @return array{text: string, length: int, checksum: string, method: string}
	 */
	private function textFields(string $text): array {
		return [
			'text' => $text,
			'length' => strlen($text),
			'checksum' => hash('sha256', $text),
			'method' => 'object_extraction',
		];
	}//end textFields()

	/**
	 * The object fields of the extraction result; language detection is left to a later step.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function objectFields(ObjectEntity $object): array {
		return [
			'owner' => $object->getOwner(),
			'organisation' => $object->getOrganisation(),
			'language' => null,
			'language_level' => null,
			'language_confidence' => null,
			'detection_method' => null,
			'metadata' => [
				'uuid' => $object->getUuid(),
				'schema_id' => $object->getSchema(),
				'register_id' => $object->getRegister(),
				'version' => $object->getVersion(),
			],
		];
	}//end objectFields()

	/**
	 * Check if object needs extraction.
	 *
	 * @param int $sourceId Object ID.
	 * @param int $sourceTimestamp Object modification timestamp.
	 * @param bool $force Force flag.
	 *
	 * @return bool True if extraction is needed.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function needsExtraction(int $sourceId, int $sourceTimestamp, bool $force): bool {
		if ($force === true) {
			return true;
		}

		// Check if chunks exist and are up-to-date.
		$latestChunkTimestamp = $this->chunkMapper->getLatestUpdatedTimestamp(sourceType: 'object', sourceId: $sourceId);

		if ($latestChunkTimestamp === null) {
			return true;
		}

		return $latestChunkTimestamp < $sourceTimestamp;
	}//end needsExtraction()

	/**
	 * Get object metadata.
	 *
	 * @param int $sourceId Object ID.
	 *
	 * @return (\DateTime|int|mixed|null|string)[] Object metadata.
	 *
	 * @throws DoesNotExistException If object not found.
	 *
	 * @psalm-return array{id: int, uuid: null|string, schema: null|string,
	 *     register: null|string, version: null|string, organisation: null|string,
	 *     owner: null|string, updated: \DateTime|null}
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function getSourceMetadata(int $sourceId): array {
		$object = $this->objectMapper->find($sourceId);

		return [
			'id' => $object->getId(),
			'uuid' => $object->getUuid(),
			'schema' => $object->getSchema(),
			'register' => $object->getRegister(),
			'version' => $object->getVersion(),
			'organisation' => $object->getOrganisation(),
			'owner' => $object->getOwner(),
			'updated' => $object->getUpdated(),
		];
	}//end getSourceMetadata()

	/**
	 * Get object modification timestamp.
	 *
	 * @param int $sourceId Object ID.
	 *
	 * @return int Unix timestamp.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function getSourceTimestamp(int $sourceId): int {
		try {
			$object = $this->objectMapper->find($sourceId);
			return $object->getUpdated()?->getTimestamp() ?? time();
		} catch (DoesNotExistException $e) {
			return time();
		}
	}//end getSourceTimestamp()

	/**
	 * Recursively extract text from nested arrays/objects.
	 *
	 * @param array          $data Data to extract text from.
	 * @param ObjectTextPath $path Key prefix and recursion depth of the data.
	 *
	 * @return string Extracted text.
	 */
	private function extractTextFromArray(array $data, ObjectTextPath $path): string {
		// Prevent excessive recursion.
		if ($path->depth > 10) {
			return '';
		}

		$textParts = [];
		foreach ($data as $key => $value) {
			$line = $this->valueText(value: $value, entry: new ObjectTextPath(prefix: $path->key(key: $key), depth: $path->depth));
			if ($line !== null) {
				$textParts[] = $line;
			}
		}

		return implode("\n", $textParts);
	}//end extractTextFromArray()

	/**
	 * The text of one value: a nested array's lines, or a `key: value` line.
	 *
	 * @param mixed          $value The value.
	 * @param ObjectTextPath $entry The value's context key and the depth of its array.
	 *
	 * @return string|null The text, or null when the value yields none.
	 */
	private function valueText(mixed $value, ObjectTextPath $entry): ?string {
		if (is_array($value) === true) {
			return $this->nestedText(value: $value, entry: $entry);
		}

		return $this->scalarText(value: $value, contextKey: $entry->prefix);
	}//end valueText()

	/**
	 * The lines of a non-empty nested array, one level deeper.
	 *
	 * @param array          $value The nested array.
	 * @param ObjectTextPath $entry Its context key and the depth of the array holding it.
	 *
	 * @return string|null The text, or null when the array yields none.
	 */
	private function nestedText(array $value, ObjectTextPath $entry): ?string {
		if (empty($value) === true) {
			return null;
		}

		$nestedText = $this->extractTextFromArray(data: $value, path: new ObjectTextPath(prefix: $entry->prefix, depth: ($entry->depth + 1)));
		if (empty($nestedText) === true) {
			return null;
		}

		return $nestedText;
	}//end nestedText()

	/**
	 * The `key: value` line of a non-blank string, a number or a boolean.
	 *
	 * @param mixed  $value      The value.
	 * @param string $contextKey The dotted key path.
	 *
	 * @return string|null The line, or null for any other value.
	 */
	private function scalarText(mixed $value, string $contextKey): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return "{$contextKey}: {$value}";
		}

		if (is_numeric($value) === true) {
			return "{$contextKey}: {$value}";
		}

		if (is_bool($value) === true) {
			return $contextKey . ': ' . var_export($value, true);
		}

		return null;
	}//end scalarText()
}//end class
