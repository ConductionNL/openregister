<?php

/**
 * The attach picker's answer: where the caller may save a file, and which objects match.
 *
 * Gathers each register's schemas with the platform's own verdict on whether
 * the caller may change their objects, hands them to {@see AttachTargetFilter}
 * to decide what may be offered, and searches object titles in the offerable
 * schemas only. The filter decides; this class only collects what it decides on.
 *
 * A schema holds files unless its configuration sets `allowFiles` to false:
 * every object has a files leaf, and the flag is how a schema opts out.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\Integration
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
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\Integration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects the picker's candidates and searches the offerable ones.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */
class AttachTargetSearch {

	/**
	 * The rule that decides what may be offered.
	 *
	 * @var AttachTargetFilter
	 */
	private readonly AttachTargetFilter $filter;

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface   $registerMapper The registers.
	 * @param SchemaMapperInterface     $schemaMapper   The schemas.
	 * @param PermissionCheckInterface  $permissions    The platform's verdict on who may change what.
	 * @param ObjectOperationsInterface $objects        The object search.
	 * @param LoggerInterface           $logger         The logger.
	 * @param AttachTargetFilter|null   $filter         The offer rule.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly PermissionCheckInterface $permissions,
		private readonly ObjectOperationsInterface $objects,
		private readonly LoggerInterface $logger,
		?AttachTargetFilter $filter = null,
	) {
		$this->filter = ($filter ?? new AttachTargetFilter());
	}//end __construct()

	/**
	 * The schemas the caller may save into, and the objects whose title matches.
	 *
	 * @param string                 $search   The title the caller typed; '' lists the targets only.
	 * @param array<int,string>|null $declared The consuming app's `attachTargets`, or null when none was pinned.
	 *
	 * @return array{targets: array<int,array<string,mixed>>, results: array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
	 */
	public function find(string $search, ?array $declared = null): array {
		$candidates = $this->candidates();
		$targets    = $this->filter->offerableSchemas(
			candidates: array_column($candidates, 'candidate'),
			declared: $declared
		);

		$search = trim($search);
		if ($search === '' || $targets === []) {
			return ['targets' => $targets, 'results' => []];
		}

		$offerable = array_column($targets, 'schema');
		$hits      = [];
		foreach ($candidates as $entry) {
			if (in_array($entry['candidate']['schema'], $offerable, true) === false) {
				continue;
			}

			array_push($hits, ...$this->titleHits(entry: $entry, search: $search));
			if (count($hits) >= AttachTargetFilter::MAX_RESULTS) {
				break;
			}
		}

		return ['targets' => $targets, 'results' => $this->filter->searchResults(hits: $hits, offerable: $offerable)];
	}//end find()

	/**
	 * Every register's schemas with the caller's verdicts on them.
	 *
	 * @return array<int,array{candidate: array<string,mixed>, register: Register, schema: Schema}>
	 */
	private function candidates(): array {
		$candidates = [];
		foreach ($this->registerMapper->findAll() as $register) {
			$ids = array_values(array_filter($register->getSchemas(), static fn ($id): bool => is_scalar($id) === true));
			if ($ids === []) {
				continue;
			}

			foreach ($this->schemaMapper->findMultiple(ids: $ids) as $schema) {
				$candidates[] = [
					'candidate' => [
						'schema' => (string)($schema->getSlug() ?? $schema->getId()),
						'register' => (string)($register->getSlug() ?? $register->getId()),
						'label' => (string)($schema->getTitle() ?? $schema->getSlug() ?? ''),
						'writable' => $this->mayChange(schema: $schema, register: $register),
						'hasFilesLeaf' => $this->holdsFiles(schema: $schema),
					],
					'register' => $register,
					'schema' => $schema,
				];
			}
		}

		return $candidates;
	}//end candidates()

	/**
	 * Whether the caller may change objects of a schema, as the platform answers it.
	 *
	 * An answer that cannot be had is a no: a picker that offers on an error
	 * offers what the write will refuse.
	 *
	 * @param Schema   $schema   The schema.
	 * @param Register $register The register it is offered in.
	 *
	 * @return bool
	 */
	private function mayChange(Schema $schema, Register $register): bool {
		try {
			return $this->permissions->hasPermission(
				schema: $schema,
				action: 'update',
				register: (string)$register->getId()
			);
		} catch (Throwable $e) {
			$this->logger->info('[AttachTargetSearch] No write verdict for a schema; not offered', ['error' => $e->getMessage()]);
			return false;
		}
	}//end mayChange()

	/**
	 * Whether a schema's objects hold files.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool
	 */
	private function holdsFiles(Schema $schema): bool {
		return (($schema->getConfiguration() ?? [])['allowFiles'] ?? true) !== false;
	}//end holdsFiles()

	/**
	 * The objects of one register and schema whose title matches.
	 *
	 * @param array{candidate: array<string,mixed>, register: Register, schema: Schema} $entry  The target.
	 * @param string                                                                      $search The title typed.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function titleHits(array $entry, string $search): array {
		try {
			$page = $this->objects->searchObjectsPaginated(
				query: [
					'@self' => ['register' => $entry['register']->getId(), 'schema' => $entry['schema']->getId()],
					'_search' => $search,
					'_limit' => AttachTargetFilter::MAX_RESULTS,
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('[AttachTargetSearch] Title search failed in one schema', ['error' => $e->getMessage()]);
			return [];
		}

		$hits = [];
		foreach (($page['results'] ?? []) as $row) {
			$hit = $this->hit(row: $row, entry: $entry);
			if ($hit !== null) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}//end titleHits()

	/**
	 * A search row as a picker hit, or null when it carries no id.
	 *
	 * @param mixed                                                                       $row   An object entity or its rendered array.
	 * @param array{candidate: array<string,mixed>, register: Register, schema: Schema} $entry The target it was found in.
	 *
	 * @return array<string,string>|null
	 */
	private function hit(mixed $row, array $entry): ?array {
		if ($row instanceof ObjectEntity) {
			$uuid  = (string)$row->getUuid();
			$title = (string)($row->getName() ?? '');
		} else if (is_array($row) === true) {
			$uuid  = (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
			$title = (string)($row['@self']['name'] ?? $row['name'] ?? $row['title'] ?? '');
		} else {
			return null;
		}

		if ($uuid === '') {
			return null;
		}

		if ($title === '') {
			$title = $uuid;
		}

		return [
			'objectUuid' => $uuid,
			'title' => $title,
			'schema' => $entry['candidate']['schema'],
			'register' => $entry['candidate']['register'],
		];
	}//end hit()
}//end class
