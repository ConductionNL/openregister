<?php

/**
 * OpenRegister Object Resource Provider
 *
 * Lets an object join a Nextcloud collaboration collection (a "project"),
 * beside the folder and the conversation that belong to the same work.
 *
 * @category Collaboration
 * @package  OCA\OpenRegister\Collaboration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Collaboration;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatterInterface;
use OCP\Collaboration\Resources\IProvider;
use OCP\Collaboration\Resources\IResource;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Answers name, icon, link and access for an object in a collection.
 *
 * A resource id is `<registerId>/<schemaId>/<uuid>`. Only an object whose
 * schema declares `collaborationResources: true` in its configuration may be
 * a resource (D-3); everything else is refused, so reference data never
 * reaches a project. Access is the object's own read, asked through the read
 * path with RBAC and multitenancy on, never decided here (D-1). The read path
 * answers for the signed-in user only, so a question about anybody else is
 * refused: a collection never shows an object that the read path did not
 * clear for the person looking.
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
 */
class ObjectResourceProvider implements IProvider {

	/**
	 * The resource type objects are registered under.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister-object';

	/**
	 * The schema configuration key that lets a schema's objects join collections.
	 *
	 * @var string
	 */
	public const CONFIGURATION_KEY = 'collaborationResources';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface       $objects     The object read path.
	 * @param SchemaMapperInterface           $schemas     The schemas, for the opt-in.
	 * @param ObjectPreviewFormatterInterface $formatter   The object's card: name, icon, link.
	 * @param IURLGenerator                   $urls        Builds the object's reference address.
	 * @param IUserSession                    $userSession The signed-in user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaMapperInterface $schemas,
		private readonly ObjectPreviewFormatterInterface $formatter,
		private readonly IURLGenerator $urls,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The resource type of objects.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
	 */
	public function getType(): string {
		return self::TYPE;
	}//end getType()

	/**
	 * The object's name, icon and link, as its reference card shows them.
	 *
	 * The card is built through the read path, so an object the signed-in user
	 * may not read, or one whose schema has not opted in, answers no data.
	 *
	 * @param IResource $resource The resource.
	 *
	 * @return array<string, string> The rich object, or [] when it cannot be shown.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
	 */
	public function getResourceRichObject(IResource $resource): array {
		$ref = self::parse(resourceId: $resource->getId());
		if ($ref === null || $this->mayJoin(schemaId: $ref['schema']) === false) {
			return [];
		}

		$reference = $this->formatter->buildReference(
			referenceText: $this->urls->getAbsoluteURL(
				'/apps/openregister/api/objects/' . $ref['register'] . '/' . $ref['schema'] . '/' . $ref['uuid']
			)
		);
		$card = $reference?->getRichObject();
		if (is_array($card) === false || $card === []) {
			return [];
		}

		return [
			'type' => self::TYPE,
			'id' => $resource->getId(),
			'name' => (string)($card['title'] ?? ''),
			'link' => (string)($card['url'] ?? ''),
			'iconUrl' => (string)($card['icon_url'] ?? ''),
		];
	}//end getResourceRichObject()

	/**
	 * Whether a user may see the object in a collection: the read path's answer.
	 *
	 * @param IResource  $resource The resource.
	 * @param IUser|null $user     The user asking, null for a guest.
	 *
	 * @return bool True only when the schema opted in and the read path returns the object to this user.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
	 */
	public function canAccessResource(IResource $resource, ?IUser $user): bool {
		$ref = self::parse(resourceId: $resource->getId());
		if ($ref === null || $user === null) {
			return false;
		}

		$current = $this->userSession->getUser();
		if ($current === null || $current->getUID() !== $user->getUID()) {
			return false;
		}

		if ($this->mayJoin(schemaId: $ref['schema']) === false) {
			return false;
		}

		try {
			$object = $this->objects->find(
				id: $ref['uuid'],
				options: new ObjectFindOptions(register: $ref['register'], schema: $ref['schema'], render: false, audit: false),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			return false;
		}

		return $object !== null;
	}//end canAccessResource()

	/**
	 * Whether a schema's objects may join collections; a schema that cannot be read may not.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return bool True when the schema declares `collaborationResources: true`.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
	 */
	public function mayJoin(int $schemaId): bool {
		try {
			$configuration = $this->schemas->find($schemaId, _rbac: false, _multitenancy: false)->getConfiguration();
		} catch (Throwable $e) {
			return false;
		}

		return (($configuration ?? [])[self::CONFIGURATION_KEY] ?? false) === true;
	}//end mayJoin()

	/**
	 * The register id, schema id and uuid a resource id names.
	 *
	 * @param string $resourceId `<registerId>/<schemaId>/<uuid>`.
	 *
	 * @return array{register: int, schema: int, uuid: string}|null Null for any other shape.
	 *
	 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md#requirement-any-object-may-be-a-collaboration-resource-req-pcr-001
	 */
	public static function parse(string $resourceId): ?array {
		if (preg_match('#^(\d+)/(\d+)/([A-Za-z0-9-]+)$#', $resourceId, $match) !== 1) {
			return null;
		}

		return ['register' => (int)$match[1], 'schema' => (int)$match[2], 'uuid' => $match[3]];
	}//end parse()
}//end class
