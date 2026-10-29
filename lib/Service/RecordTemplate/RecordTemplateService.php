<?php

/**
 * OpenRegister RecordTemplateService
 *
 * Who may use, change and delete a record template, and the checks a template
 * passes before it is stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RecordTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RecordTemplate;

use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Db\RecordTemplateMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RecordTemplateRefused;
use OCA\OpenRegister\Kernel\Contracts\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\Rbac\ViewShareResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists, stores, changes and deletes record templates for one caller.
 *
 * USE NEEDS CREATE RIGHTS. A template is a way to start a record, so a caller
 * who may not create in its schema is not offered it, even when it is public
 * or shared with their group: the list leaves it out and a direct read answers
 * 404, never "it exists but you may not". CHANGE NEEDS OWNERSHIP: only the
 * owner or an administrator changes or deletes one (design D-4); a group share
 * is a way in to USE it, not to edit it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thirteen: the store, the schema read, the
 * create-rights check, the share rules and their refusals; each is one question this class asks.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class RecordTemplateService {

	/**
	 * Reads the shares the way a saved view does.
	 *
	 * @var ViewShareResolver
	 */
	private readonly ViewShareResolver $shares;

	/**
	 * Checks and applies the writable fields.
	 *
	 * @var RecordTemplateInput
	 */
	private readonly RecordTemplateInput $input;

	/**
	 * Create rights per caller and schema, for this request.
	 *
	 * @var array<string, bool>
	 */
	private array $mayCreate = [];

	/**
	 * Constructor.
	 *
	 * @param RecordTemplateMapper $mapper      The template store.
	 * @param SchemaMapperInterface<Schema>         $schemas     Reads the template's schema.
	 * @param PermissionHandler    $permissions Answers whether a caller may create in a schema.
	 * @param ViewerReachResolver  $viewers     Validates shares against existing groups.
	 * @param LoggerInterface      $logger      Logger.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function __construct(
		private readonly RecordTemplateMapper $mapper,
		private readonly SchemaMapperInterface $schemas,
		private readonly PermissionHandler $permissions,
		private readonly ViewerReachResolver $viewers,
		private readonly LoggerInterface $logger,
	) {
		$this->shares = new ViewShareResolver();
		$this->input = new RecordTemplateInput();
	}//end __construct()

	/**
	 * The templates the caller may use, each with the access they hold on it.
	 *
	 * @param ViewerReach $reach    The caller.
	 * @param string|null $register Only templates for this register id.
	 * @param string|null $schema   Only templates for this schema id.
	 *
	 * @return RecordTemplate[] The usable templates.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function listFor(ViewerReach $reach, ?string $register=null, ?string $schema=null): array {
		$usable = [];
		foreach ($this->mapper->findCandidates(userId: $reach->userId, register: $register, schema: $schema) as $template) {
			if ($this->resolveUse(template: $template, reach: $reach) === true) {
				$usable[] = $template;
			}
		}

		return $usable;
	}//end listFor()

	/**
	 * One template the caller may use.
	 *
	 * @param string      $uuid  The template uuid.
	 * @param ViewerReach $reach The caller.
	 *
	 * @return RecordTemplate The template, with the caller's access.
	 *
	 * @throws RecordTemplateRefused 404 when it is absent or the caller may not use it.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function get(string $uuid, ViewerReach $reach): RecordTemplate {
		$template = $this->find(uuid: $uuid);
		if ($this->resolveUse(template: $template, reach: $reach) === false) {
			throw new RecordTemplateRefused(status: 404, message: 'No such record template.');
		}

		return $template;
	}//end get()

	/**
	 * Store a new template owned by the caller.
	 *
	 * @param array<string, mixed> $data  `{name, description?, register, schema, values?, isPublic?, sharedWith?}`.
	 * @param ViewerReach          $reach The caller.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @throws RecordTemplateRefused 400 when the input is unusable, 403 when the caller may not create in the schema.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function create(array $data, ViewerReach $reach): RecordTemplate {
		$fields = $this->input->validated(data: $data, partial: false, shareFindings: $this->shareFindings(...));
		$this->assertMayCreate(schema: (string)$fields['schema'], reach: $reach);

		$template = new RecordTemplate();
		$this->input->apply(template: $template, fields: $fields);
		$template->setOwner($reach->userId);

		$stored = $this->mapper->insert(entity: $template);
		$stored->setAccess(ViewShareResolver::ACCESS_OWNER);

		return $stored;
	}//end create()

	/**
	 * Change a template the caller owns, or any template for an administrator.
	 *
	 * @param string               $uuid    The template uuid.
	 * @param array<string, mixed> $data    The fields to set.
	 * @param ViewerReach          $reach   The caller.
	 * @param bool                 $partial True for PATCH (only the fields sent), false for PUT.
	 *
	 * @return RecordTemplate The stored template.
	 *
	 * @throws RecordTemplateRefused 404, 403 or 400.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) PUT and PATCH differ only in this.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function change(string $uuid, array $data, ViewerReach $reach, bool $partial): RecordTemplate {
		$template = $this->administered(uuid: $uuid, reach: $reach);
		$fields = $this->input->validated(data: $data, partial: $partial, shareFindings: $this->shareFindings(...));
		if (isset($fields['schema']) === true && $fields['schema'] !== $template->getSchema()) {
			$this->assertMayCreate(schema: (string)$fields['schema'], reach: $reach);
		}

		$this->input->apply(template: $template, fields: $fields);
		$stored = $this->mapper->update(entity: $template);
		$this->resolveUse(template: $stored, reach: $reach);

		return $stored;
	}//end change()

	/**
	 * Delete a template the caller owns, or any template for an administrator.
	 *
	 * @param string      $uuid  The template uuid.
	 * @param ViewerReach $reach The caller.
	 *
	 * @return void
	 *
	 * @throws RecordTemplateRefused 404 or 403.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function delete(string $uuid, ViewerReach $reach): void {
		$this->mapper->delete(entity: $this->administered(uuid: $uuid, reach: $reach));
	}//end delete()

	/**
	 * Whether the caller may use the template; sets their access when they may.
	 *
	 * @param RecordTemplate $template The template.
	 * @param ViewerReach    $reach    The caller.
	 *
	 * @return bool True when they may use it.
	 */
	private function resolveUse(RecordTemplate $template, ViewerReach $reach): bool {
		$access = $this->shares->accessFor(view: $template->jsonSerialize(), userId: $reach->userId, userGroups: $reach->groups);
		if ($access === null && $reach->isAdmin === true) {
			$access = ViewShareResolver::ACCESS_WRITE;
		}

		if ($access === null || $this->mayCreateIn(schema: (string)$template->getSchema(), reach: $reach) === false) {
			return false;
		}

		$template->setAccess($access);

		return true;
	}//end resolveUse()

	/**
	 * The template, when the caller may change it.
	 *
	 * @param string      $uuid  The template uuid.
	 * @param ViewerReach $reach The caller.
	 *
	 * @return RecordTemplate The template.
	 *
	 * @throws RecordTemplateRefused 404 when they may not even use it, 403 when they may use but not change it.
	 */
	private function administered(string $uuid, ViewerReach $reach): RecordTemplate {
		$template = $this->get(uuid: $uuid, reach: $reach);
		if ($this->shares->mayAdminister(view: $template->jsonSerialize(), userId: $reach->userId, isAdmin: $reach->isAdmin) === false) {
			throw new RecordTemplateRefused(status: 403, message: 'Only the owner or an administrator may change this record template.');
		}

		return $template;
	}//end administered()

	/**
	 * The stored template.
	 *
	 * @param string $uuid The template uuid.
	 *
	 * @return RecordTemplate The template.
	 *
	 * @throws RecordTemplateRefused 404 when it is absent.
	 */
	private function find(string $uuid): RecordTemplate {
		try {
			return $this->mapper->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException $e) {
			throw new RecordTemplateRefused(status: 404, message: 'No such record template.');
		}
	}//end find()

	/**
	 * Refuse a caller who may not create in the schema.
	 *
	 * @param string      $schema The schema id.
	 * @param ViewerReach $reach  The caller.
	 *
	 * @return void
	 *
	 * @throws RecordTemplateRefused 403 when they may not create there.
	 */
	private function assertMayCreate(string $schema, ViewerReach $reach): void {
		if ($this->mayCreateIn(schema: $schema, reach: $reach) === false) {
			throw new RecordTemplateRefused(status: 403, message: 'You may not create records in this schema, so you may not keep a template for it.');
		}
	}//end assertMayCreate()

	/**
	 * Whether the caller may create records in the schema.
	 *
	 * A schema that cannot be read is no right to create: the template is then
	 * left out, never offered on a guess.
	 *
	 * @param string      $schema The schema id.
	 * @param ViewerReach $reach  The caller.
	 *
	 * @return bool True when they may.
	 */
	private function mayCreateIn(string $schema, ViewerReach $reach): bool {
		if ($schema === '') {
			return false;
		}

		$key = $reach->userId . '|' . $schema;
		if (array_key_exists($key, $this->mayCreate) === false) {
			$verdict = false;
			try {
				$verdict = $this->permissions->hasPermission(
					schema: $this->schemas->find(id: $schema, _rbac: false),
					action: 'create',
					userId: $reach->userId
				);
			} catch (DoesNotExistException $e) {
				$verdict = false;
			} catch (Throwable $e) {
				$this->logger->warning(
					'[RecordTemplateService] Could not read create rights; the template is not offered.',
					['schema' => $schema, 'exception' => $e->getMessage()]
				);
			}

			$this->mayCreate[$key] = $verdict;
		}

		return $this->mayCreate[$key];
	}//end mayCreateIn()

	/**
	 * What is wrong with a share list, judged against the groups that exist.
	 *
	 * @param mixed $sharedWith The declared shares.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function shareFindings(mixed $sharedWith): array {
		return $this->viewers->shareFindings(sharedWith: $sharedWith);
	}//end shareFindings()
}//end class
