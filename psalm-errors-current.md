
[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Controller/SettingsController.php#L3209\lib/Controller/[1;31mSettingsController.php:3209:13[0m]8;;\ - Docblock-defined class, interface or enum named Doctrine\DBAL\Platforms\AbstractPlatform does not exist (see https://psalm.dev/200)
            /** @var AbstractPlatform $platform */
            [97;41m$platform[0m = $this->db->getDatabasePlatform();


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Controller/SettingsController.php#L3209\lib/Controller/[1;31mSettingsController.php:3209:25[0m]8;;\ - Docblock-defined class, interface or enum named Doctrine\DBAL\Platforms\AbstractPlatform does not exist (see https://psalm.dev/200)
            $platform = [97;41m$this->db->getDatabasePlatform()[0m;


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Controller/SettingsController.php#L3211\lib/Controller/[1;31mSettingsController.php:3211:29[0m]8;;\ - Docblock-defined class, interface or enum named Doctrine\DBAL\Platforms\AbstractPlatform does not exist (see https://psalm.dev/200)
            $platformName = [97;41m$platform[0m->getName();


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/AgentMapper.php#L56\lib/Db/[1;31mAgentMapper.php:56:12[0m]8;;\ - The inherited return type 'list<OCA\OpenRegister\Db\Agent>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\Agent>' (see https://psalm.dev/166)
/**
 * Class AgentMapper
 *
 * Mapper for Agent entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Agent>
 *
 * @psalm-suppress MissingTemplateParam
 *
 * @method Agent insert(Entity $entity)
 * @method Agent update(Entity $entity)
 * @method Agent insertOrUpdate(Entity $entity)
 * @method Agent delete(Entity $entity)
 * @method Agent find(int|string $id)
 * @method Agent findEntity(IQueryBuilder $query)
 * @method [97;41mAgent[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method Agent[] findEntities(IQueryBuilder $query)
 */
class AgentMapper extends QBMapper


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/AgentMapper.php#L483\lib/Db/[1;31mAgentMapper.php:483:13[0m]8;;\ - Type OCA\OpenRegister\Db\Agent for $entity is always OCA\OpenRegister\Db\Agent (see https://psalm.dev/122)
        if ([97;41m$entity instanceof Agent[0m) {


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/AuditTrailMapper.php#L45\lib/Db/[1;31mAuditTrailMapper.php:45:12[0m]8;;\ - The inherited return type 'list<OCP\AppFramework\Db\Entity>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\AuditTrail>' (see https://psalm.dev/166)
/**
 * The AuditTrailMapper class handles audit trail operations and object reversions
 *
 * @package OCA\OpenRegister\Db
 *
 * @method AuditTrail insert(Entity $entity)
 * @method AuditTrail update(Entity $entity)
 * @method AuditTrail insertOrUpdate(Entity $entity)
 * @method AuditTrail delete(Entity $entity)
 * @method AuditTrail find(int|string $id)
 * @method AuditTrail findEntity(IQueryBuilder $query)
 * @method [97;41mAuditTrail[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method AuditTrail[] findEntities(IQueryBuilder $query)
 */
class AuditTrailMapper extends QBMapper


[0;31mERROR[0m: MissingTemplateParam - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/AuditTrailMapper.php#L47\lib/Db/[1;31mAuditTrailMapper.php:47:7[0m]8;;\ - OCA\OpenRegister\Db\AuditTrailMapper has missing template params when extending OCP\AppFramework\Db\QBMapper, expecting 1 (see https://psalm.dev/182)
class [97;41mAuditTrailMapper[0m extends QBMapper


[0;31mERROR[0m: ImplicitToStringCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/AuditTrailMapper.php#L272\lib/Db/[1;31mAuditTrailMapper.php:272:34[0m]8;;\ - Argument 1 of setUuid expects null|string, but Symfony\Component\Uid\UuidV4 provided with a __toString method (see https://psalm.dev/060)
            $auditTrail->setUuid([97;41mUuid::v4()[0m);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ConfigurationMapper.php#L435\lib/Db/[1;31mConfigurationMapper.php:435:13[0m]8;;\ - Type OCA\OpenRegister\Db\Configuration for $entity is always OCA\OpenRegister\Db\Configuration (see https://psalm.dev/122)
        if ([97;41m$entity instanceof Configuration[0m) {


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FeedbackMapper.php#L44\lib/Db/[1;31mFeedbackMapper.php:44:12[0m]8;;\ - The inherited return type 'list<OCA\OpenRegister\Db\Feedback>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\Feedback>' (see https://psalm.dev/166)
/**
 * Class FeedbackMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Feedback>
 *
 * @method Feedback insert(Entity $entity)
 * @method Feedback update(Entity $entity)
 * @method Feedback insertOrUpdate(Entity $entity)
 * @method Feedback delete(Entity $entity)
 * @method Feedback find(int|string $id)
 * @method Feedback findEntity(IQueryBuilder $query)
 * @method [97;41mFeedback[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method Feedback[] findEntities(IQueryBuilder $query)
 */
class FeedbackMapper extends QBMapper


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FeedbackMapper.php#L67\lib/Db/[1;31mFeedbackMapper.php:67:16[0m]8;;\ - The inherited return type 'OCA\OpenRegister\Db\Feedback' for OCP\AppFramework\Db\QBMapper::insert is more specific than the implemented return type for OCA\OpenRegister\Db\FeedbackMapper::insert 'OCP\AppFramework\Db\Entity' (see https://psalm.dev/166)
     * @return [97;41mEntity[0m Inserted entity


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FeedbackMapper.php#L94\lib/Db/[1;31mFeedbackMapper.php:94:16[0m]8;;\ - The inherited return type 'OCA\OpenRegister\Db\Feedback' for OCP\AppFramework\Db\QBMapper::update is more specific than the implemented return type for OCA\OpenRegister\Db\FeedbackMapper::update 'OCP\AppFramework\Db\Entity' (see https://psalm.dev/166)
     * @return [97;41mEntity[0m Updated entity


[0;31mERROR[0m: ImplementedReturnTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L65\lib/Db/[1;31mFileMapper.php:65:10[0m]8;;\ - The inherited return type 'OCP\AppFramework\Db\Entity' for OCP\AppFramework\Db\QBMapper::insert is different to the implemented return type for OCP\AppFramework\Db\QBMapper::insert 'array{accessUrl: null|string, checksum: string, downloadUrl: null|string, encrypted: int, etag: string, fileid: int, mimepart: string, mimetype: string, mtime: int, name: string, owner: null|string, parent: int, path: string, path_hash: string, permissions: int, published: null|string, share_stime: int|null, share_token: null|string, size: int, storage: int, storage_id: null|string, storage_mtime: int, unencrypted_size: int}' (see https://psalm.dev/123)
/**
 * Class [97;41mFile[0mMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db


[0;31mERROR[0m: ImplementedParamTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L65\lib/Db/[1;31mFileMapper.php:65:12[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\FileMapper::insert has wrong type 'OCA\OpenRegister\Db\Entity', expecting 'OCP\AppFramework\Db\Entity' as defined by OCP\AppFramework\Db\QBMapper::insert (see https://psalm.dev/199)
/**
 * Class FileMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @phpstan-type File array{
 *   fileid: int,
 *   storage: int,
 *   path: string,
 *   path_hash: string,
 *   parent: int,
 *   name: string,
 *   mimetype: string,
 *   mimepart: string,
 *   size: int,
 *   mtime: int,
 *   storage_mtime: int,
 *   encrypted: int,
 *   unencrypted_size: int,
 *   etag: string,
 *   permissions: int,
 *   checksum: string,
 *   share_token: string|null,
 *   share_stime: int|null,
 *   storage_id: string|null,
 *   owner: string|null,
 *   accessUrl: string|null,
 *   downloadUrl: string|null,
 *   published: string|null
 * }
 *
 * @method [97;41mFile insert(Entity $entity)[0m
 * @method File update(Entity $entity)
 * @method File insertOrUpdate(Entity $entity)
 * @method File delete(Entity $entity)
 * @method File find(int|string $id)


[0;31mERROR[0m: ImplementedReturnTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L66\lib/Db/[1;31mFileMapper.php:66:10[0m]8;;\ - The inherited return type 'OCP\AppFramework\Db\Entity' for OCP\AppFramework\Db\QBMapper::update is different to the implemented return type for OCP\AppFramework\Db\QBMapper::update 'array{accessUrl: null|string, checksum: string, downloadUrl: null|string, encrypted: int, etag: string, fileid: int, mimepart: string, mimetype: string, mtime: int, name: string, owner: null|string, parent: int, path: string, path_hash: string, permissions: int, published: null|string, share_stime: int|null, share_token: null|string, size: int, storage: int, storage_id: null|string, storage_mtime: int, unencrypted_size: int}' (see https://psalm.dev/123)
/**
 * Class [97;41mFile[0mMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db


[0;31mERROR[0m: ImplementedParamTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L66\lib/Db/[1;31mFileMapper.php:66:12[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\FileMapper::update has wrong type 'OCA\OpenRegister\Db\Entity', expecting 'OCP\AppFramework\Db\Entity' as defined by OCP\AppFramework\Db\QBMapper::update (see https://psalm.dev/199)
/**
 * Class FileMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @phpstan-type File array{
 *   fileid: int,
 *   storage: int,
 *   path: string,
 *   path_hash: string,
 *   parent: int,
 *   name: string,
 *   mimetype: string,
 *   mimepart: string,
 *   size: int,
 *   mtime: int,
 *   storage_mtime: int,
 *   encrypted: int,
 *   unencrypted_size: int,
 *   etag: string,
 *   permissions: int,
 *   checksum: string,
 *   share_token: string|null,
 *   share_stime: int|null,
 *   storage_id: string|null,
 *   owner: string|null,
 *   accessUrl: string|null,
 *   downloadUrl: string|null,
 *   published: string|null
 * }
 *
 * @method [97;41mFile insert(Entity $entity)[0m
 * @method File update(Entity $entity)
 * @method File insertOrUpdate(Entity $entity)
 * @method File delete(Entity $entity)
 * @method File find(int|string $id)


[0;31mERROR[0m: ImplementedReturnTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L67\lib/Db/[1;31mFileMapper.php:67:10[0m]8;;\ - The inherited return type 'OCP\AppFramework\Db\Entity' for OCP\AppFramework\Db\QBMapper::insertOrUpdate is different to the implemented return type for OCP\AppFramework\Db\QBMapper::insertorupdate 'array{accessUrl: null|string, checksum: string, downloadUrl: null|string, encrypted: int, etag: string, fileid: int, mimepart: string, mimetype: string, mtime: int, name: string, owner: null|string, parent: int, path: string, path_hash: string, permissions: int, published: null|string, share_stime: int|null, share_token: null|string, size: int, storage: int, storage_id: null|string, storage_mtime: int, unencrypted_size: int}' (see https://psalm.dev/123)
/**
 * Class [97;41mFile[0mMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db


[0;31mERROR[0m: ImplementedParamTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L67\lib/Db/[1;31mFileMapper.php:67:12[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\FileMapper::insertOrUpdate has wrong type 'OCA\OpenRegister\Db\Entity', expecting 'OCP\AppFramework\Db\Entity' as defined by OCP\AppFramework\Db\QBMapper::insertOrUpdate (see https://psalm.dev/199)
/**
 * Class FileMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @phpstan-type File array{
 *   fileid: int,
 *   storage: int,
 *   path: string,
 *   path_hash: string,
 *   parent: int,
 *   name: string,
 *   mimetype: string,
 *   mimepart: string,
 *   size: int,
 *   mtime: int,
 *   storage_mtime: int,
 *   encrypted: int,
 *   unencrypted_size: int,
 *   etag: string,
 *   permissions: int,
 *   checksum: string,
 *   share_token: string|null,
 *   share_stime: int|null,
 *   storage_id: string|null,
 *   owner: string|null,
 *   accessUrl: string|null,
 *   downloadUrl: string|null,
 *   published: string|null
 * }
 *
 * @method [97;41mFile insert(Entity $entity)[0m
 * @method File update(Entity $entity)
 * @method File insertOrUpdate(Entity $entity)
 * @method File delete(Entity $entity)
 * @method File find(int|string $id)


[0;31mERROR[0m: ImplementedReturnTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L68\lib/Db/[1;31mFileMapper.php:68:10[0m]8;;\ - The inherited return type 'OCP\AppFramework\Db\Entity' for OCP\AppFramework\Db\QBMapper::delete is different to the implemented return type for OCP\AppFramework\Db\QBMapper::delete 'array{accessUrl: null|string, checksum: string, downloadUrl: null|string, encrypted: int, etag: string, fileid: int, mimepart: string, mimetype: string, mtime: int, name: string, owner: null|string, parent: int, path: string, path_hash: string, permissions: int, published: null|string, share_stime: int|null, share_token: null|string, size: int, storage: int, storage_id: null|string, storage_mtime: int, unencrypted_size: int}' (see https://psalm.dev/123)
/**
 * Class [97;41mFile[0mMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db


[0;31mERROR[0m: ImplementedParamTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L68\lib/Db/[1;31mFileMapper.php:68:12[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\FileMapper::delete has wrong type 'OCA\OpenRegister\Db\Entity', expecting 'OCP\AppFramework\Db\Entity' as defined by OCP\AppFramework\Db\QBMapper::delete (see https://psalm.dev/199)
/**
 * Class FileMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @phpstan-type File array{
 *   fileid: int,
 *   storage: int,
 *   path: string,
 *   path_hash: string,
 *   parent: int,
 *   name: string,
 *   mimetype: string,
 *   mimepart: string,
 *   size: int,
 *   mtime: int,
 *   storage_mtime: int,
 *   encrypted: int,
 *   unencrypted_size: int,
 *   etag: string,
 *   permissions: int,
 *   checksum: string,
 *   share_token: string|null,
 *   share_stime: int|null,
 *   storage_id: string|null,
 *   owner: string|null,
 *   accessUrl: string|null,
 *   downloadUrl: string|null,
 *   published: string|null
 * }
 *
 * @method [97;41mFile insert(Entity $entity)[0m
 * @method File update(Entity $entity)
 * @method File insertOrUpdate(Entity $entity)
 * @method File delete(Entity $entity)
 * @method File find(int|string $id)


[0;31mERROR[0m: ImplementedReturnTypeMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L70\lib/Db/[1;31mFileMapper.php:70:10[0m]8;;\ - The inherited return type 'OCP\AppFramework\Db\Entity' for OCP\AppFramework\Db\QBMapper::findEntity is different to the implemented return type for OCP\AppFramework\Db\QBMapper::findentity 'array{accessUrl: null|string, checksum: string, downloadUrl: null|string, encrypted: int, etag: string, fileid: int, mimepart: string, mimetype: string, mtime: int, name: string, owner: null|string, parent: int, path: string, path_hash: string, permissions: int, published: null|string, share_stime: int|null, share_token: null|string, size: int, storage: int, storage_id: null|string, storage_mtime: int, unencrypted_size: int}' (see https://psalm.dev/123)
/**
 * Class [97;41mFile[0mMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L72\lib/Db/[1;31mFileMapper.php:72:12[0m]8;;\ - The inherited return type 'list<OCP\AppFramework\Db\Entity>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, array{accessUrl: null|string, checksum: string, downloadUrl: null|string, encrypted: int, etag: string, fileid: int, mimepart: string, mimetype: string, mtime: int, name: string, owner: null|string, parent: int, path: string, path_hash: string, permissions: int, published: null|string, share_stime: int|null, share_token: null|string, size: int, storage: int, storage_id: null|string, storage_mtime: int, unencrypted_size: int}>' (see https://psalm.dev/166)
/**
 * Class FileMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @phpstan-type File array{
 *   fileid: int,
 *   storage: int,
 *   path: string,
 *   path_hash: string,
 *   parent: int,
 *   name: string,
 *   mimetype: string,
 *   mimepart: string,
 *   size: int,
 *   mtime: int,
 *   storage_mtime: int,
 *   encrypted: int,
 *   unencrypted_size: int,
 *   etag: string,
 *   permissions: int,
 *   checksum: string,
 *   share_token: string|null,
 *   share_stime: int|null,
 *   storage_id: string|null,
 *   owner: string|null,
 *   accessUrl: string|null,
 *   downloadUrl: string|null,
 *   published: string|null
 * }
 *
 * @method File insert(Entity $entity)
 * @method File update(Entity $entity)
 * @method File insertOrUpdate(Entity $entity)
 * @method File delete(Entity $entity)
 * @method File find(int|string $id)
 * @method File findEntity(IQueryBuilder $query)
 * @method [97;41mFile[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method File[] findEntities(IQueryBuilder $query)
 */
class FileMapper extends QBMapper


[0;31mERROR[0m: MissingTemplateParam - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L74\lib/Db/[1;31mFileMapper.php:74:7[0m]8;;\ - OCA\OpenRegister\Db\FileMapper has missing template params when extending OCP\AppFramework\Db\QBMapper, expecting 1 (see https://psalm.dev/182)
class [97;41mFileMapper[0m extends QBMapper


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L516\lib/Db/[1;31mFileMapper.php:516:30[0m]8;;\ - Redundant cast to int (see https://psalm.dev/262)
            'id'          => [97;41m(int) $shareId[0m,


[0;31mERROR[0m: TooManyArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/FileMapper.php#L661\lib/Db/[1;31mFileMapper.php:661:34[0m]8;;\ - Too many arguments for method OCP\DB\QueryBuilder\IFunctionBuilder::sum - saw 2 (see https://psalm.dev/026)
        $qb->select($qb->func()->[97;41msum[0m('fc.size', 'total_size'))


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L60\lib/Db/[1;31mMultiTenancyTrait.php:60:14[0m]8;;\ - Property $this->organisationService with type OCA\OpenRegister\Service\OrganisationService should already be set in the constructor (see https://psalm.dev/261)
        if (![97;41misset($this->organisationService)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L84\lib/Db/[1;31mMultiTenancyTrait.php:84:14[0m]8;;\ - Property $this->organisationService with type OCA\OpenRegister\Service\OrganisationService should already be set in the constructor (see https://psalm.dev/261)
        if (![97;41misset($this->organisationService)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L103\lib/Db/[1;31mMultiTenancyTrait.php:103:14[0m]8;;\ - Property $this->userSession with type OCP\IUserSession should already be set in the constructor (see https://psalm.dev/261)
        if (![97;41misset($this->userSession)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L128\lib/Db/[1;31mMultiTenancyTrait.php:128:14[0m]8;;\ - Property $this->groupManager with type OCP\IGroupManager should already be set in the constructor (see https://psalm.dev/261)
        if (![97;41misset($this->groupManager)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L183\lib/Db/[1;31mMultiTenancyTrait.php:183:13[0m]8;;\ - Property $this->appConfig with type OCP\IAppConfig should already be set in the constructor (see https://psalm.dev/261)
        if ([97;41misset($this->appConfig)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L191\lib/Db/[1;31mMultiTenancyTrait.php:191:25[0m]8;;\ - Property $this->logger with type Psr\Log\LoggerInterface should already be set in the constructor (see https://psalm.dev/261)
                    if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L201\lib/Db/[1;31mMultiTenancyTrait.php:201:19[0m]8;;\ - Instance property OCA\OpenRegister\Db\RegisterMapper::$userSession is not defined (see https://psalm.dev/041)
        $user   = [97;41m$this->userSession[0m->getUser();


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L206\lib/Db/[1;31mMultiTenancyTrait.php:206:17[0m]8;;\ - Property $this->logger with type Psr\Log\LoggerInterface should already be set in the constructor (see https://psalm.dev/261)
            if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L221\lib/Db/[1;31mMultiTenancyTrait.php:221:13[0m]8;;\ - Type OCP\IAppConfig for $this->appConfig is always isset (see https://psalm.dev/122)
        if ([97;41m$enablePublished && isset($this->appConfig)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L231\lib/Db/[1;31mMultiTenancyTrait.php:231:17[0m]8;;\ - Property $this->logger with type Psr\Log\LoggerInterface should already be set in the constructor (see https://psalm.dev/261)
            if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L244\lib/Db/[1;31mMultiTenancyTrait.php:244:27[0m]8;;\ - Instance property OCA\OpenRegister\Db\RegisterMapper::$groupManager is not defined (see https://psalm.dev/041)
            $userGroups = [97;41m$this->groupManager[0m->getUserGroupIds($user);


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L287\lib/Db/[1;31mMultiTenancyTrait.php:287:13[0m]8;;\ - Property $this->organisationService with type OCA\OpenRegister\Service\OrganisationService should already be set in the constructor (see https://psalm.dev/261)
        if ([97;41misset($this->organisationService)[0m) {


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L296\lib/Db/[1;31mMultiTenancyTrait.php:296:23[0m]8;;\ - Instance property OCA\OpenRegister\Db\RegisterMapper::$groupManager is not defined (see https://psalm.dev/041)
        $userGroups = [97;41m$this->groupManager[0m->getUserGroupIds($user);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L300\lib/Db/[1;31mMultiTenancyTrait.php:300:13[0m]8;;\ - Type OCP\IAppConfig for $this->appConfig is always isset (see https://psalm.dev/122)
        if ([97;41m$isAdmin && isset($this->appConfig)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L311\lib/Db/[1;31mMultiTenancyTrait.php:311:17[0m]8;;\ - Property $this->logger with type Psr\Log\LoggerInterface should already be set in the constructor (see https://psalm.dev/261)
            if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L320\lib/Db/[1;31mMultiTenancyTrait.php:320:17[0m]8;;\ - Property $this->logger with type Psr\Log\LoggerInterface should already be set in the constructor (see https://psalm.dev/261)
            if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L335\lib/Db/[1;31mMultiTenancyTrait.php:335:13[0m]8;;\ - Property $this->logger with type Psr\Log\LoggerInterface should already be set in the constructor (see https://psalm.dev/261)
        if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L363\lib/Db/[1;31mMultiTenancyTrait.php:363:17[0m]8;;\ - Type Psr\Log\LoggerInterface for $this->logger is always isset (see https://psalm.dev/122)
            if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: ParadoxicalCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L377\lib/Db/[1;31mMultiTenancyTrait.php:377:13[0m]8;;\ - Condition (($allowNullOrg) && ($isAdmin) && ($isSystemDefaultOrg)) contradicts a previously-established condition ((!$isAdmin) || (!$isSystemDefaultOrg)) (see https://psalm.dev/089)
        if ([97;41m$allowNullOrg && $isSystemDefaultOrg && $isAdmin[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L377\lib/Db/[1;31mMultiTenancyTrait.php:377:53[0m]8;;\ - Operand of type false is always falsy (see https://psalm.dev/056)
        if ($allowNullOrg && $isSystemDefaultOrg && [97;41m$isAdmin[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L380\lib/Db/[1;31mMultiTenancyTrait.php:380:17[0m]8;;\ - Type Psr\Log\LoggerInterface for $this->logger is always isset (see https://psalm.dev/122)
            if ([97;41misset($this->logger)[0m) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L496\lib/Db/[1;31mMultiTenancyTrait.php:496:14[0m]8;;\ - Property $this->organisationService with type OCA\OpenRegister\Service\OrganisationService should already be set in the constructor (see https://psalm.dev/261)
        if (![97;41misset($this->organisationService)[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L512\lib/Db/[1;31mMultiTenancyTrait.php:512:13[0m]8;;\ - Type array<array-key, mixed> for $orgUsers is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41mis_array($orgUsers)[0m && in_array($userId, $orgUsers)) {


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L523\lib/Db/[1;31mMultiTenancyTrait.php:523:14[0m]8;;\ - Property $this->groupManager with type OCP\IGroupManager should already be set in the constructor (see https://psalm.dev/261)
        if (![97;41misset($this->groupManager)[0m) {


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/MultiTenancyTrait.php#L528\lib/Db/[1;31mMultiTenancyTrait.php:528:17[0m]8;;\ - Instance property OCA\OpenRegister\Db\RegisterMapper::$userSession is not defined (see https://psalm.dev/041)
        $user = [97;41m$this->userSession[0m->getUser();


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L69\lib/Db/[1;31mObjectEntityMapper.php:69:12[0m]8;;\ - The inherited return type 'list<OCP\AppFramework\Db\Entity>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\ObjectEntity>' (see https://psalm.dev/166)
/**
 * The ObjectEntityMapper class
 *
 * @package OCA\OpenRegister\Db
 *
 * @method ObjectEntity insert(Entity $entity)
 * @method ObjectEntity update(Entity $entity)
 * @method ObjectEntity insertOrUpdate(Entity $entity)
 * @method ObjectEntity delete(Entity $entity)
 * @method ObjectEntity find(int|string $id)
 * @method ObjectEntity findEntity(IQueryBuilder $query)
 * @method [97;41mObjectEntity[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method ObjectEntity[] findEntities(IQueryBuilder $query)
 */
class ObjectEntityMapper extends QBMapper


[0;31mERROR[0m: MissingTemplateParam - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L71\lib/Db/[1;31mObjectEntityMapper.php:71:7[0m]8;;\ - OCA\OpenRegister\Db\ObjectEntityMapper has missing template params when extending OCP\AppFramework\Db\QBMapper, expecting 1 (see https://psalm.dev/182)
class [97;41mObjectEntityMapper[0m extends QBMapper


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L212\lib/Db/[1;31mObjectEntityMapper.php:212:9[0m]8;;\ - Docblock-defined class, interface or enum named Doctrine\DBAL\Platforms\AbstractPlatform does not exist (see https://psalm.dev/200)
        /** @var \Doctrine\DBAL\Platforms\AbstractPlatform $platform */
        [97;41m$platform[0m = $db->getDatabasePlatform();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L213\lib/Db/[1;31mObjectEntityMapper.php:213:34[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Platforms\MySQLPlatform does not exist (see https://psalm.dev/019)
        if ($platform instanceof [97;41mMySQLPlatform[0m === true) {


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L779\lib/Db/[1;31mObjectEntityMapper.php:779:57[0m]8;;\ - Cannot find referenced variable $organizationColumn (see https://psalm.dev/024)
                $orgConditions->add($qb->expr()->isNull([97;41m$organizationColumn[0m));


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L845\lib/Db/[1;31mObjectEntityMapper.php:845:13[0m]8;;\ - string can never contain null (see https://psalm.dev/122)
        if ([97;41m$objectTableAlias !== null[0m && $objectTableAlias !== '') {


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L938\lib/Db/[1;31mObjectEntityMapper.php:938:57[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Platforms\MySQLPlatform does not exist (see https://psalm.dev/019)
        if ($this->db->getDatabasePlatform() instanceof [97;41mMySQLPlatform[0m) {


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L960\lib/Db/[1;31mObjectEntityMapper.php:960:57[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Platforms\MySQLPlatform does not exist (see https://psalm.dev/019)
        if ($this->db->getDatabasePlatform() instanceof [97;41mMySQLPlatform[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L1611\lib/Db/[1;31mObjectEntityMapper.php:1611:64[0m]8;;\ - Operand of type true is always truthy (see https://psalm.dev/122)
            $needsSchemaJoin = $rbac && !$performanceBypass && [97;41m!$smartBypass[0m;


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L1638\lib/Db/[1;31mObjectEntityMapper.php:1638:64[0m]8;;\ - Operand of type true is always truthy (see https://psalm.dev/122)
            $needsSchemaJoin = $rbac && !$performanceBypass && [97;41m!$smartBypass[0m;


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L2066\lib/Db/[1;31mObjectEntityMapper.php:2066:13[0m]8;;\ - Cannot find referenced variable $ids (see https://psalm.dev/024)
        if ([97;41m$ids[0m !== null && empty($ids) === false) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L2139\lib/Db/[1;31mObjectEntityMapper.php:2139:13[0m]8;;\ - string can never contain null (see https://psalm.dev/122)
        if ([97;41m$tableAlias !== null[0m && $tableAlias !== '') {


[0;31mERROR[0m: ParamNameMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L2577\lib/Db/[1;31mObjectEntityMapper.php:2577:35[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\ObjectEntityMapper::delete has wrong name $object, expecting $entity as defined by OCP\AppFramework\Db\QBMapper::delete (see https://psalm.dev/230)
    public function delete(Entity [97;41m$object[0m): ObjectEntity


[0;31mERROR[0m: MoreSpecificImplementedParamType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L2577\lib/Db/[1;31mObjectEntityMapper.php:2577:35[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\ObjectEntityMapper::delete has the more specific type 'OCA\OpenRegister\Db\ObjectEntity', expecting 'OCP\AppFramework\Db\Entity' as defined by OCP\AppFramework\Db\QBMapper::delete (see https://psalm.dev/140)
    public function delete(Entity [97;41m$object[0m): ObjectEntity


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L2862\lib/Db/[1;31mObjectEntityMapper.php:2862:86[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\ParameterType does not exist (see https://psalm.dev/019)
            ->where($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId, [97;41m\Doctrine\DBAL\ParameterType[0m::INTEGER)))


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L3118\lib/Db/[1;31mObjectEntityMapper.php:3118:21[0m]8;;\ - int can never contain null (see https://psalm.dev/122)
                if ([97;41m$range['min'] !== null[0m) {


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L3604\lib/Db/[1;31mObjectEntityMapper.php:3604:26[0m]8;;\ - Cannot call method on array<string, mixed> variable $obj (see https://psalm.dev/091)
            return $obj->[97;41mjsonSerialize[0m();


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L3628\lib/Db/[1;31mObjectEntityMapper.php:3628:48[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\ObjectEntityMapper::update expects OCP\AppFramework\Db\Entity, but array<string, mixed> provided (see https://psalm.dev/004)
                $updatedObject = $this->update([97;41m$largeUpdateObject[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L3665\lib/Db/[1;31mObjectEntityMapper.php:3665:59[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\ObjectEntityMapper::processUpdateChunk expects array<int, OCA\OpenRegister\Db\ObjectEntity>, but non-empty-list<array<string, mixed>> provided (see https://psalm.dev/004)
                    $chunkIds = $this->processUpdateChunk([97;41m$updateChunk[0m);


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L3775\lib/Db/[1;31mObjectEntityMapper.php:3775:13[0m]8;;\ - 0 cannot be identical to int<1, max> (see https://psalm.dev/056)
        if ([97;41m$objectCount === 0[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L3897\lib/Db/[1;31mObjectEntityMapper.php:3897:13[0m]8;;\ - Type int<1, max> for $objectCount is always !>0 (see https://psalm.dev/056)
        if ([97;41m$objectCount <= 0[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L4445\lib/Db/[1;31mObjectEntityMapper.php:4445:25[0m]8;;\ - 'object' cannot be identical to key-of<array<array-key, mixed>> (see https://psalm.dev/056)
                    if ([97;41m$column === 'object'[0m && is_array($value)) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L4445\lib/Db/[1;31mObjectEntityMapper.php:4445:25[0m]8;;\ - Type key-of<array<array-key, mixed>> for $column is never =string(object) (see https://psalm.dev/056)
                    if ([97;41m$column === 'object'[0m && is_array($value)) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectEntityMapper.php#L5321\lib/Db/[1;31mObjectEntityMapper.php:5321:21[0m]8;;\ - Operand of type OCP\DB\IResult is always truthy (see https://psalm.dev/122)
                if ([97;41m$result[0m && isset($objectData['uuid'])) {


[0;31mERROR[0m: UndefinedFunction - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectHandlers/HyperFacetHandler.php#L447\lib/Db/ObjectHandlers/[1;31mHyperFacetHandler.php:447:20[0m]8;;\ - Function React\Async\await does not exist (see https://psalm.dev/021)
        $results = [97;41m\React\Async\await(\React\Promise\all($promises))[0m;


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectHandlers/HyperFacetHandler.php#L609\lib/Db/ObjectHandlers/[1;31mHyperFacetHandler.php:609:26[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but array<string, array<array-key, mixed>|mixed> provided (see https://psalm.dev/004)
                $resolve([97;41m$results[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectHandlers/HyperFacetHandler.php#L1072\lib/Db/ObjectHandlers/[1;31mHyperFacetHandler.php:1072:22[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but array<never, never> provided (see https://psalm.dev/004)
            $resolve([97;41m[][0m); // Simplified for now


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectHandlers/MariaDbFacetHandler.php#L1231\lib/Db/ObjectHandlers/[1;31mMariaDbFacetHandler.php:1231:25[0m]8;;\ - Type array<array-key, mixed> for $value[0] is always array<array-key, mixed> (see https://psalm.dev/122)
                    if ([97;41mis_array($value[0])[0m) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectHandlers/MariaDbFacetHandler.php#L1531\lib/Db/ObjectHandlers/[1;31mMariaDbFacetHandler.php:1531:44[0m]8;;\ - All possible types for this assignment were invalidated - This may be dead code (see https://psalm.dev/179)
            foreach (array_keys($types) as [97;41m$type[0m) {


[0;31mERROR[0m: InvalidOperand - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectHandlers/OptimizedBulkOperations.php#L250\lib/Db/ObjectHandlers/[1;31mOptimizedBulkOperations.php:250:60[0m]8;;\ - Cannot perform a numeric operation with a non-numeric type OCP\DB\IResult (see https://psalm.dev/058)
            $estimatedCreated = max(0, $totalObjects * 2 - [97;41m$affectedRows[0m);


[0;31mERROR[0m: InvalidOperand - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/ObjectHandlers/OptimizedBulkOperations.php#L251\lib/Db/ObjectHandlers/[1;31mOptimizedBulkOperations.php:251:33[0m]8;;\ - Cannot perform a numeric operation with a non-numeric type OCP\DB\IResult (see https://psalm.dev/058)
            $estimatedUpdated = [97;41m$affectedRows[0m - $estimatedCreated;


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php#L348\lib/Db/[1;31mOrganisation.php:348:13[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$roles is not defined (see https://psalm.dev/041)
        if ([97;41m$this->roles[0m === null) {


[0;31mERROR[0m: UndefinedThisPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php#L350\lib/Db/[1;31mOrganisation.php:350:13[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$roles is not defined (see https://psalm.dev/040)
            [97;41m$this->roles[0m = [];


[0;31mERROR[0m: UndefinedThisPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php#L368\lib/Db/[1;31mOrganisation.php:368:17[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$roles is not defined (see https://psalm.dev/040)
                [97;41m$this->roles[0m[] = $role;


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php#L387\lib/Db/[1;31mOrganisation.php:387:13[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$roles is not defined (see https://psalm.dev/041)
        if ([97;41m$this->roles[0m === null) {


[0;31mERROR[0m: UndefinedThisPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php#L392\lib/Db/[1;31mOrganisation.php:392:9[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$roles is not defined (see https://psalm.dev/040)
        [97;41m$this->roles[0m = array_values(


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php#L417\lib/Db/[1;31mOrganisation.php:417:13[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$roles is not defined (see https://psalm.dev/041)
        if ([97;41m$this->roles[0m === null) {


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php#L443\lib/Db/[1;31mOrganisation.php:443:13[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$roles is not defined (see https://psalm.dev/041)
        if ([97;41m$this->roles[0m === null) {


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/OrganisationMapper.php#L55\lib/Db/[1;31mOrganisationMapper.php:55:12[0m]8;;\ - The inherited return type 'list<OCP\AppFramework\Db\Entity>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\Organisation>' (see https://psalm.dev/166)
/**
 * OrganisationMapper
 *
 * Database mapper for Organisation entities with multi-tenancy support.
 * Manages CRUD operations and user-organisation relationships.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method Organisation insert(Entity $entity)
 * @method Organisation update(Entity $entity)
 * @method Organisation insertOrUpdate(Entity $entity)
 * @method Organisation delete(Entity $entity)
 * @method Organisation find(int|string $id)
 * @method Organisation findEntity(IQueryBuilder $query)
 * @method [97;41mOrganisation[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method Organisation[] findEntities(IQueryBuilder $query)
 */
class OrganisationMapper extends QBMapper


[0;31mERROR[0m: MissingTemplateParam - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/OrganisationMapper.php#L57\lib/Db/[1;31mOrganisationMapper.php:57:7[0m]8;;\ - OCA\OpenRegister\Db\OrganisationMapper has missing template params when extending OCP\AppFramework\Db\QBMapper, expecting 1 (see https://psalm.dev/182)
class [97;41mOrganisationMapper[0m extends QBMapper


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/OrganisationMapper.php#L118\lib/Db/[1;31mOrganisationMapper.php:118:29[0m]8;;\ - Method OCA\OpenRegister\Db\OrganisationMapper::find does not exist (see https://psalm.dev/022)
        $oldEntity = $this->[97;41mfind[0m(id: $entity->getId());


[0;31mERROR[0m: UndefinedPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/OrganisationMapper.php#L250\lib/Db/[1;31mOrganisationMapper.php:250:13[0m]8;;\ - Instance property OCA\OpenRegister\Db\Organisation::$userCount is not defined (see https://psalm.dev/038)
            [97;41m$organisation->userCount[0m = count($organisation->getUserIds());


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Register.php#L480\lib/Db/[1;31mRegister.php:480:30[0m]8;;\ - Property $this->id with type int should already be set in the constructor (see https://psalm.dev/261)
        return 'Register #'.([97;41m$this->id[0m ?? 'unknown');


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Register.php#L480\lib/Db/[1;31mRegister.php:480:43[0m]8;;\ - Property $this->id with type int should already be set in the constructor (see https://psalm.dev/261)
        return 'Register #'.($this->id ?? [97;41m'unknown'[0m);


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L50\lib/Db/[1;31mRegisterMapper.php:50:12[0m]8;;\ - The inherited return type 'list<OCP\AppFramework\Db\Entity>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\Register>' (see https://psalm.dev/166)
/**
 * The RegisterMapper class
 *
 * Handles database operations for Register entities with multi-tenancy support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method Register insert(Entity $entity)
 * @method Register update(Entity $entity)
 * @method Register insertOrUpdate(Entity $entity)
 * @method Register delete(Entity $entity)
 * @method Register find(int|string $id)
 * @method Register findEntity(IQueryBuilder $query)
 * @method [97;41mRegister[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method Register[] findEntities(IQueryBuilder $query)
 */
class RegisterMapper extends QBMapper


[0;31mERROR[0m: MissingTemplateParam - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L52\lib/Db/[1;31mRegisterMapper.php:52:7[0m]8;;\ - OCA\OpenRegister\Db\RegisterMapper has missing template params when extending OCP\AppFramework\Db\QBMapper, expecting 1 (see https://psalm.dev/182)
class [97;41mRegisterMapper[0m extends QBMapper


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L82\lib/Db/[1;31mRegisterMapper.php:82:13[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Db\FileService does not exist (see https://psalm.dev/019)
    private [97;41mFileService[0m $fileService;


[0;31mERROR[0m: UndefinedThisPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L113\lib/Db/[1;31mRegisterMapper.php:113:9[0m]8;;\ - Instance property OCA\OpenRegister\Db\RegisterMapper::$organisationService is not defined (see https://psalm.dev/040)
        [97;41m$this->organisationService[0m = $organisationService;


[0;31mERROR[0m: UndefinedThisPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L114\lib/Db/[1;31mRegisterMapper.php:114:9[0m]8;;\ - Instance property OCA\OpenRegister\Db\RegisterMapper::$userSession is not defined (see https://psalm.dev/040)
        [97;41m$this->userSession[0m         = $userSession;


[0;31mERROR[0m: UndefinedThisPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L115\lib/Db/[1;31mRegisterMapper.php:115:9[0m]8;;\ - Instance property OCA\OpenRegister\Db\RegisterMapper::$groupManager is not defined (see https://psalm.dev/040)
        [97;41m$this->groupManager[0m        = $groupManager;


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L205\lib/Db/[1;31mRegisterMapper.php:205:30[0m]8;;\ - Too few arguments for OCP\DB\QueryBuilder\IExpressionBuilder::in - expecting y to be passed (see https://psalm.dev/025)
                $qb->expr()->[97;41min[0m('id', schema: $qb->createNamedParameter($ids, extend: IQueryBuilder::PARAM_INT_ARRAY))


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L205\lib/Db/[1;31mRegisterMapper.php:205:39[0m]8;;\ - Parameter $schema does not exist on function OCP\DB\QueryBuilder\IExpressionBuilder::in (see https://psalm.dev/238)
                $qb->expr()->in('id', [97;41mschema[0m: $qb->createNamedParameter($ids, extend: IQueryBuilder::PARAM_INT_ARRAY))


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L205\lib/Db/[1;31mRegisterMapper.php:205:79[0m]8;;\ - Parameter $extend does not exist on function OCP\DB\QueryBuilder\IQueryBuilder::createNamedParameter (see https://psalm.dev/238)
                $qb->expr()->in('id', schema: $qb->createNamedParameter($ids, [97;41mextend[0m: IQueryBuilder::PARAM_INT_ARRAY))


[0;31mERROR[0m: ImplicitToStringCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L317\lib/Db/[1;31mRegisterMapper.php:317:32[0m]8;;\ - Argument 1 of setUuid expects null|string, but Symfony\Component\Uid\UuidV4 provided with a __toString method (see https://psalm.dev/060)
            $register->setUuid([97;41mUuid::v4()[0m);


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L419\lib/Db/[1;31mRegisterMapper.php:419:27[0m]8;;\ - Too few arguments for explode - expecting string to be passed (see https://psalm.dev/025)
            $version    = [97;41mexplode('.', register: $register->getVersion())[0m;


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L419\lib/Db/[1;31mRegisterMapper.php:419:40[0m]8;;\ - Parameter $register does not exist on function explode (see https://psalm.dev/238)
            $version    = explode('.', [97;41mregister[0m: $register->getVersion());


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L421\lib/Db/[1;31mRegisterMapper.php:421:48[0m]8;;\ - Parameter $schema does not exist on function implode (see https://psalm.dev/238)
            $register->setVersion(implode('.', [97;41mschema[0m: $version));


[0;31mERROR[0m: MoreSpecificImplementedParamType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L445\lib/Db/[1;31mRegisterMapper.php:445:35[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\RegisterMapper::delete has the more specific type 'OCA\OpenRegister\Db\Register', expecting 'OCP\AppFramework\Db\Entity' as defined by OCP\AppFramework\Db\QBMapper::delete (see https://psalm.dev/140)
    public function delete(Entity [97;41m$entity[0m): Register


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L524\lib/Db/[1;31mRegisterMapper.php:524:15[0m]8;;\ - Too few arguments for OCP\DB\QueryBuilder\IQueryBuilder::setParameter - expecting value to be passed (see https://psalm.dev/025)
            ->[97;41msetParameter[0m('pattern', rbac: $pattern)


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/RegisterMapper.php#L524\lib/Db/[1;31mRegisterMapper.php:524:39[0m]8;;\ - Parameter $rbac does not exist on function OCP\DB\QueryBuilder\IQueryBuilder::setParameter (see https://psalm.dev/238)
            ->setParameter('pattern', [97;41mrbac[0m: $pattern)


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L427\lib/Db/[1;31mSchema.php:427:16[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Db\Exception does not exist (see https://psalm.dev/200)
     * @throws [97;41mException[0m If the properties are invalid


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L618\lib/Db/[1;31mSchema.php:618:16[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Db\Exception does not exist (see https://psalm.dev/200)
     * @throws [97;41mException[0m If property validation fails


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L888\lib/Db/[1;31mSchema.php:888:13[0m]8;;\ - Type array<string, mixed> for $this->configuration is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41mis_array($this->configuration)[0m === true) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L893\lib/Db/[1;31mSchema.php:893:13[0m]8;;\ - Type never for $this->configuration is never string (see https://psalm.dev/056)
        if ([97;41mis_string($this->configuration)[0m === true) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L894\lib/Db/[1;31mSchema.php:894:36[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
            $decoded = json_decode([97;41m$this->configuration[0m, true);


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L939\lib/Db/[1;31mSchema.php:939:36[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
            $decoded = json_decode([97;41m$configuration[0m, true);


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L1092\lib/Db/[1;31mSchema.php:1092:28[0m]8;;\ - Property $this->id with type int should already be set in the constructor (see https://psalm.dev/261)
        return 'Schema #'.([97;41m$this->id[0m ?? 'unknown');


[0;31mERROR[0m: RedundantPropertyInitializationCheck - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L1092\lib/Db/[1;31mSchema.php:1092:41[0m]8;;\ - Property $this->id with type int should already be set in the constructor (see https://psalm.dev/261)
        return 'Schema #'.($this->id ?? [97;41m'unknown'[0m);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L1115\lib/Db/[1;31mSchema.php:1115:13[0m]8;;\ - Type array<array-key, mixed> for $this->facets is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41mis_array($this->facets)[0m === true) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L1120\lib/Db/[1;31mSchema.php:1120:13[0m]8;;\ - Type never for $this->facets is never string (see https://psalm.dev/056)
        if ([97;41mis_string($this->facets)[0m === true) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Schema.php#L1121\lib/Db/[1;31mSchema.php:1121:36[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
            $decoded = json_decode([97;41m$this->facets[0m, true);


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L51\lib/Db/[1;31mSchemaMapper.php:51:12[0m]8;;\ - The inherited return type 'list<OCP\AppFramework\Db\Entity>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\Schema>' (see https://psalm.dev/166)
/**
 * The SchemaMapper class
 *
 * Mapper for Schema entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method Schema insert(Entity $entity)
 * @method Schema update(Entity $entity)
 * @method Schema insertOrUpdate(Entity $entity)
 * @method Schema delete(Entity $entity)
 * @method Schema find(int|string $id)
 * @method Schema findEntity(IQueryBuilder $query)
 * @method [97;41mSchema[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method Schema[] findEntities(IQueryBuilder $query)
 */
class SchemaMapper extends QBMapper


[0;31mERROR[0m: MissingTemplateParam - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L53\lib/Db/[1;31mSchemaMapper.php:53:7[0m]8;;\ - OCA\OpenRegister\Db\SchemaMapper has missing template params when extending OCP\AppFramework\Db\QBMapper, expecting 1 (see https://psalm.dev/182)
class [97;41mSchemaMapper[0m extends QBMapper


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L211\lib/Db/[1;31mSchemaMapper.php:211:30[0m]8;;\ - Too few arguments for OCP\DB\QueryBuilder\IExpressionBuilder::in - expecting y to be passed (see https://psalm.dev/025)
                $qb->expr()->[97;41min[0m('id', schema: $qb->createNamedParameter($ids, extend: IQueryBuilder::PARAM_INT_ARRAY))


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L211\lib/Db/[1;31mSchemaMapper.php:211:39[0m]8;;\ - Parameter $schema does not exist on function OCP\DB\QueryBuilder\IExpressionBuilder::in (see https://psalm.dev/238)
                $qb->expr()->in('id', [97;41mschema[0m: $qb->createNamedParameter($ids, extend: IQueryBuilder::PARAM_INT_ARRAY))


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L211\lib/Db/[1;31mSchemaMapper.php:211:79[0m]8;;\ - Parameter $extend does not exist on function OCP\DB\QueryBuilder\IQueryBuilder::createNamedParameter (see https://psalm.dev/238)
                $qb->expr()->in('id', schema: $qb->createNamedParameter($ids, [97;41mextend[0m: IQueryBuilder::PARAM_INT_ARRAY))


[0;31mERROR[0m: ImplicitToStringCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L328\lib/Db/[1;31mSchemaMapper.php:328:30[0m]8;;\ - Argument 1 of setUuid expects null|string, but Symfony\Component\Uid\UuidV4 provided with a __toString method (see https://psalm.dev/060)
            $schema->setUuid([97;41mUuid::v4()[0m);


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L479\lib/Db/[1;31mSchemaMapper.php:479:16[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Db\Exception does not exist (see https://psalm.dev/200)
     * @throws [97;41mException[0m If property validation fails


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L563\lib/Db/[1;31mSchemaMapper.php:563:16[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Db\Exception does not exist (see https://psalm.dev/200)
     * @throws [97;41mException[0m If property validation fails


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L573\lib/Db/[1;31mSchemaMapper.php:573:27[0m]8;;\ - Too few arguments for explode - expecting string to be passed (see https://psalm.dev/025)
            $version    = [97;41mexplode('.', register: $schema->getVersion())[0m;


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L573\lib/Db/[1;31mSchemaMapper.php:573:40[0m]8;;\ - Parameter $register does not exist on function explode (see https://psalm.dev/238)
            $version    = explode('.', [97;41mregister[0m: $schema->getVersion());


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L575\lib/Db/[1;31mSchemaMapper.php:575:46[0m]8;;\ - Parameter $schema does not exist on function implode (see https://psalm.dev/238)
            $schema->setVersion(implode('.', [97;41mschema[0m: $version));


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L578\lib/Db/[1;31mSchemaMapper.php:578:35[0m]8;;\ - Parameter $extend does not exist on function OCA\OpenRegister\Db\Schema::hydrate (see https://psalm.dev/238)
        $schema->hydrate($object, [97;41mextend[0m: $this->validator);


[0;31mERROR[0m: ParamNameMismatch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L598\lib/Db/[1;31mSchemaMapper.php:598:35[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\SchemaMapper::delete has wrong name $schema, expecting $entity as defined by OCP\AppFramework\Db\QBMapper::delete (see https://psalm.dev/230)
    public function delete(Entity [97;41m$schema[0m): Schema


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L607\lib/Db/[1;31mSchemaMapper.php:607:44[0m]8;;\ - Parameter $rbac does not exist on function method_exists (see https://psalm.dev/238)
        $schemaId = method_exists($schema, [97;41mrbac[0m: 'getId') ? $schema->getId() : $schema->id;


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L889\lib/Db/[1;31mSchemaMapper.php:889:13[0m]8;;\ - Operand of type true is always truthy (see https://psalm.dev/122)
        if ([97;41m!empty($facetConfig)[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L889\lib/Db/[1;31mSchemaMapper.php:889:14[0m]8;;\ - Type non-empty-array<array-key, array{created?: array{interval: 'month', type: 'date_histogram'}, interval?: 'month', owner?: array{type: 'terms'}, published?: array{interval: 'month', type: 'date_histogram'}, register?: array{type: 'terms'}, schema?: array{type: 'terms'}, type?: string, updated?: array{interval: 'month', type: 'date_histogram'}}> for $facetConfig is never falsy (see https://psalm.dev/122)
        if (![97;41mempty($facetConfig)[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1039\lib/Db/[1;31mSchemaMapper.php:1039:13[0m]8;;\ - Type array<array-key, mixed> for $allOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41m$allOf !== null && is_array($allOf)[0m && count($allOf) > 0) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1039\lib/Db/[1;31mSchemaMapper.php:1039:32[0m]8;;\ - Type array<array-key, mixed> for $allOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ($allOf !== null && [97;41mis_array($allOf)[0m && count($allOf) > 0) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1044\lib/Db/[1;31mSchemaMapper.php:1044:13[0m]8;;\ - Type array<array-key, mixed> for $oneOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41m$oneOf !== null && is_array($oneOf)[0m && count($oneOf) > 0) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1044\lib/Db/[1;31mSchemaMapper.php:1044:32[0m]8;;\ - Type array<array-key, mixed> for $oneOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ($oneOf !== null && [97;41mis_array($oneOf)[0m && count($oneOf) > 0) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1049\lib/Db/[1;31mSchemaMapper.php:1049:13[0m]8;;\ - Type array<array-key, mixed> for $anyOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41m$anyOf !== null && is_array($anyOf)[0m && count($anyOf) > 0) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1049\lib/Db/[1;31mSchemaMapper.php:1049:32[0m]8;;\ - Type array<array-key, mixed> for $anyOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ($anyOf !== null && [97;41mis_array($anyOf)[0m && count($anyOf) > 0) {


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1111\lib/Db/[1;31mSchemaMapper.php:1111:13[0m]8;;\ - Argument 3 of OCA\OpenRegister\Db\SchemaMapper::mergeSchemaPropertiesWithValidation expects string, but int|string provided (see https://psalm.dev/012)
            [97;41m$currentId[0m


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1684\lib/Db/[1;31mSchemaMapper.php:1684:14[0m]8;;\ - Type array<array-key, mixed> for $oneOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if (([97;41m$oneOf !== null && is_array($oneOf)[0m && count($oneOf) > 0)


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1684\lib/Db/[1;31mSchemaMapper.php:1684:33[0m]8;;\ - Type array<array-key, mixed> for $oneOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if (($oneOf !== null && [97;41mis_array($oneOf)[0m && count($oneOf) > 0)


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1685\lib/Db/[1;31mSchemaMapper.php:1685:17[0m]8;;\ - Type array<array-key, mixed> for $anyOf is always array<array-key, mixed> (see https://psalm.dev/122)
            || ([97;41m$anyOf !== null && is_array($anyOf)[0m && count($anyOf) > 0)


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1685\lib/Db/[1;31mSchemaMapper.php:1685:36[0m]8;;\ - Type array<array-key, mixed> for $anyOf is always array<array-key, mixed> (see https://psalm.dev/122)
            || ($anyOf !== null && [97;41mis_array($anyOf)[0m && count($anyOf) > 0)


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1691\lib/Db/[1;31mSchemaMapper.php:1691:13[0m]8;;\ - Type array<array-key, mixed> for $allOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41m$allOf !== null && is_array($allOf)[0m && count($allOf) > 0) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1691\lib/Db/[1;31mSchemaMapper.php:1691:32[0m]8;;\ - Type array<array-key, mixed> for $allOf is always array<array-key, mixed> (see https://psalm.dev/122)
        if ($allOf !== null && [97;41mis_array($allOf)[0m && count($allOf) > 0) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1898\lib/Db/[1;31mSchemaMapper.php:1898:13[0m]8;;\ - string can never contain null (see https://psalm.dev/122)
        if ([97;41m$targetId !== null[0m && $targetId !== '') {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1898\lib/Db/[1;31mSchemaMapper.php:1898:35[0m]8;;\ - '' can never contain numeric-string (see https://psalm.dev/122)
        if ($targetId !== null && [97;41m$targetId !== ''[0m) {


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1899\lib/Db/[1;31mSchemaMapper.php:1899:44[0m]8;;\ - Too few arguments for OCP\DB\QueryBuilder\IExpressionBuilder::like - expecting y to be passed (see https://psalm.dev/025)
            $orConditions[] = $qb->expr()->[97;41mlike[0m('all_of', schema: $qb->createNamedParameter('%"'.$targetId.'"%'));


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1899\lib/Db/[1;31mSchemaMapper.php:1899:59[0m]8;;\ - Parameter $schema does not exist on function OCP\DB\QueryBuilder\IExpressionBuilder::like (see https://psalm.dev/238)
            $orConditions[] = $qb->expr()->like('all_of', [97;41mschema[0m: $qb->createNamedParameter('%"'.$targetId.'"%'));


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1903\lib/Db/[1;31mSchemaMapper.php:1903:44[0m]8;;\ - Too few arguments for OCP\DB\QueryBuilder\IExpressionBuilder::like - expecting y to be passed (see https://psalm.dev/025)
            $orConditions[] = $qb->expr()->[97;41mlike[0m('all_of', extend: $qb->createNamedParameter('%"'.$targetUuid.'"%'));


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1903\lib/Db/[1;31mSchemaMapper.php:1903:59[0m]8;;\ - Parameter $extend does not exist on function OCP\DB\QueryBuilder\IExpressionBuilder::like (see https://psalm.dev/238)
            $orConditions[] = $qb->expr()->like('all_of', [97;41mextend[0m: $qb->createNamedParameter('%"'.$targetUuid.'"%'));


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1907\lib/Db/[1;31mSchemaMapper.php:1907:44[0m]8;;\ - Too few arguments for OCP\DB\QueryBuilder\IExpressionBuilder::like - expecting y to be passed (see https://psalm.dev/025)
            $orConditions[] = $qb->expr()->[97;41mlike[0m('all_of', files: $qb->createNamedParameter('%"'.$targetSlug.'"%'));


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1907\lib/Db/[1;31mSchemaMapper.php:1907:59[0m]8;;\ - Parameter $files does not exist on function OCP\DB\QueryBuilder\IExpressionBuilder::like (see https://psalm.dev/238)
            $orConditions[] = $qb->expr()->like('all_of', [97;41mfiles[0m: $qb->createNamedParameter('%"'.$targetSlug.'"%'));


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1911\lib/Db/[1;31mSchemaMapper.php:1911:35[0m]8;;\ - '' can never contain non-empty-string (see https://psalm.dev/122)
        if ($targetId !== null && [97;41m$targetId !== ''[0m) {


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1912\lib/Db/[1;31mSchemaMapper.php:1912:44[0m]8;;\ - Too few arguments for OCP\DB\QueryBuilder\IExpressionBuilder::like - expecting y to be passed (see https://psalm.dev/025)
            $orConditions[] = $qb->expr()->[97;41mlike[0m('one_of', rbac: $qb->createNamedParameter('%"'.$targetId.'"%'));


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SchemaMapper.php#L1912\lib/Db/[1;31mSchemaMapper.php:1912:59[0m]8;;\ - Parameter $rbac does not exist on function OCP\DB\QueryBuilder\IExpressionBuilder::like (see https://psalm.dev/238)
            $orConditions[] = $qb->expr()->like('one_of', [97;41mrbac[0m: $qb->createNamedParameter('%"'.$targetId.'"%'));


[0;31mERROR[0m: LessSpecificImplementedReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SearchTrailMapper.php#L52\lib/Db/[1;31mSearchTrailMapper.php:52:12[0m]8;;\ - The inherited return type 'list<OCP\AppFramework\Db\Entity>' for OCP\AppFramework\Db\QBMapper::findEntities is more specific than the implemented return type for OCP\AppFramework\Db\QBMapper::findentities 'array<array-key, OCA\OpenRegister\Db\SearchTrail>' (see https://psalm.dev/166)
/**
 * SearchTrailMapper handles database operations for SearchTrail entities
 *
 * Provides comprehensive CRUD operations and statistical query methods
 * for search analytics and optimization.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method SearchTrail insert(Entity $entity)
 * @method SearchTrail update(Entity $entity)
 * @method SearchTrail insertOrUpdate(Entity $entity)
 * @method SearchTrail delete(Entity $entity)
 * @method SearchTrail find(int|string $id)
 * @method SearchTrail findEntity(IQueryBuilder $query)
 * @method [97;41mSearchTrail[][0m findAll(int|null $limit = null, int|null $offset = null)
 * @method SearchTrail[] findEntities(IQueryBuilder $query)
 */
class SearchTrailMapper extends QBMapper


[0;31mERROR[0m: MissingTemplateParam - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SearchTrailMapper.php#L54\lib/Db/[1;31mSearchTrailMapper.php:54:7[0m]8;;\ - OCA\OpenRegister\Db\SearchTrailMapper has missing template params when extending OCP\AppFramework\Db\QBMapper, expecting 1 (see https://psalm.dev/182)
class [97;41mSearchTrailMapper[0m extends QBMapper


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SearchTrailMapper.php#L406\lib/Db/[1;31mSearchTrailMapper.php:406:13[0m]8;;\ - Docblock-defined class, interface or enum named Doctrine\DBAL\Platforms\AbstractPlatform does not exist (see https://psalm.dev/200)
        if ([97;41m$this->db->getDatabasePlatform()[0m->getName() === 'mysql') {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SearchTrailMapper.php#L985\lib/Db/[1;31mSearchTrailMapper.php:985:22[0m]8;;\ - Type string for $<tmp coalesce var>34590 is never null (see https://psalm.dev/122)
        $sessionId = [97;41m$this->request->getHeader('X-Session-ID')[0m ?? session_id();


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/SearchTrailMapper.php#L985\lib/Db/[1;31mSearchTrailMapper.php:985:67[0m]8;;\ - Cannot resolve types for $<tmp coalesce var>34590 - string does not contain null (see https://psalm.dev/090)
        $sessionId = $this->request->getHeader('X-Session-ID') ?? [97;41msession_id()[0m;


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/EventListener/AbstractNodeFolderEventListener.php#L45\lib/EventListener/[1;31mAbstractNodeFolderEventListener.php:45:15[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\EventListener\FileService does not exist (see https://psalm.dev/019)
     * @param [97;41mFileService[0m   $fileService   The file service for file operations.


[0;31mERROR[0m: MoreSpecificImplementedParamType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Migration/Version1Date20250903160000.php#L58\lib/Migration/[1;31mVersion1Date20250903160000.php:58:81[0m]8;;\ - Argument 3 of OCA\OpenRegister\Migration\Version1Date20250903160000::changeSchema has the more specific type 'array<string, mixed>', expecting 'array<array-key, mixed>' as defined by OCP\Migration\IMigrationStep::changeSchema (see https://psalm.dev/140)
    public function changeSchema(IOutput $output, Closure $schemaClosure, array [97;41m$options[0m): ?ISchemaWrapper


[0;31mERROR[0m: MoreSpecificImplementedParamType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Migration/Version1Date20250903160000.php#L58\lib/Migration/[1;31mVersion1Date20250903160000.php:58:81[0m]8;;\ - Argument 3 of OCA\OpenRegister\Migration\Version1Date20250903160000::changeSchema has the more specific type 'array<string, mixed>', expecting 'array<array-key, mixed>' as defined by OCP\Migration\SimpleMigrationStep::changeSchema (see https://psalm.dev/140)
    public function changeSchema(IOutput $output, Closure $schemaClosure, array [97;41m$options[0m): ?ISchemaWrapper


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Migration/Version1Date20250904170000.php#L107\lib/Migration/[1;31mVersion1Date20250904170000.php:107:15[0m]8;;\ - Docblock-defined class, interface or enum named OCP\DB\Types\ITable does not exist (see https://psalm.dev/200)
     * @param [97;41m\OCP\DB\Types\ITable[0m $table  The table to optimize


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Migration/Version1Date20251106000000.php#L65\lib/Migration/[1;31mVersion1Date20251106000000.php:65:34[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Types\Type does not exist (see https://psalm.dev/019)
                $column->setType([97;41m\Doctrine\DBAL\Types\Type[0m::getType(Types::STRING));


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Migration/Version1Date20251106120000.php#L86\lib/Migration/[1;31mVersion1Date20251106120000.php:86:38[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Types\Type does not exist (see https://psalm.dev/019)
                    $column->setType([97;41m\Doctrine\DBAL\Types\Type[0m::getType(Types::STRING));


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Search/ObjectsProvider.php#L292\lib/Search/[1;31mObjectsProvider.php:292:34[0m]8;;\ - Type null for $limit is always !null (see https://psalm.dev/056)
        $searchQuery['_limit'] = [97;41m$limit[0m ?? 25;


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Search/ObjectsProvider.php#L297\lib/Search/[1;31mObjectsProvider.php:297:35[0m]8;;\ - Type null for $offset is always !null (see https://psalm.dev/056)
        $searchQuery['_offset'] = [97;41m$offset[0m ?? 0;


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/AuthorizationExceptionService.php#L341\lib/Service/[1;31mAuthorizationExceptionService.php:341:58[0m]8;;\ - Argument 1 of OCP\IGroupManager::getUserGroups expects OCP\IUser|null, but OCP\IGroup provided (see https://psalm.dev/004)
            $groups = $this->groupManager->getUserGroups([97;41m$userObj[0m);


[0;31mERROR[0m: TooManyArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L689\lib/Service/[1;31mChatService.php:689:40[0m]8;;\ - Too many arguments for method OCA\OpenRegister\Service\GuzzleSolrService::searchobjectspaginated - saw 9 (see https://psalm.dev/026)
        $results = $this->solrService->[97;41msearchObjectsPaginated[0m(


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L690\lib/Service/[1;31mChatService.php:690:13[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\GuzzleSolrService::searchObjectsPaginated expects array<array-key, mixed>, but string provided (see https://psalm.dev/004)
            [97;41m$query[0m,


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L691\lib/Service/[1;31mChatService.php:691:13[0m]8;;\ - Argument 2 of OCA\OpenRegister\Service\GuzzleSolrService::searchObjectsPaginated expects bool, but 0 provided (see https://psalm.dev/012)
            [97;41m0[0m,


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L692\lib/Service/[1;31mChatService.php:692:13[0m]8;;\ - Argument 3 of OCA\OpenRegister\Service\GuzzleSolrService::searchObjectsPaginated expects bool, but int provided (see https://psalm.dev/012)
            [97;41m$limit[0m,


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L693\lib/Service/[1;31mChatService.php:693:13[0m]8;;\ - Argument 4 of OCA\OpenRegister\Service\GuzzleSolrService::searchObjectsPaginated expects bool, but array<never, never> provided (see https://psalm.dev/004)
            [97;41m[][0m,


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L694\lib/Service/[1;31mChatService.php:694:13[0m]8;;\ - Argument 5 of OCA\OpenRegister\Service\GuzzleSolrService::searchObjectsPaginated expects bool, but 'score desc' provided (see https://psalm.dev/012)
            [97;41m'score desc'[0m,


[0;31mERROR[0m: UndefinedPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L951\lib/Service/[1;31mChatService.php:951:25[0m]8;;\ - Instance property LLPhant\OpenAIConfig::$organizationId is not defined (see https://psalm.dev/038)
                        [97;41m$config->organizationId[0m = $openaiConfig['organizationId'];


[0;31mERROR[0m: UndefinedPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L977\lib/Service/[1;31mChatService.php:977:21[0m]8;;\ - Instance property LLPhant\OpenAIConfig::$temperature is not defined (see https://psalm.dev/038)
                    [97;41m$config->temperature[0m = $agent->getTemperature();


[0;31mERROR[0m: UndefinedPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1007\lib/Service/[1;31mChatService.php:1007:21[0m]8;;\ - Instance property LLPhant\OllamaConfig::$apiKey is not defined (see https://psalm.dev/039)
                    [97;41m$config->apiKey[0m,


[0;31mERROR[0m: UndefinedPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1145\lib/Service/[1;31mChatService.php:1145:17[0m]8;;\ - Instance property LLPhant\OpenAIConfig::$temperature is not defined (see https://psalm.dev/038)
                [97;41m$config->temperature[0m = 0.7;


[0;31mERROR[0m: UndefinedPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1157\lib/Service/[1;31mChatService.php:1157:21[0m]8;;\ - Instance property LLPhant\OllamaConfig::$apiKey is not defined (see https://psalm.dev/039)
                    [97;41m$config->apiKey[0m,


[0;31mERROR[0m: UndefinedPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1349\lib/Service/[1;31mChatService.php:1349:25[0m]8;;\ - Instance property LLPhant\OpenAIConfig::$organizationId is not defined (see https://psalm.dev/038)
                        [97;41m$llphantConfig->organizationId[0m = $config['organizationId'];


[0;31mERROR[0m: UndefinedPropertyAssignment - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1371\lib/Service/[1;31mChatService.php:1371:21[0m]8;;\ - Instance property LLPhant\OpenAIConfig::$temperature is not defined (see https://psalm.dev/038)
                    [97;41m$llphantConfig->temperature[0m = (float) $config['temperature'];


[0;31mERROR[0m: UndefinedPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1379\lib/Service/[1;31mChatService.php:1379:21[0m]8;;\ - Instance property LLPhant\OllamaConfig::$apiKey is not defined (see https://psalm.dev/039)
                    [97;41m$llphantConfig->apiKey[0m,


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1394\lib/Service/[1;31mChatService.php:1394:39[0m]8;;\ - Type string for $llphantConfig->url is always isset (see https://psalm.dev/122)
                        'url'      => [97;41m$llphantConfig->url[0m ?? 'default',


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1394\lib/Service/[1;31mChatService.php:1394:62[0m]8;;\ - Cannot resolve types for $llphantConfig->url with type string and !isset assertion (see https://psalm.dev/056)
                        'url'      => $llphantConfig->url ?? [97;41m'default'[0m,


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1409\lib/Service/[1;31mChatService.php:1409:43[0m]8;;\ - Type string for $llphantConfig->url is always isset (see https://psalm.dev/122)
                            'url'      => [97;41m$llphantConfig->url[0m ?? 'default',


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1409\lib/Service/[1;31mChatService.php:1409:66[0m]8;;\ - Cannot resolve types for $llphantConfig->url with type string and !isset assertion (see https://psalm.dev/056)
                            'url'      => $llphantConfig->url ?? [97;41m'default'[0m,


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1546\lib/Service/[1;31mChatService.php:1546:13[0m]8;;\ - string can never contain null (see https://psalm.dev/122)
        if ([97;41m$curlError !== null[0m && $curlError !== '') {


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1552\lib/Service/[1;31mChatService.php:1552:41[0m]8;;\ - Argument 1 of json_decode expects string, but bool|string provided (see https://psalm.dev/012)
            $errorData    = json_decode([97;41m$response[0m, true);


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1567\lib/Service/[1;31mChatService.php:1567:29[0m]8;;\ - Argument 1 of json_decode expects string, but bool|string provided (see https://psalm.dev/012)
        $data = json_decode([97;41m$response[0m, true);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1661\lib/Service/[1;31mChatService.php:1661:13[0m]8;;\ - string can never contain null (see https://psalm.dev/122)
        if ([97;41m$curlError !== null[0m && $curlError !== '') {


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1667\lib/Service/[1;31mChatService.php:1667:41[0m]8;;\ - Argument 1 of json_decode expects string, but bool|string provided (see https://psalm.dev/012)
            $errorData    = json_decode([97;41m$response[0m, true);


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1682\lib/Service/[1;31mChatService.php:1682:29[0m]8;;\ - Argument 1 of json_decode expects string, but bool|string provided (see https://psalm.dev/012)
        $data = json_decode([97;41m$response[0m, true);


[0;31mERROR[0m: UndefinedPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1846\lib/Service/[1;31mChatService.php:1846:17[0m]8;;\ - Instance property LLPhant\OllamaConfig::$apiKey is not defined (see https://psalm.dev/039)
                [97;41m$config->apiKey[0m,


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ChatService.php#L1945\lib/Service/[1;31mChatService.php:1945:24[0m]8;;\ - Method OCA\OpenRegister\Tool\ToolInterface::setAgent does not exist (see https://psalm.dev/181)
                $tool->[97;41msetAgent[0m($agent);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationCacheService.php#L171\lib/Service/[1;31mConfigurationCacheService.php:171:40[0m]8;;\ - Method OCP\ISession::getKeys does not exist (see https://psalm.dev/181)
        $sessionKeys = $this->session->[97;41mgetKeys[0m();


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L90\lib/Service/[1;31mConfigurationService.php:90:13[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Service\OCA\OpenConnector\Service\ConfigurationService does not exist (see https://psalm.dev/200)
     * @var [97;41mOCA\OpenConnector\Service\ConfigurationService[0m The OpenConnector service instance.


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L430\lib/Service/[1;31mConfigurationService.php:430:40[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Service\OCA\OpenConnector\Service\ConfigurationService does not exist (see https://psalm.dev/200)
                $openConnectorConfig = [97;41m$this->openConnectorConfigurationService[0m->exportRegister($register->getId());


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L521\lib/Service/[1;31mConfigurationService.php:521:54[0m]8;;\ - Cannot access value on variable $registerIdsAndSlugsMap using a numeric offset, expecting array-key (see https://psalm.dev/115)
                if (is_numeric($registerId) && isset([97;41m$registerIdsAndSlugsMap[$registerId][0m) === true) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L534\lib/Service/[1;31mConfigurationService.php:534:52[0m]8;;\ - Cannot access value on variable $schemaIdsAndSlugsMap using a numeric offset, expecting array-key (see https://psalm.dev/115)
                if (is_numeric($schemaId) && isset([97;41m$schemaIdsAndSlugsMap[$schemaId][0m) === true) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L551\lib/Service/[1;31mConfigurationService.php:551:54[0m]8;;\ - Cannot access value on variable $registerIdsAndSlugsMap using a numeric offset, expecting array-key (see https://psalm.dev/115)
                if (is_numeric($registerId) && isset([97;41m$registerIdsAndSlugsMap[$registerId][0m) === true) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L568\lib/Service/[1;31mConfigurationService.php:568:52[0m]8;;\ - Cannot access value on variable $schemaIdsAndSlugsMap using a numeric offset, expecting array-key (see https://psalm.dev/115)
                if (is_numeric($schemaId) && isset([97;41m$schemaIdsAndSlugsMap[$schemaId][0m) === true) {


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L613\lib/Service/[1;31mConfigurationService.php:613:16[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Service\InvalidArgumentException does not exist (see https://psalm.dev/200)
     * @throws [97;41mInvalidArgumentException[0m If the URL is not a string


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L777\lib/Service/[1;31mConfigurationService.php:777:16[0m]8;;\ - The declared return type 'array<array-key, mixed>' for OCA\OpenRegister\Service\ConfigurationService::getJSONfromFile is incorrect, got 'OCP\AppFramework\Http\JSONResponse<400, array{'MIME-type'?: string, error: string}, array<never, never>>|array<array-key, mixed>' (see https://psalm.dev/011)
     * @return [97;41marray[0m A PHP array with the uploaded json data or a JSONResponse in case of an error.


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L783\lib/Service/[1;31mConfigurationService.php:783:20[0m]8;;\ - The inferred type 'OCP\AppFramework\Http\JSONResponse<400, array{error: string}, array<never, never>>' does not match the declared return type 'array<array-key, mixed>' for OCA\OpenRegister\Service\ConfigurationService::getJSONfromFile (see https://psalm.dev/128)
            return [97;41mnew JSONResponse(data: ['error' => 'File upload error: '.$uploadedFile['error']], statusCode: 400)[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L791\lib/Service/[1;31mConfigurationService.php:791:20[0m]8;;\ - The inferred type 'OCP\AppFramework\Http\JSONResponse<400, array{'MIME-type': string, error: 'Failed to decode file content as JSON or YAML'}, array<never, never>>' does not match the declared return type 'array<array-key, mixed>' for OCA\OpenRegister\Service\ConfigurationService::getJSONfromFile (see https://psalm.dev/128)
            return [97;41mnew JSONResponse(
                data: ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => $fileExtension],
                statusCode: 400
            )[0m;


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L843\lib/Service/[1;31mConfigurationService.php:843:16[0m]8;;\ - The declared return type 'array<array-key, mixed>' for OCA\OpenRegister\Service\ConfigurationService::getJSONfromBody is incorrect, got 'OCP\AppFramework\Http\JSONResponse<400, array{error: 'Failed to decode JSON input'}, array<never, never>>|array<array-key, mixed>' (see https://psalm.dev/011)
     * @return [97;41marray[0m A PHP array with the uploaded json data or a JSONResponse in case of an error.


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L852\lib/Service/[1;31mConfigurationService.php:852:20[0m]8;;\ - The inferred type 'OCP\AppFramework\Http\JSONResponse<400, array{error: 'Failed to decode JSON input'}, array<never, never>>' does not match the declared return type 'array<array-key, mixed>' for OCA\OpenRegister\Service\ConfigurationService::getJSONfromBody (see https://psalm.dev/128)
            return [97;41mnew JSONResponse(
                data: ['error' => 'Failed to decode JSON input'],
                statusCode: 400
            )[0m;


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1158\lib/Service/[1;31mConfigurationService.php:1158:29[0m]8;;\ - OCA\OpenRegister\Db\ObjectEntity can never contain null (see https://psalm.dev/122)
                        if ([97;41m$object !== null[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1183\lib/Service/[1;31mConfigurationService.php:1183:25[0m]8;;\ - OCA\OpenRegister\Db\ObjectEntity can never contain null (see https://psalm.dev/122)
                    if ([97;41m$object !== null[0m) {


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1193\lib/Service/[1;31mConfigurationService.php:1193:36[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Service\OCA\OpenConnector\Service\ConfigurationService does not exist (see https://psalm.dev/200)
            $openConnectorResult = [97;41m$this->openConnectorConfigurationService[0m->importConfiguration($data);


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1566\lib/Service/[1;31mConfigurationService.php:1566:33[0m]8;;\ - $this->registersMap with declared type 'array<string, OCA\OpenRegister\Db\Register>' cannot be assigned type 'non-empty-array<int|string, OCA\OpenRegister\Db\Register>' (see https://psalm.dev/145)
                                [97;41m$this->registersMap[0m[$registerSlug] = $existingRegister;


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1591\lib/Service/[1;31mConfigurationService.php:1591:37[0m]8;;\ - $this->schemasMap with declared type 'array<string, OCA\OpenRegister\Db\Schema>' cannot be assigned type 'non-empty-array<int|string, OCA\OpenRegister\Db\Schema>' (see https://psalm.dev/145)
                                    [97;41m$this->schemasMap[0m[$schemaSlug] = $existingSchema;


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1628\lib/Service/[1;31mConfigurationService.php:1628:33[0m]8;;\ - $this->registersMap with declared type 'array<string, OCA\OpenRegister\Db\Register>' cannot be assigned type 'non-empty-array<int|string, OCA\OpenRegister\Db\Register>' (see https://psalm.dev/145)
                                [97;41m$this->registersMap[0m[$registerSlug] = $existingRegister;


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1653\lib/Service/[1;31mConfigurationService.php:1653:37[0m]8;;\ - $this->schemasMap with declared type 'array<string, OCA\OpenRegister\Db\Schema>' cannot be assigned type 'non-empty-array<int|string, OCA\OpenRegister\Db\Schema>' (see https://psalm.dev/145)
                                    [97;41m$this->schemasMap[0m[$schemaSlug] = $existingSchema;


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1767\lib/Service/[1;31mConfigurationService.php:1767:21[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\ObjectEntityMapper::findAll expects int|null, but array{filters: array{name: mixed, register: mixed, schema: mixed}} provided (see https://psalm.dev/004)
                    [97;41m[
                        'filters' => [
                            'register' => $registerId,
                            'schema'   => $schemaId,
                            'name'     => $objectName,
                        ],
                    ][0m


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1865\lib/Service/[1;31mConfigurationService.php:1865:29[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Service\OCA\OpenConnector\Service\ConfigurationService does not exist (see https://psalm.dev/200)
            $exportedData = [97;41m$this->openConnectorConfigurationService[0m->exportRegister($registerId);


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L1916\lib/Service/[1;31mConfigurationService.php:1916:25[0m]8;;\ - Instance property OCA\OpenRegister\Service\ConfigurationService::$appDataPath is not defined (see https://psalm.dev/041)
            $fullPath = [97;41m$this->appDataPath[0m.'/../../../'.$filePath;


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L2616\lib/Service/[1;31mConfigurationService.php:2616:24[0m]8;;\ - The declared return type 'OCP\AppFramework\Http\JSONResponse|array{endpoints: array<array-key, mixed>, jobs: array<array-key, mixed>, mappings: array<array-key, mixed>, objects: array<array-key, mixed>, registers: array<array-key, mixed>, rules: array<array-key, mixed>, schemas: array<array-key, mixed>, sources: array<array-key, mixed>, synchronizations: array<array-key, mixed>}' for OCA\OpenRegister\Service\ConfigurationService::previewConfigurationChanges is incorrect, got 'OCP\AppFramework\Http\JSONResponse<100|101|102|200|201|202|203|204|205|206|207|208|226|300|301|302|303|304|305|306|307|400|401|402|403|404|405|406|407|408|409|410|411|412|413|414|415|416|417|418|422|423|424|426|428|429|431|500|501|502|503|504|505|506|507|508|509|510|511, JsonSerializable|array<array-key, mixed>|null|scalar|stdClass, array<string, mixed>>|array{endpoints: array<never, never>, jobs: array<never, never>, mappings: array<never, never>, metadata: array{configurationId: int, configurationTitle: null|string, localVersion: null|string, previewedAt: string, remoteVersion: mixed|null, sourceUrl: null|string, totalChanges: int<0, max>}, objects: list<array{action: string, changes: array<array-key, mixed>, current: array<array-key, mixed>|null, proposed: array<array-key, mixed>, register: string, schema: string, slug: string, title: string, type: string}>, registers: list<array{action: string, changes: array<array-key, mixed>, current: array<array-key, mixed>|null, proposed: array<array-key, mixed>, slug: string, title: string, type: string}>, rules: array<never, never>, schemas: list<array{action: string, changes: array<array-key, mixed>, current: array<array-key, mixed>|null, proposed: array<array-key, mixed>, slug: string, title: string, type: string}>, sources: array<never, never>, synchronizations: array<never, never>}' which is different due to additional array shape fields (metadata) (see https://psalm.dev/011)
     * @phpstan-return [97;41marray{
     *     registers: array,
     *     schemas: array,
     *     objects: array,
     *     endpoints: array,
     *     sources: array,
     *     mappings: array,
     *     jobs: array,
     *     synchronizations: array,
     *     rules: array
     * }|JSONResponse[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L2699\lib/Service/[1;31mConfigurationService.php:2699:16[0m]8;;\ - The inferred type 'array{endpoints: array<never, never>, jobs: array<never, never>, mappings: array<never, never>, metadata: array{configurationId: int, configurationTitle: null|string, localVersion: null|string, previewedAt: string, remoteVersion: mixed|null, sourceUrl: null|string, totalChanges: int<0, max>}, objects: list<array{action: string, changes: array<array-key, mixed>, current: array<array-key, mixed>|null, proposed: array<array-key, mixed>, register: string, schema: string, slug: string, title: string, type: string}>, registers: list<array{action: string, changes: array<array-key, mixed>, current: array<array-key, mixed>|null, proposed: array<array-key, mixed>, slug: string, title: string, type: string}>, rules: array<never, never>, schemas: list<array{action: string, changes: array<array-key, mixed>, current: array<array-key, mixed>|null, proposed: array<array-key, mixed>, slug: string, title: string, type: string}>, sources: array<never, never>, synchronizations: array<never, never>}' does not match the declared return type 'OCP\AppFramework\Http\JSONResponse|array{endpoints: array<array-key, mixed>, jobs: array<array-key, mixed>, mappings: array<array-key, mixed>, objects: array<array-key, mixed>, registers: array<array-key, mixed>, rules: array<array-key, mixed>, schemas: array<array-key, mixed>, sources: array<array-key, mixed>, synchronizations: array<array-key, mixed>}' for OCA\OpenRegister\Service\ConfigurationService::previewConfigurationChanges due to additional array shape fields (metadata) (see https://psalm.dev/128)
        return [97;41m$preview[0m;


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L3029\lib/Service/[1;31mConfigurationService.php:3029:24[0m]8;;\ - The declared return type 'array{objects: array<array-key, OCA\OpenRegister\Db\ObjectEntity>, registers: array<array-key, OCA\OpenRegister\Db\Register>, schemas: array<array-key, OCA\OpenRegister\Db\Schema>, skipped: array<array-key, mixed>}' for OCA\OpenRegister\Service\ConfigurationService::importConfigurationWithSelection is incorrect, got 'array{endpoints: array<array-key, mixed>, jobs: array<array-key, mixed>, mappings: array<array-key, mixed>, objects: array<array-key, OCA\OpenRegister\Db\ObjectEntity>, registers: array<array-key, OCA\OpenRegister\Db\Register>, rules: array<array-key, mixed>, schemas: array<array-key, OCA\OpenRegister\Db\Schema>, sources: array<array-key, mixed>, synchronizations: array<array-key, mixed>}' which is different due to additional array shape fields (endpoints, sources, mappings, jobs, synchronizations, rules) (see https://psalm.dev/011)
     * @phpstan-return [97;41marray{
     *     registers: array<Register>,
     *     schemas: array<Schema>,
     *     objects: array<ObjectEntity>,
     *     skipped: array
     * }[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ConfigurationService.php#L3164\lib/Service/[1;31mConfigurationService.php:3164:16[0m]8;;\ - The inferred type 'array{endpoints: array<array-key, mixed>, jobs: array<array-key, mixed>, mappings: array<array-key, mixed>, objects: array<array-key, OCA\OpenRegister\Db\ObjectEntity>, registers: array<array-key, OCA\OpenRegister\Db\Register>, rules: array<array-key, mixed>, schemas: array<array-key, OCA\OpenRegister\Db\Schema>, sources: array<array-key, mixed>, synchronizations: array<array-key, mixed>}' does not match the declared return type 'array{objects: array<array-key, OCA\OpenRegister\Db\ObjectEntity>, registers: array<array-key, OCA\OpenRegister\Db\Register>, schemas: array<array-key, OCA\OpenRegister\Db\Schema>, skipped: array<array-key, mixed>}' for OCA\OpenRegister\Service\ConfigurationService::importConfigurationWithSelection due to additional array shape fields (endpoints, sources, mappings, jobs, synchronizations, rules) (see https://psalm.dev/128)
        return [97;41m$result[0m;


[0;31mERROR[0m: InvalidDocblock - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/DashboardService.php#L272\lib/Service/[1;31mDashboardService.php:272:5[0m]8;;\ - Unexpected token . in docblock for OCA\OpenRegister\Service\DashboardService::getRegistersWithSchemas (see https://psalm.dev/008)
    /**
     * Get all registers with their schemas and statistics
     *
     * @param int|null $registerId The register ID to filter by
     * @param int|null $schemaId   The schema ID to filter by
     *
     * @return (array|mixed|string)[][] Array of registers with their schemas and statistics
     *
     * @throws \Exception If there is an error getting the registers with schemas
     *
     * @psalm-return list{
     *     0: array{
     *         id: 'orphaned'|'totals'|mixed,
     *         title: 'Orphaned Items'|'System Totals'|mixed,
     *         description: (
     *             'Items that reference non-existent registers, schemas, '
     *             .'or invalid register-schema combinations'
     *             )|('Total statistics across all registers and schemas')|mixed,
     *         stats: array,
     *         schemas: list<mixed>,
     *         ...
     *     },
     *     1?: array{
     *         stats: array,
     *         schemas: list<mixed>,
     *         id: 'orphaned'|'totals'|mixed,
     *         title: 'Orphaned Items'|'System Totals'|mixed,
     *         description: (
     *             'Items that reference non-existent registers, schemas, '
     *             .'or invalid register-schema combinations'
     *             )|('Total statistics across all registers and schemas')|mixed,
     *         ...
     *     },
     *     ...
     * }
     */
    [97;41mpublic function getRegistersWithSchemas([0m


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/DashboardService.php#L539\lib/Service/[1;31mDashboardService.php:539:37[0m]8;;\ - Method OCA\OpenRegister\Service\DashboardService::buildRegisterScope does not exist (see https://psalm.dev/022)
            $registerScope = $this->[97;41mbuildRegisterScope[0m($register);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/DashboardService.php#L541\lib/Service/[1;31mDashboardService.php:541:35[0m]8;;\ - Method OCA\OpenRegister\Service\DashboardService::buildSchemaScope does not exist (see https://psalm.dev/022)
            $schemaScope = $this->[97;41mbuildSchemaScope[0m($schema);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/DashboardService.php#L543\lib/Service/[1;31mDashboardService.php:543:35[0m]8;;\ - Method OCA\OpenRegister\Service\DashboardService::calculateSuccessRate does not exist (see https://psalm.dev/022)
            $successRate = $this->[97;41mcalculateSuccessRate[0m($results);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ExportService.php#L156\lib/Service/[1;31mExportService.php:156:34[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but PhpOffice\PhpSpreadsheet\Spreadsheet provided (see https://psalm.dev/004)
                        $resolve([97;41m$spreadsheet[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ExportService.php#L220\lib/Service/[1;31mExportService.php:220:34[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but string provided (see https://psalm.dev/004)
                        $resolve([97;41m$csv[0m);


[0;31mERROR[0m: StringIncrement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ExportService.php#L390\lib/Service/[1;31mExportService.php:390:17[0m]8;;\ - Possibly unintended string increment (see https://psalm.dev/211)
                [97;41m$col[0m++;


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L129\lib/Service/[1;31mFileService.php:129:15[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
     * @param [97;41mIRootFolder[0m           $rootFolder         The root folder interface


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L139\lib/Service/[1;31mFileService.php:139:15[0m]8;;\ - Class, interface or enum named OCA\Files_Versions\Versions\VersionManager does not exist (see https://psalm.dev/019)
     * @param [97;41mVersionManager[0m        $versionManager     Version manager service


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L210\lib/Service/[1;31mFileService.php:210:9[0m]8;;\ - Class, interface or enum named OCA\Files_Versions\Versions\VersionManager does not exist (see https://psalm.dev/019)
        [97;41m$this->versionManager[0m->createVersion(user: $this->userManager->get(self::APP_USER), file: $file);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L236\lib/Service/[1;31mFileService.php:236:16[0m]8;;\ - Class, interface or enum named OCA\Files_Versions\Versions\VersionManager does not exist (see https://psalm.dev/019)
        return [97;41m$this->versionManager[0m->getVersionFile($this->userManager->get(self::APP_USER), $file, $version);


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L365\lib/Service/[1;31mFileService.php:365:7[0m]8;;\ - Type OCA\OpenRegister\Db\ObjectEntity for $objectEntity is never string (see https://psalm.dev/056)
		if ([97;41mis_string($objectEntity)[0m === true) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L366\lib/Service/[1;31mFileService.php:366:4[0m]8;;\ - All possible types for this return were invalidated - This may be dead code (see https://psalm.dev/179)
			[97;41mreturn $objectEntity;[0m


[0;31mERROR[0m: InvalidCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L431\lib/Service/[1;31mFileService.php:431:60[0m]8;;\ - never cannot be cast to int (see https://psalm.dev/103)
                $existingFolder = $this->getNodeById((int) [97;41m$folderProperty[0m);


[0;31mERROR[0m: InvalidCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L491\lib/Service/[1;31mFileService.php:491:60[0m]8;;\ - never cannot be cast to int (see https://psalm.dev/103)
                $existingFolder = $this->getNodeById((int) [97;41m$folderProperty[0m);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L530\lib/Service/[1;31mFileService.php:530:46[0m]8;;\ - Method OCP\Files\Node::get does not exist (see https://psalm.dev/181)
            $objectFolder = $registerFolder->[97;41mget[0m($objectFolderName);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L534\lib/Service/[1;31mFileService.php:534:46[0m]8;;\ - Method OCP\Files\Node::newFolder does not exist (see https://psalm.dev/181)
            $objectFolder = $registerFolder->[97;41mnewFolder[0m($objectFolderName);


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L574\lib/Service/[1;31mFileService.php:574:27[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
            $userFolder = [97;41m$this->rootFolder[0m->getUserFolder($user->getUID());


[0;31mERROR[0m: InvalidCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L670\lib/Service/[1;31mFileService.php:670:44[0m]8;;\ - never cannot be cast to int (see https://psalm.dev/103)
        $folder = $this->getNodeById((int) [97;41m$folderProperty[0m);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L757\lib/Service/[1;31mFileService.php:757:45[0m]8;;\ - Method OCA\OpenRegister\Service\FileService::getNodeTypeFromFolder does not exist (see https://psalm.dev/022)
                    'nodeType'    => $this->[97;41mgetNodeTypeFromFolder[0m($rootFolder),


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L932\lib/Service/[1;31mFileService.php:932:24[0m]8;;\ - Method OCP\Files\Node::getDirectoryListing does not exist (see https://psalm.dev/181)
                $file->[97;41mgetDirectoryListing[0m();


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1035\lib/Service/[1;31mFileService.php:1035:37[0m]8;;\ - Method OCA\OpenRegister\Service\FileService::getAccessUrlFromShares does not exist (see https://psalm.dev/022)
            'accessUrl'   => $this->[97;41mgetAccessUrlFromShares[0m($shares),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1036\lib/Service/[1;31mFileService.php:1036:37[0m]8;;\ - Method OCA\OpenRegister\Service\FileService::getDownloadUrlFromShares does not exist (see https://psalm.dev/022)
            'downloadUrl' => $this->[97;41mgetDownloadUrlFromShares[0m($shares),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1041\lib/Service/[1;31mFileService.php:1041:37[0m]8;;\ - Method OCA\OpenRegister\Service\FileService::getPublishedTimeFromShares does not exist (see https://psalm.dev/022)
            'published'   => $this->[97;41mgetPublishedTimeFromShares[0m($shares),


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1043\lib/Service/[1;31mFileService.php:1043:57[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\FileService::getFileTags expects string, but int provided (see https://psalm.dev/004)
            'labels'      => $this->getFileTags(fileId: [97;41m$file->getId()[0m),


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1626\lib/Service/[1;31mFileService.php:1626:38[0m]8;;\ - Method OCP\Files\Storage\IStorage::chown does not exist (see https://psalm.dev/181)
                $file->getStorage()->[97;41mchown[0m($file->getInternalPath(), $openRegisterUserId);


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1655\lib/Service/[1;31mFileService.php:1655:52[0m]8;;\ - Too few arguments for OCP\Share\IManager::getSharesBy - expecting userId to be passed (see https://psalm.dev/025)
            $existingShares = $this->shareManager->[97;41mgetSharesBy[0m(


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1656\lib/Service/[1;31mFileService.php:1656:17[0m]8;;\ - Parameter $sharedBy does not exist on function OCP\Share\IManager::getSharesBy (see https://psalm.dev/238)
                [97;41msharedBy[0m: $this->getUser()->getUID(),


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1658\lib/Service/[1;31mFileService.php:1658:17[0m]8;;\ - Parameter $node does not exist on function OCP\Share\IManager::getSharesBy (see https://psalm.dev/238)
                [97;41mnode[0m: $file


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1733\lib/Service/[1;31mFileService.php:1733:40[0m]8;;\ - Method OCP\Files\Storage\IStorage::chown does not exist (see https://psalm.dev/181)
                $folder->getStorage()->[97;41mchown[0m($folder->getInternalPath(), $openRegisterUserId);


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1780\lib/Service/[1;31mFileService.php:1780:21[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
            $file = [97;41m$this->rootFolder[0m->get($path);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1865\lib/Service/[1;31mFileService.php:1865:45[0m]8;;\ - Method OCA\OpenRegister\Service\FileService::getNodeTypeFromFolder does not exist (see https://psalm.dev/022)
                    'nodeType'    => $this->[97;41mgetNodeTypeFromFolder[0m($rootFolder),


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L1951\lib/Service/[1;31mFileService.php:1951:56[0m]8;;\ - Redundant cast to string (see https://psalm.dev/262)
            $pathInfo = $this->extractFileNameFromPath([97;41m(string)$filePath[0m);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2045\lib/Service/[1;31mFileService.php:2045:41[0m]8;;\ - Method OCP\Files\Node::hash does not exist (see https://psalm.dev/181)
        if ($content !== null && $file->[97;41mhash[0m(type: 'md5') !== md5(string: $content)) {


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2058\lib/Service/[1;31mFileService.php:2058:24[0m]8;;\ - Method OCP\Files\Node::putContent does not exist (see https://psalm.dev/181)
                $file->[97;41mputContent[0m(data: $content);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2072\lib/Service/[1;31mFileService.php:2072:56[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\FileService::getFileTags expects string, but int provided (see https://psalm.dev/004)
            $existingTags = $this->getFileTags(fileId: [97;41m$file->getId()[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2080\lib/Service/[1;31mFileService.php:2080:45[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\FileService::attachTagsToFile expects string, but int provided (see https://psalm.dev/004)
            $this->attachTagsToFile(fileId: [97;41m$file->getId()[0m, tags: $allTags);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2397\lib/Service/[1;31mFileService.php:2397:17[0m]8;;\ - Type non-empty-list<string> for $allTags is never falsy (see https://psalm.dev/122)
            if ([97;41mempty($allTags) === false[0m) {


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2398\lib/Service/[1;31mFileService.php:2398:49[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\FileService::attachTagsToFile expects string, but int provided (see https://psalm.dev/004)
                $this->attachTagsToFile(fileId: [97;41m$file->getId()[0m, tags: $allTags);


[0;31mERROR[0m: RedundantFunctionCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2519\lib/Service/[1;31mFileService.php:2519:20[0m]8;;\ - The call to array_values is unnecessary, list<string> is already a list (see https://psalm.dev/280)
            return [97;41marray_values[0m($tagNames);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2589\lib/Service/[1;31mFileService.php:2589:31[0m]8;;\ - Type string for $file is always string (see https://psalm.dev/122)
        if (is_int($file) || ([97;41mis_string($file)[0m && ctype_digit($file))) {


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2664\lib/Service/[1;31mFileService.php:2664:22[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
            $nodes = [97;41m$this->rootFolder[0m->getById($fileId);


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2761\lib/Service/[1;31mFileService.php:2761:56[0m]8;;\ - Redundant cast to string (see https://psalm.dev/262)
            $pathInfo = $this->extractFileNameFromPath([97;41m(string)$file[0m);


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L2883\lib/Service/[1;31mFileService.php:2883:56[0m]8;;\ - Redundant cast to string (see https://psalm.dev/262)
            $pathInfo = $this->extractFileNameFromPath([97;41m(string)$filePath[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L3173\lib/Service/[1;31mFileService.php:3173:54[0m]8;;\ - Argument 1 of OCP\Files\Folder::getById expects int, but string provided (see https://psalm.dev/004)
                        $file = $userFolder->getById([97;41m$fileId[0m);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L3284\lib/Service/[1;31mFileService.php:3284:35[0m]8;;\ - Method OCA\OpenRegister\Service\FileService::getObjectId does not exist (see https://psalm.dev/022)
            'object_id' => $this->[97;41mgetObjectId[0m($object),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L3316\lib/Service/[1;31mFileService.php:3316:37[0m]8;;\ - Method OCA\OpenRegister\Service\FileService::getFileInObjectFolderMessage does not exist (see https://psalm.dev/022)
                'message' => $this->[97;41mgetFileInObjectFolderMessage[0m($fileInObjectFolder, $fileId)


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L3544\lib/Service/[1;31mFileService.php:3544:46[0m]8;;\ - Method OCP\Files\Node::get does not exist (see https://psalm.dev/181)
            $objectFolder = $registerFolder->[97;41mget[0m($objectFolderName);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/FileService.php#L3548\lib/Service/[1;31mFileService.php:3548:46[0m]8;;\ - Method OCP\Files\Node::newFolder does not exist (see https://psalm.dev/181)
            $objectFolder = $registerFolder->[97;41mnewFolder[0m($objectFolderName);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GitHubService.php#L274\lib/Service/[1;31mGitHubService.php:274:21[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::hasResponse does not exist (see https://psalm.dev/181)
            if ($e->[97;41mhasResponse[0m()) {


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GitHubService.php#L275\lib/Service/[1;31mGitHubService.php:275:35[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getResponse does not exist (see https://psalm.dev/181)
                $statusCode = $e->[97;41mgetResponse[0m()->getStatusCode();


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GitHubService.php#L950\lib/Service/[1;31mGitHubService.php:950:21[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::hasResponse does not exist (see https://psalm.dev/181)
            if ($e->[97;41mhasResponse[0m()) {


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GitHubService.php#L951\lib/Service/[1;31mGitHubService.php:951:37[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getResponse does not exist (see https://psalm.dev/181)
                $statusCode   = $e->[97;41mgetResponse[0m()->getStatusCode();


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GitHubService.php#L952\lib/Service/[1;31mGitHubService.php:952:37[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getResponse does not exist (see https://psalm.dev/181)
                $responseBody = $e->[97;41mgetResponse[0m()->getBody()->getContents();


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GitHubService.php#L1021\lib/Service/[1;31mGitHubService.php:1021:21[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::hasResponse does not exist (see https://psalm.dev/181)
            if ($e->[97;41mhasResponse[0m() && $e->getResponse()->getStatusCode() === 404) {


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GitHubService.php#L1021\lib/Service/[1;31mGitHubService.php:1021:42[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getResponse does not exist (see https://psalm.dev/181)
            if ($e->hasResponse() && $e->[97;41mgetResponse[0m()->getStatusCode() === 404) {


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L75\lib/Service/[1;31mGuzzleSolrService.php:75:33[0m]8;;\ - $this->solrConfig with declared type 'array{autoCommit: bool, commitWithin: int, core: string, enableLogging: bool, enabled: bool, host: string, password: string, path: string, port: int, scheme: string, timeout: int, username: string}' cannot be assigned type 'array<never, never>' (see https://psalm.dev/145)
    private array $solrConfig = [97;41m[][0m;


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L197\lib/Service/[1;31mGuzzleSolrService.php:197:33[0m]8;;\ - $this->solrConfig with declared type 'array{autoCommit: bool, commitWithin: int, core: string, enableLogging: bool, enabled: bool, host: string, password: string, path: string, port: int, scheme: string, timeout: int, username: string}' cannot be assigned type 'array{enabled: false}' (see https://psalm.dev/145)
            $this->solrConfig = [97;41m['enabled' => false][0m;


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L242\lib/Service/[1;31mGuzzleSolrService.php:242:29[0m]8;;\ - $this->httpClient with declared type 'OCP\Http\Client\IClient' cannot be assigned type 'GuzzleHttp\Client' (see https://psalm.dev/145)
        $this->httpClient = [97;41mnew GuzzleClient($clientConfig)[0m;


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L634\lib/Service/[1;31mGuzzleSolrService.php:634:37[0m]8;;\ - Class, interface or enum named APCUIterator does not exist (see https://psalm.dev/019)
                    $iterator = new [97;41m\APCUIterator[0m('/^solr_availability_/');


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L693\lib/Service/[1;31mGuzzleSolrService.php:693:54[0m]8;;\ - Cannot access value on variable $solrConfig using offset value of 'useCloud', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
            if ($includeCollectionTests === true && ([97;41m$solrConfig['useCloud'][0m ?? false) === true) {


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L811\lib/Service/[1;31mGuzzleSolrService.php:811:16[0m]8;;\ - The declared return type 'bool' for OCA\OpenRegister\Service\GuzzleSolrService::ensureTenantCollection is incorrect, got 'array<array-key, mixed>|bool' (see https://psalm.dev/011)
     * @return [97;41mbool[0m True if collection exists or was created


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L819\lib/Service/[1;31mGuzzleSolrService.php:819:33[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'collection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
        $baseCollectionName   = [97;41m$this->solrConfig['collection'][0m ?? $this->solrConfig['core'] ?? 'openregister';


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L851\lib/Service/[1;31mGuzzleSolrService.php:851:22[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'configSet', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin', 'enableLogging' or 'collection' (see https://psalm.dev/115)
        $configSet = [97;41m$this->solrConfig['configSet'][0m ?? 'openregister';


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L852\lib/Service/[1;31mGuzzleSolrService.php:852:16[0m]8;;\ - The inferred type 'array<array-key, mixed>' does not match the declared return type 'bool' for OCA\OpenRegister\Service\GuzzleSolrService::ensureTenantCollection (see https://psalm.dev/128)
        return [97;41m$this->createCollection($tenantCollectionName, $configSet)[0m;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L868\lib/Service/[1;31mGuzzleSolrService.php:868:31[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'objectCollection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
        $baseCollectionName = [97;41m$this->solrConfig['objectCollection'][0m ?? null;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L872\lib/Service/[1;31mGuzzleSolrService.php:872:35[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'collection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin', 'enableLogging' or 'objectCollection' (see https://psalm.dev/115)
            $baseCollectionName = [97;41m$this->solrConfig['collection'][0m ?? 'openregister';


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L1165\lib/Service/[1;31mGuzzleSolrService.php:1165:62[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getSelfRelationsType does not exist (see https://psalm.dev/022)
                            'self_relations_type'  => $this->[97;41mgetSelfRelationsType[0m($document),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L1617\lib/Service/[1;31mGuzzleSolrService.php:1617:45[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getSelfObjectJson does not exist (see https://psalm.dev/022)
            'self_object'         => $this->[97;41mgetSelfObjectJson[0m($object),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L2315\lib/Service/[1;31mGuzzleSolrService.php:2315:37[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getConfigStatus does not exist (see https://psalm.dev/022)
                'host'    => $this->[97;41mgetConfigStatus[0m('host'),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L2316\lib/Service/[1;31mGuzzleSolrService.php:2316:37[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getPortStatus does not exist (see https://psalm.dev/022)
                'port'    => $this->[97;41mgetPortStatus[0m(),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L2317\lib/Service/[1;31mGuzzleSolrService.php:2317:37[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getCoreStatus does not exist (see https://psalm.dev/022)
                'core'    => $this->[97;41mgetCoreStatus[0m(),


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L2329\lib/Service/[1;31mGuzzleSolrService.php:2329:76[0m]8;;\ - Cannot access value on variable $connectionTest using offset value of 'error', expecting 'success', 'message', 'details' or 'components' (see https://psalm.dev/115)
                'SOLR service is not available. Connection test failed: '.([97;41m$connectionTest['error'][0m ?? 'Unknown connection error').'. Please verify that SOLR is running and accessible at the configured URL.'


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L2847\lib/Service/[1;31mGuzzleSolrService.php:2847:13[0m]8;;\ - Type array<array-key, mixed> for $order is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41mis_array($order)[0m === true) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L3472\lib/Service/[1;31mGuzzleSolrService.php:3472:17[0m]8;;\ - Type non-empty-list<array<array-key, mixed>> for $solrDocs is never falsy (see https://psalm.dev/122)
            if ([97;41mempty($solrDocs) === false[0m) {


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L4367\lib/Service/[1;31mGuzzleSolrService.php:4367:51[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $responseBody = $response->getBody()->[97;41mgetContents[0m();


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L4782\lib/Service/[1;31mGuzzleSolrService.php:4782:31[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'zookeeperHosts', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
            $zookeeperHosts = [97;41m$this->solrConfig['zookeeperHosts'][0m ?? 'zookeeper:2181';


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L4982\lib/Service/[1;31mGuzzleSolrService.php:4982:18[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'useCloud', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
            if (([97;41m$this->solrConfig['useCloud'][0m ?? false) === true) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L5117\lib/Service/[1;31mGuzzleSolrService.php:5117:23[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'collection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
            if (isset([97;41m$this->solrConfig['collection'][0m) === true) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L5215\lib/Service/[1;31mGuzzleSolrService.php:5215:23[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'collection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
            if (isset([97;41m$this->solrConfig['collection'][0m) === true) {


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L5290\lib/Service/[1;31mGuzzleSolrService.php:5290:63[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $responseData = json_decode($response->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L5615\lib/Service/[1;31mGuzzleSolrService.php:5615:33[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'objectCollection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin' or 'enableLogging' (see https://psalm.dev/115)
            $objectCollection = [97;41m$this->solrConfig['objectCollection'][0m ?? null;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L5616\lib/Service/[1;31mGuzzleSolrService.php:5616:33[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'fileCollection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin', 'enableLogging' or 'objectCollection' (see https://psalm.dev/115)
            $fileCollection   = [97;41m$this->solrConfig['fileCollection'][0m ?? null;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L5619\lib/Service/[1;31mGuzzleSolrService.php:5619:23[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'collection', expecting 'enabled', 'host', 'port', 'path', 'core', 'scheme', 'username', 'password', 'timeout', 'autoCommit', 'commitWithin', 'enableLogging', 'objectCollection' or 'fileCollection' (see https://psalm.dev/115)
            if (isset([97;41m$this->solrConfig['collection'][0m) === true) {


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L6062\lib/Service/[1;31mGuzzleSolrService.php:6062:16[0m]8;;\ - The declared return type 'GuzzleHttp\Client' for OCA\OpenRegister\Service\GuzzleSolrService::getHttpClient is incorrect, got 'OCP\Http\Client\IClient' (see https://psalm.dev/011)
     * @return [97;41mGuzzleClient[0m The configured and authenticated HTTP client


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L6066\lib/Service/[1;31mGuzzleSolrService.php:6066:16[0m]8;;\ - The inferred type 'OCP\Http\Client\IClient' does not match the declared return type 'GuzzleHttp\Client' for OCA\OpenRegister\Service\GuzzleSolrService::getHttpClient (see https://psalm.dev/128)
        return [97;41m$this->httpClient[0m;


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L6223\lib/Service/[1;31mGuzzleSolrService.php:6223:13[0m]8;;\ - array<array-key, mixed> does not contain null (see https://psalm.dev/090)
        if ([97;41m$schemaIds === null[0m) {


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L6456\lib/Service/[1;31mGuzzleSolrService.php:6456:13[0m]8;;\ - array<array-key, mixed> does not contain null (see https://psalm.dev/090)
        if ([97;41m$schemaIds === null[0m) {


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L6606\lib/Service/[1;31mGuzzleSolrService.php:6606:16[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Service\ObjectEntityMapper does not exist (see https://psalm.dev/200)
     * @param  [97;41mObjectEntityMapper[0m $objectMapper Object mapper instance


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L6798\lib/Service/[1;31mGuzzleSolrService.php:6798:50[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
                                $entity->hydrate([97;41m$object[0m);


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L7028\lib/Service/[1;31mGuzzleSolrService.php:7028:15[0m]8;;\ - Docblock-defined class, interface or enum named OCA\OpenRegister\Service\ObjectEntityMapper does not exist (see https://psalm.dev/200)
     * @param [97;41mObjectEntityMapper[0m $objectMapper Object mapper for database operations


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L7248\lib/Service/[1;31mGuzzleSolrService.php:7248:13[0m]8;;\ - array<array-key, mixed> does not contain null (see https://psalm.dev/090)
        if ([97;41m$schemaIds === null[0m) {


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L7272\lib/Service/[1;31mGuzzleSolrService.php:7272:31[0m]8;;\ - Redundant cast to int (see https://psalm.dev/262)
        $initialMemoryUsage = [97;41m(int) memory_get_usage(true)[0m;


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L7273\lib/Service/[1;31mGuzzleSolrService.php:7273:31[0m]8;;\ - Redundant cast to int (see https://psalm.dev/262)
        $initialMemoryPeak  = [97;41m(int) memory_get_peak_usage(true)[0m;


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L7692\lib/Service/[1;31mGuzzleSolrService.php:7692:13[0m]8;;\ - array<array-key, mixed> does not contain null (see https://psalm.dev/090)
        if ([97;41m$schemaIds === null[0m) {


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L7971\lib/Service/[1;31mGuzzleSolrService.php:7971:75[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
                        $responseData = json_decode($response->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L8720\lib/Service/[1;31mGuzzleSolrService.php:8720:16[0m]8;;\ - The declared return type 'array{core_info?: array<array-key, mixed>, dynamic_fields?: array<array-key, mixed>, environment_notes?: array<array-key, mixed>, field_types?: array<array-key, mixed>, fields?: array<array-key, mixed>, message: string, success: bool}' for OCA\OpenRegister\Service\GuzzleSolrService::getFieldsConfiguration is incorrect, got 'array{core_info?: array<array-key, mixed>, details?: array{error?: string, response?: mixed}, dynamic_fields?: array<array-key, mixed>, environment_notes?: array<array-key, mixed>, execution_time_ms?: float, field_types?: array<array-key, mixed>, fields?: array<array-key, mixed>, message: non-falsy-string, success: bool}' which is different due to additional array shape fields (details, execution_time_ms) (see https://psalm.dev/011)
     * @return [97;41marray{success: bool, message: string, fields?: array, dynamic_fields?: array, field_types?: array, core_info?: array, environment_notes?: array}[0m


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L8768\lib/Service/[1;31mGuzzleSolrService.php:8768:61[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $schemaData = json_decode($response->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L8771\lib/Service/[1;31mGuzzleSolrService.php:8771:24[0m]8;;\ - The inferred type 'array{details: array{response: mixed}, message: 'Invalid schema response from SOLR', success: false}' does not match the declared return type 'array{core_info?: array<array-key, mixed>, dynamic_fields?: array<array-key, mixed>, environment_notes?: array<array-key, mixed>, field_types?: array<array-key, mixed>, fields?: array<array-key, mixed>, message: string, success: bool}' for OCA\OpenRegister\Service\GuzzleSolrService::getFieldsConfiguration due to additional array shape fields (details) (see https://psalm.dev/128)
                return [97;41m[
                    'success' => false,
                    'message' => 'Invalid schema response from SOLR',
                    'details' => ['response' => $schemaData],
                ][0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L8804\lib/Service/[1;31mGuzzleSolrService.php:8804:20[0m]8;;\ - The inferred type 'array{core_info: array<array-key, mixed>, dynamic_fields: array<array-key, mixed>, environment_notes: array<array-key, mixed>, execution_time_ms: float, field_types: array<array-key, mixed>, fields: array<array-key, mixed>, message: 'SOLR field configuration retrieved successfully', success: true}' does not match the declared return type 'array{core_info?: array<array-key, mixed>, dynamic_fields?: array<array-key, mixed>, environment_notes?: array<array-key, mixed>, field_types?: array<array-key, mixed>, fields?: array<array-key, mixed>, message: string, success: bool}' for OCA\OpenRegister\Service\GuzzleSolrService::getFieldsConfiguration due to additional array shape fields (execution_time_ms) (see https://psalm.dev/128)
            return [97;41m$result[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L8814\lib/Service/[1;31mGuzzleSolrService.php:8814:20[0m]8;;\ - The inferred type 'array{details: array{error: string}, message: non-falsy-string, success: false}' does not match the declared return type 'array{core_info?: array<array-key, mixed>, dynamic_fields?: array<array-key, mixed>, environment_notes?: array<array-key, mixed>, field_types?: array<array-key, mixed>, fields?: array<array-key, mixed>, message: string, success: bool}' for OCA\OpenRegister\Service\GuzzleSolrService::getFieldsConfiguration due to additional array shape fields (details) (see https://psalm.dev/128)
            return [97;41m[
                'success' => false,
                'message' => 'Failed to retrieve SOLR field configuration: '.$e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ][0m;


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L8994\lib/Service/[1;31mGuzzleSolrService.php:8994:30[0m]8;;\ - Redundant cast to int (see https://psalm.dev/262)
            $currentMemory = [97;41m(int) memory_get_usage(true)[0m;


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L9106\lib/Service/[1;31mGuzzleSolrService.php:9106:42[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::calculatePredictionAccuracy does not exist (see https://psalm.dev/022)
            $predictionAccuracy = $this->[97;41mcalculatePredictionAccuracy[0m($prediction['estimated_additional'], $actualUsed);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L9253\lib/Service/[1;31mGuzzleSolrService.php:9253:13[0m]8;;\ - Type string for $fieldName is always string (see https://psalm.dev/122)
        if ([97;41mis_string($fieldName)[0m === true && str_contains(strtolower($fieldName), 'base64') === true) {


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L9293\lib/Service/[1;31mGuzzleSolrService.php:9293:61[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $schemaData = json_decode($response->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L9418\lib/Service/[1;31mGuzzleSolrService.php:9418:61[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $schemaData = json_decode($response->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L9619\lib/Service/[1;31mGuzzleSolrService.php:9619:51[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $responseBody = $response->getBody()->[97;41mgetContents[0m();


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L9798\lib/Service/[1;31mGuzzleSolrService.php:9798:27[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getNumericType does not exist (see https://psalm.dev/022)
            return $this->[97;41mgetNumericType[0m($sampleValue);


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L9919\lib/Service/[1;31mGuzzleSolrService.php:9919:51[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $responseBody = $response->getBody()->[97;41mgetContents[0m();


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L10134\lib/Service/[1;31mGuzzleSolrService.php:10134:37[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getFieldNameFromFacetKey does not exist (see https://psalm.dev/022)
                $fieldName = $this->[97;41mgetFieldNameFromFacetKey[0m($facetKey);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L10161\lib/Service/[1;31mGuzzleSolrService.php:10161:35[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getFacetConfigKey does not exist (see https://psalm.dev/022)
            $configKey   = $this->[97;41mgetFacetConfigKey[0m($isMetadataField, $facetKey, $fieldName);


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L10345\lib/Service/[1;31mGuzzleSolrService.php:10345:51[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $responseBody = $response->getBody()->[97;41mgetContents[0m();


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L10365\lib/Service/[1;31mGuzzleSolrService.php:10365:55[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getFacetKeys does not exist (see https://psalm.dev/022)
                        'facet_keys'        => $this->[97;41mgetFacetKeys[0m($data),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L10366\lib/Service/[1;31mGuzzleSolrService.php:10366:55[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getObjectFacetKeys does not exist (see https://psalm.dev/022)
                        'object_facet_keys' => $this->[97;41mgetObjectFacetKeys[0m($data),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L11587\lib/Service/[1;31mGuzzleSolrService.php:11587:51[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getCollectionHealth does not exist (see https://psalm.dev/022)
                    'health'            => $this->[97;41mgetCollectionHealth[0m($allActive),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L11588\lib/Service/[1;31mGuzzleSolrService.php:11588:51[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getCollectionStatus does not exist (see https://psalm.dev/022)
                    'status'            => $this->[97;41mgetCollectionStatus[0m($allActive),


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L11966\lib/Service/[1;31mGuzzleSolrService.php:11966:59[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $result   = json_decode($response->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L12163\lib/Service/[1;31mGuzzleSolrService.php:12163:59[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $result   = json_decode($response->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: InvalidMethodCall - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/GuzzleSolrService.php#L12178\lib/Service/[1;31mGuzzleSolrService.php:12178:61[0m]8;;\ - Cannot call method on string variable  (see https://psalm.dev/091)
            $result2   = json_decode($response2->getBody()->[97;41mgetContents[0m(), true);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L228\lib/Service/[1;31mImportService.php:228:8[0m]8;;\ - Class, interface or enum named React\Async\PromiseInterface does not exist (see https://psalm.dev/019)
    ): [97;41mPromiseInterface[0m {


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L318\lib/Service/[1;31mImportService.php:318:8[0m]8;;\ - Class, interface or enum named React\Async\PromiseInterface does not exist (see https://psalm.dev/019)
    ): [97;41mPromiseInterface[0m {


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L346\lib/Service/[1;31mImportService.php:346:24[0m]8;;\ - The declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::importFromCsv is incorrect, got 'non-empty-array<string, array{schema: array{id: int, slug: null|string, title: null|string}, ...<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>}>}>' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray<string, array{created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L402\lib/Service/[1;31mImportService.php:402:16[0m]8;;\ - The inferred type 'non-empty-array<string, array{schema: array{id: int, slug: null|string, title: null|string}, ...<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>}>}>' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::importFromCsv (see https://psalm.dev/128)
        return [97;41m$finalResult[0m;


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L419\lib/Service/[1;31mImportService.php:419:24[0m]8;;\ - The declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processMultiSchemaSpreadsheetAsync is incorrect, got 'array<string, array{created: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, debug: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, headers: array<never, never>, processableHeaders: array<never, never>, schemaProperties: list<array-key>, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, errors: array{0?: array{error: non-falsy-string, register: array{id: int, name: null|string}, schema: null, sheet: string, type: 'SchemaNotFoundException'}, created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>, ...<int<0, max>, array{error: non-falsy-string, register: array{id: int, name: null|string}, schema: null, sheet: string, type: 'SchemaNotFoundException'}>}, found: 0|array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}, schema: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, id: int, slug: null|string, title: null|string, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}|null, unchanged: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, updated: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, ...<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>}>' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L482\lib/Service/[1;31mImportService.php:482:16[0m]8;;\ - The inferred type 'array<string, array{created: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, debug: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, headers: array<never, never>, processableHeaders: array<never, never>, schemaProperties: list<array-key>, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, errors: array{0?: array{error: non-falsy-string, register: array{id: int, name: null|string}, schema: null, sheet: string, type: 'SchemaNotFoundException'}, created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>, ...<int<0, max>, array{error: non-falsy-string, register: array{id: int, name: null|string}, schema: null, sheet: string, type: 'SchemaNotFoundException'}>}, found: 0|array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}, schema: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, id: int, slug: null|string, title: null|string, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}|null, unchanged: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, updated: array{created?: array<array-key, mixed>, errors?: array<array-key, mixed>, found?: int, unchanged?: array<array-key, mixed>, updated?: array<array-key, mixed>}, ...<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>}>' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processMultiSchemaSpreadsheetAsync (see https://psalm.dev/128)
        return [97;41m$summary[0m;


[0;31mERROR[0m: InvalidOperand - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L534\lib/Service/[1;31mImportService.php:534:13[0m]8;;\ - Cannot add an array to a non-array int (see https://psalm.dev/058)
            [97;41m$summary['found'][0m    += $chunkSummary['found'];


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L575\lib/Service/[1;31mImportService.php:575:24[0m]8;;\ - The declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processSpreadsheetBatch is incorrect, got 'array{created: array<never, mixed|null>, deduplication_efficiency?: non-empty-lowercase-string, errors: list<array{error: 'No data rows found in sheet'|'No valid headers found in sheet'|'Validation failed'|mixed, object: array<never, never>|mixed, row?: 1, sheet: string, type?: 'ValidationException'|mixed}>, found: int<0, max>, unchanged: array<never, mixed|null>, updated: array<never, mixed|null>}' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray<string, array{found: int, created: array<mixed>, updated: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>[0m


[0;31mERROR[0m: DuplicateArrayKey - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L594\lib/Service/[1;31mImportService.php:594:13[0m]8;;\ - Key 'unchanged' already exists on array (see https://psalm.dev/151)
            [97;41m'unchanged' => [][0m,


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L616\lib/Service/[1;31mImportService.php:616:20[0m]8;;\ - The inferred type 'array{created: array<never, never>, errors: non-empty-list<array{error: 'No valid headers found in sheet', object: array<never, never>, row: 1, sheet: string}>, found: 0, unchanged: array<never, never>, updated: array<never, never>}' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processSpreadsheetBatch (see https://psalm.dev/128)
            return [97;41m$summary[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L629\lib/Service/[1;31mImportService.php:629:20[0m]8;;\ - The inferred type 'array{created: array<never, never>, errors: non-empty-list<array{error: 'No data rows found in sheet', object: array<never, never>, row: 1, sheet: string}>, found: 0, unchanged: array<never, never>, updated: array<never, never>}' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processSpreadsheetBatch (see https://psalm.dev/128)
            return [97;41m$summary[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L696\lib/Service/[1;31mImportService.php:696:16[0m]8;;\ - The inferred type 'array{created: array<never, mixed|null>, deduplication_efficiency?: non-empty-lowercase-string, errors: list<array{error: 'Validation failed'|mixed, object: mixed, sheet: string, type: 'ValidationException'|mixed}>, found: int<0, max>, unchanged: array<never, mixed|null>, updated: array<never, mixed|null>}' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>, updated: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processSpreadsheetBatch (see https://psalm.dev/128)
        return [97;41m$summary[0m;


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L713\lib/Service/[1;31mImportService.php:713:24[0m]8;;\ - The declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processCsvSheet is incorrect, got 'array{created: array<never, mixed|null>, deduplication_efficiency?: non-empty-lowercase-string, errors: list<array{error: 'No data rows found in CSV file'|'No valid headers found in CSV file'|'Validation failed'|mixed, object: array<never, never>|mixed, row?: 1, type?: 'ValidationException'|mixed}>, found: int<0, max>, performance?: array{efficiency: 0|float, objectsPerSecond: float, totalFound: int<0, max>, totalProcessed: int<0, max>, totalTime: float, totalTimeMs: float}, unchanged: array<never, mixed|null>, updated: array<never, mixed|null>}' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray<string, array{found: int, created: array<mixed>, unchanged: array<mixed>, errors: array<mixed>}>[0m


[0;31mERROR[0m: DuplicateArrayKey - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L722\lib/Service/[1;31mImportService.php:722:13[0m]8;;\ - Key 'unchanged' already exists on array (see https://psalm.dev/151)
            [97;41m'unchanged' => [][0m,


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L739\lib/Service/[1;31mImportService.php:739:20[0m]8;;\ - The inferred type 'array{created: array<never, never>, errors: non-empty-list<array{error: 'No valid headers found in CSV file', object: array<never, never>, row: 1}>, found: 0, unchanged: array<never, never>, updated: array<never, never>}' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processCsvSheet (see https://psalm.dev/128)
            return [97;41m$summary[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L751\lib/Service/[1;31mImportService.php:751:20[0m]8;;\ - The inferred type 'array{created: array<never, never>, errors: non-empty-list<array{error: 'No data rows found in CSV file', object: array<never, never>, row: 1}>, found: 0, unchanged: array<never, never>, updated: array<never, never>}' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processCsvSheet (see https://psalm.dev/128)
            return [97;41m$summary[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L847\lib/Service/[1;31mImportService.php:847:16[0m]8;;\ - The inferred type 'array{created: array<never, mixed|null>, deduplication_efficiency?: non-empty-lowercase-string, errors: list<array{error: 'Validation failed'|mixed, object: mixed, type: 'ValidationException'|mixed}>, found: int<0, max>, performance: array{efficiency: 0|float, objectsPerSecond: float, totalFound: int<0, max>, totalProcessed: int<0, max>, totalTime: float, totalTimeMs: float}, unchanged: array<never, mixed|null>, updated: array<never, mixed|null>}' does not match the declared return type 'array<string, array{created: array<array-key, mixed>, errors: array<array-key, mixed>, found: int, unchanged: array<array-key, mixed>}>' for OCA\OpenRegister\Service\ImportService::processCsvSheet (see https://psalm.dev/128)
        return [97;41m$summary[0m;


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L951\lib/Service/[1;31mImportService.php:951:30[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but array{created: array<never, mixed|null>, errors: array<int, array<string, mixed>>, found: int<0, max>, updated: array<never, mixed|null>} provided (see https://psalm.dev/004)
                    $resolve([97;41m$result[0m);


[0;31mERROR[0m: UndefinedFunction - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L957\lib/Service/[1;31mImportService.php:957:29[0m]8;;\ - Function React\Async\await does not exist (see https://psalm.dev/021)
            $batchResults = [97;41m\React\Async\await(\React\Promise\all($promises))[0m;


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L989\lib/Service/[1;31mImportService.php:989:24[0m]8;;\ - The declared return type 'array{errors: array<int, array<string, mixed>>, objects: array<int, array<string, mixed>>}' for OCA\OpenRegister\Service\ImportService::processCsvChunk is incorrect, got 'array{objects: list<array<string, mixed>>}' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray{objects: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1038\lib/Service/[1;31mImportService.php:1038:16[0m]8;;\ - The inferred type 'array{objects: list<array<string, mixed>>}' does not match the declared return type 'array{errors: array<int, array<string, mixed>>, objects: array<int, array<string, mixed>>}' for OCA\OpenRegister\Service\ImportService::processCsvChunk (see https://psalm.dev/128)
        return [97;41m[
            'objects' => $objects,
        ][0m;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1060\lib/Service/[1;31mImportService.php:1060:20[0m]8;;\ - Cannot access value on variable $this->schemaPropertiesCache using a int offset, expecting string (see https://psalm.dev/115)
        if (!isset([97;41m$this->schemaPropertiesCache[$schemaId][0m)) {


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1061\lib/Service/[1;31mImportService.php:1061:13[0m]8;;\ - $this->schemaPropertiesCache with declared type 'array<string, array<array-key, mixed>>' cannot be assigned type 'non-empty-array<int|string, array<array-key, mixed>|null>' (see https://psalm.dev/145)
            [97;41m$this->schemaPropertiesCache[0m[$schemaId] = $schema->getProperties();


[0;31mERROR[0m: InvalidArrayAccess - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1081\lib/Service/[1;31mImportService.php:1081:26[0m]8;;\ - Cannot access array value on non-array variable $key of type array-key (see https://psalm.dev/005)
            $firstChar = [97;41m$key[0][0m ?? '';


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1083\lib/Service/[1;31mImportService.php:1083:17[0m]8;;\ - '_' cannot be identical to '' (see https://psalm.dev/056)
            if ([97;41m$firstChar === '_'[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1083\lib/Service/[1;31mImportService.php:1083:17[0m]8;;\ - Type '' for $firstChar is never =string(_) (see https://psalm.dev/056)
            if ([97;41m$firstChar === '_'[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1086\lib/Service/[1;31mImportService.php:1086:24[0m]8;;\ - '@' cannot be identical to '' (see https://psalm.dev/056)
            } else if ([97;41m$firstChar === '@'[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1086\lib/Service/[1;31mImportService.php:1086:24[0m]8;;\ - Type '' for $firstChar is never =string(@) (see https://psalm.dev/056)
            } else if ([97;41m$firstChar === '@'[0m) {


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1119\lib/Service/[1;31mImportService.php:1119:54[0m]8;;\ - Argument 2 of OCA\OpenRegister\Service\ImportService::validateObjectProperties expects string, but int provided (see https://psalm.dev/004)
        $this->validateObjectProperties($objectData, [97;41m$schemaId[0m);


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1209\lib/Service/[1;31mImportService.php:1209:24[0m]8;;\ - The declared return type 'array{errors: array<int, array<string, mixed>>, objects: array<int, array<string, mixed>>}' for OCA\OpenRegister\Service\ImportService::processExcelChunk is incorrect, got 'array{objects: list<array<string, mixed>>}' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray{objects: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1238\lib/Service/[1;31mImportService.php:1238:16[0m]8;;\ - The inferred type 'array{objects: list<array<string, mixed>>}' does not match the declared return type 'array{errors: array<int, array<string, mixed>>, objects: array<int, array<string, mixed>>}' for OCA\OpenRegister\Service\ImportService::processExcelChunk (see https://psalm.dev/128)
        return [97;41m[
            'objects' => $objects,
        ][0m;


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1343\lib/Service/[1;31mImportService.php:1343:41[0m]8;;\ - Redundant cast to string (see https://psalm.dev/262)
                $cleanColumnName = trim([97;41m(string) $cellValue[0m);


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1369\lib/Service/[1;31mImportService.php:1369:16[0m]8;;\ - The declared return type 'array<string, array<array-key, mixed>>' for OCA\OpenRegister\Service\ImportService::processChunk is incorrect, got 'array{created: list<mixed>, errors: list<mixed>, found: int<0, max>, unchanged: array<never, never>, updated: list<mixed>}' (see https://psalm.dev/011)
     * @return [97;41marray<string, array>[0m Chunk processing summary


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1408\lib/Service/[1;31mImportService.php:1408:38[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but array<string, mixed> provided (see https://psalm.dev/004)
                            $resolve([97;41m$result[0m);


[0;31mERROR[0m: UndefinedFunction - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1417\lib/Service/[1;31mImportService.php:1417:28[0m]8;;\ - Function React\Async\await does not exist (see https://psalm.dev/021)
                $results = [97;41m\React\Async\await(\React\Promise\all($batch))[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1433\lib/Service/[1;31mImportService.php:1433:16[0m]8;;\ - The inferred type 'array{created: list<mixed>, errors: list<mixed>, found: int<0, max>, unchanged: array<never, never>, updated: list<mixed>}' does not match the declared return type 'array<string, array<array-key, mixed>>' for OCA\OpenRegister\Service\ImportService::processChunk (see https://psalm.dev/128)
        return [97;41m$chunkSummary[0m;


[0;31mERROR[0m: TooManyArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ImportService.php#L1949\lib/Service/[1;31mImportService.php:1949:29[0m]8;;\ - Too many arguments for method OCP\BackgroundJob\IJobList::add - saw 3 (see https://psalm.dev/026)
            $this->jobList->[97;41madd[0m(SolrWarmupJob::class, $jobArguments, $executeAfter);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L342\lib/Service/[1;31mMagicMapper.php:342:33[0m]8;;\ - Operand of type true is always truthy (see https://psalm.dev/122)
            if ($tableExists && [97;41m$force[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L505\lib/Service/[1;31mMagicMapper.php:505:21[0m]8;;\ - string can never contain null (see https://psalm.dev/122)
                if ([97;41m$uuid !== null[0m && $uuid !== '') {


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L603\lib/Service/[1;31mMagicMapper.php:603:41[0m]8;;\ - Method OCP\IDBConnection::getSchemaManager does not exist (see https://psalm.dev/181)
            $schemaManager = $this->db->[97;41mgetSchemaManager[0m();


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L798\lib/Service/[1;31mMagicMapper.php:798:21[0m]8;;\ - Type array{default?: mixed, index?: bool, length?: int, name: string, nullable: bool, type: string, unique?: bool} for $column is never =string() (see https://psalm.dev/122)
                if ([97;41m$column !== null && $column !== ''[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L798\lib/Service/[1;31mMagicMapper.php:798:41[0m]8;;\ - '' can never contain array{default?: mixed, index?: bool, length?: int, name: string, nullable: bool, type: string, unique?: bool} (see https://psalm.dev/122)
                if ($column !== null && [97;41m$column !== ''[0m) {


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L814\lib/Service/[1;31mMagicMapper.php:814:22[0m]8;;\ - The declared return type 'array<string, array{default?: mixed, index?: bool, length?: int, name: string, nullable: bool, type: string, unique?: bool}>' for OCA\OpenRegister\Service\MagicMapper::getMetadataColumns is incorrect, got 'array{_application: array{length: 255, name: '_application', nullable: true, type: 'string'}, _authorization: array{name: '_authorization', nullable: true, type: 'json'}, _created: array{index: true, name: '_created', nullable: true, type: 'datetime'}, _deleted: array{name: '_deleted', nullable: true, type: 'json'}, _depublished: array{index: true, name: '_depublished', nullable: true, type: 'datetime'}, _description: array{name: '_description', nullable: true, type: 'text'}, _expires: array{index: true, name: '_expires', nullable: true, type: 'datetime'}, _files: array{name: '_files', nullable: true, type: 'json'}, _folder: array{length: 255, name: '_folder', nullable: true, type: 'string'}, _geo: array{name: '_geo', nullable: true, type: 'json'}, _groups: array{name: '_groups', nullable: true, type: 'json'}, _id: array{autoincrement: true, name: '_id', nullable: false, primary: true, type: 'bigint'}, _image: array{name: '_image', nullable: true, type: 'text'}, _locked: array{name: '_locked', nullable: true, type: 'json'}, _name: array{index: true, length: 255, name: '_name', nullable: true, type: 'string'}, _organisation: array{index: true, length: 36, name: '_organisation', nullable: true, type: 'string'}, _owner: array{index: true, length: 64, name: '_owner', nullable: true, type: 'string'}, _published: array{index: true, name: '_published', nullable: true, type: 'datetime'}, _register: array{index: true, length: 255, name: '_register', nullable: false, type: 'string'}, _relations: array{name: '_relations', nullable: true, type: 'json'}, _retention: array{name: '_retention', nullable: true, type: 'json'}, _schema: array{index: true, length: 255, name: '_schema', nullable: false, type: 'string'}, _schema_version: array{length: 50, name: '_schema_version', nullable: true, type: 'string'}, _size: array{length: 50, name: '_size', nullable: true, type: 'string'}, _slug: array{index: true, length: 255, name: '_slug', nullable: true, type: 'string'}, _summary: array{name: '_summary', nullable: true, type: 'text'}, _updated: array{index: true, name: '_updated', nullable: true, type: 'datetime'}, _uri: array{name: '_uri', nullable: true, type: 'text'}, _uuid: array{index: true, length: 36, name: '_uuid', nullable: false, type: 'string', unique: true}, _validation: array{name: '_validation', nullable: true, type: 'json'}, _version: array{length: 50, name: '_version', nullable: true, type: 'string'}}' (see https://psalm.dev/011)
     * @psalm-return [97;41marray<string, TableColumnConfig>[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L818\lib/Service/[1;31mMagicMapper.php:818:16[0m]8;;\ - The inferred type 'array{_application: array{length: 255, name: '_application', nullable: true, type: 'string'}, _authorization: array{name: '_authorization', nullable: true, type: 'json'}, _created: array{index: true, name: '_created', nullable: true, type: 'datetime'}, _deleted: array{name: '_deleted', nullable: true, type: 'json'}, _depublished: array{index: true, name: '_depublished', nullable: true, type: 'datetime'}, _description: array{name: '_description', nullable: true, type: 'text'}, _expires: array{index: true, name: '_expires', nullable: true, type: 'datetime'}, _files: array{name: '_files', nullable: true, type: 'json'}, _folder: array{length: 255, name: '_folder', nullable: true, type: 'string'}, _geo: array{name: '_geo', nullable: true, type: 'json'}, _groups: array{name: '_groups', nullable: true, type: 'json'}, _id: array{autoincrement: true, name: '_id', nullable: false, primary: true, type: 'bigint'}, _image: array{name: '_image', nullable: true, type: 'text'}, _locked: array{name: '_locked', nullable: true, type: 'json'}, _name: array{index: true, length: 255, name: '_name', nullable: true, type: 'string'}, _organisation: array{index: true, length: 36, name: '_organisation', nullable: true, type: 'string'}, _owner: array{index: true, length: 64, name: '_owner', nullable: true, type: 'string'}, _published: array{index: true, name: '_published', nullable: true, type: 'datetime'}, _register: array{index: true, length: 255, name: '_register', nullable: false, type: 'string'}, _relations: array{name: '_relations', nullable: true, type: 'json'}, _retention: array{name: '_retention', nullable: true, type: 'json'}, _schema: array{index: true, length: 255, name: '_schema', nullable: false, type: 'string'}, _schema_version: array{length: 50, name: '_schema_version', nullable: true, type: 'string'}, _size: array{length: 50, name: '_size', nullable: true, type: 'string'}, _slug: array{index: true, length: 255, name: '_slug', nullable: true, type: 'string'}, _summary: array{name: '_summary', nullable: true, type: 'text'}, _updated: array{index: true, name: '_updated', nullable: true, type: 'datetime'}, _uri: array{name: '_uri', nullable: true, type: 'text'}, _uuid: array{index: true, length: 36, name: '_uuid', nullable: false, type: 'string', unique: true}, _validation: array{name: '_validation', nullable: true, type: 'json'}, _version: array{length: 50, name: '_version', nullable: true, type: 'string'}}' does not match the declared return type 'array<string, array{default?: mixed, index?: bool, length?: int, name: string, nullable: bool, type: string, unique?: bool}>' for OCA\OpenRegister\Service\MagicMapper::getMetadataColumns (see https://psalm.dev/128)
        return [97;41m[
            self::METADATA_PREFIX.'id'             => [
                'name'          => self::METADATA_PREFIX.'id',
                'type'          => 'bigint',
                'nullable'      => false,
                'autoincrement' => true,
                'primary'       => true,
            ],
            self::METADATA_PREFIX.'uuid'           => [
                'name'     => self::METADATA_PREFIX.'uuid',
                'type'     => 'string',
                'length'   => 36,
                'nullable' => false,
                'unique'   => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'slug'           => [
                'name'     => self::METADATA_PREFIX.'slug',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'uri'            => [
                'name'     => self::METADATA_PREFIX.'uri',
                'type'     => 'text',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'version'        => [
                'name'     => self::METADATA_PREFIX.'version',
                'type'     => 'string',
                'length'   => 50,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'register'       => [
                'name'     => self::METADATA_PREFIX.'register',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => false,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'schema'         => [
                'name'     => self::METADATA_PREFIX.'schema',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => false,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'owner'          => [
                'name'     => self::METADATA_PREFIX.'owner',
                'type'     => 'string',
                'length'   => 64,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'organisation'   => [
                'name'     => self::METADATA_PREFIX.'organisation',
                'type'     => 'string',
                'length'   => 36,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'application'    => [
                'name'     => self::METADATA_PREFIX.'application',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'folder'         => [
                'name'     => self::METADATA_PREFIX.'folder',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'name'           => [
                'name'     => self::METADATA_PREFIX.'name',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'description'    => [
                'name'     => self::METADATA_PREFIX.'description',
                'type'     => 'text',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'summary'        => [
                'name'     => self::METADATA_PREFIX.'summary',
                'type'     => 'text',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'image'          => [
                'name'     => self::METADATA_PREFIX.'image',
                'type'     => 'text',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'size'           => [
                'name'     => self::METADATA_PREFIX.'size',
                'type'     => 'string',
                'length'   => 50,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'schema_version' => [
                'name'     => self::METADATA_PREFIX.'schema_version',
                'type'     => 'string',
                'length'   => 50,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'created'        => [
                'name'     => self::METADATA_PREFIX.'created',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'updated'        => [
                'name'     => self::METADATA_PREFIX.'updated',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'published'      => [
                'name'     => self::METADATA_PREFIX.'published',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'depublished'    => [
                'name'     => self::METADATA_PREFIX.'depublished',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'expires'        => [
                'name'     => self::METADATA_PREFIX.'expires',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            // JSON columns for complex data.
            self::METADATA_PREFIX.'files'          => [
                'name'     => self::METADATA_PREFIX.'files',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'relations'      => [
                'name'     => self::METADATA_PREFIX.'relations',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'locked'         => [
                'name'     => self::METADATA_PREFIX.'locked',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'authorization'  => [
                'name'     => self::METADATA_PREFIX.'authorization',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'validation'     => [
                'name'     => self::METADATA_PREFIX.'validation',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'deleted'        => [
                'name'     => self::METADATA_PREFIX.'deleted',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'geo'            => [
                'name'     => self::METADATA_PREFIX.'geo',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'retention'      => [
                'name'     => self::METADATA_PREFIX.'retention',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'groups'         => [
                'name'     => self::METADATA_PREFIX.'groups',
                'type'     => 'json',
                'nullable' => true,
            ],
        ][0m;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1041\lib/Service/[1;31mMagicMapper.php:1041:35[0m]8;;\ - Cannot access value on variable $propertyConfig using offset value of 'default', expecting 'type', 'format', 'items', 'properties', 'required', 'maxLength', 'minLength', 'maximum' or 'minimum' (see https://psalm.dev/115)
                    'default'  => [97;41m$propertyConfig['default'][0m ?? null,


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1181\lib/Service/[1;31mMagicMapper.php:1181:27[0m]8;;\ - Cannot access value on variable $propertyConfig using offset value of 'default', expecting 'type', 'format', 'items', 'properties', 'required', 'maxLength', 'minLength', 'maximum' or 'minimum' (see https://psalm.dev/115)
            'default'  => [97;41m$propertyConfig['default'][0m ?? null,


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1198\lib/Service/[1;31mMagicMapper.php:1198:22[0m]8;;\ - The declared return type 'array{default?: mixed, index?: bool, length?: int, name: string, nullable: bool, type: string, unique?: bool}' for OCA\OpenRegister\Service\MagicMapper::mapNumberProperty is incorrect, got 'array{default: mixed|null, index: true, name: string, nullable: bool, precision: 10, scale: 2, type: 'decimal'}' which is different due to additional array shape fields (precision, scale) (see https://psalm.dev/011)
     * @psalm-return [97;41mTableColumnConfig[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1204\lib/Service/[1;31mMagicMapper.php:1204:16[0m]8;;\ - The inferred type 'array{default: mixed|null, index: true, name: string, nullable: bool, precision: 10, scale: 2, type: 'decimal'}' does not match the declared return type 'array{default?: mixed, index?: bool, length?: int, name: string, nullable: bool, type: string, unique?: bool}' for OCA\OpenRegister\Service\MagicMapper::mapNumberProperty due to additional array shape fields (precision, scale) (see https://psalm.dev/128)
        return [97;41m[
            'name'      => $columnName,
            'type'      => 'decimal',
            'precision' => 10,
            'scale'     => 2,
            'nullable'  => !$isRequired,
            'default'   => $propertyConfig['default'] ?? null,
            'index'     => true,
        // Numeric fields are often used for filtering.
        ][0m;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1210\lib/Service/[1;31mMagicMapper.php:1210:28[0m]8;;\ - Cannot access value on variable $propertyConfig using offset value of 'default', expecting 'type', 'format', 'items', 'properties', 'required', 'maxLength', 'minLength', 'maximum' or 'minimum' (see https://psalm.dev/115)
            'default'   => [97;41m$propertyConfig['default'][0m ?? null,


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1230\lib/Service/[1;31mMagicMapper.php:1230:19[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Schema\Schema does not exist (see https://psalm.dev/019)
        $schema = [97;41m$this->db->createSchema()[0m;


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1231\lib/Service/[1;31mMagicMapper.php:1231:19[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Schema\Schema does not exist (see https://psalm.dev/019)
        $table  = [97;41m$schema[0m->createTable($tableName);


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1275\lib/Service/[1;31mMagicMapper.php:1275:19[0m]8;;\ - Cannot access value on variable $column using offset value of 'autoincrement', expecting 'name', 'type', 'length', 'nullable', 'default', 'index' or 'unique' (see https://psalm.dev/115)
        if (isset([97;41m$column['autoincrement'][0m) && $column['autoincrement']) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1279\lib/Service/[1;31mMagicMapper.php:1279:19[0m]8;;\ - Cannot access value on variable $column using offset value of 'precision', expecting 'name', 'type', 'length', 'nullable', 'default', 'index', 'unique' or 'autoincrement' (see https://psalm.dev/115)
        if (isset([97;41m$column['precision'][0m)) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1283\lib/Service/[1;31mMagicMapper.php:1283:19[0m]8;;\ - Cannot access value on variable $column using offset value of 'scale', expecting 'name', 'type', 'length', 'nullable', 'default', 'index', 'unique', 'autoincrement' or 'precision' (see https://psalm.dev/115)
        if (isset([97;41m$column['scale'][0m)) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1291\lib/Service/[1;31mMagicMapper.php:1291:19[0m]8;;\ - Cannot access value on variable $column using offset value of 'primary', expecting 'name', 'type', 'length', 'nullable', 'default', 'index', 'unique', 'autoincrement', 'precision' or 'scale' (see https://psalm.dev/115)
        if (isset([97;41m$column['primary'][0m) && $column['primary']) {


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1643\lib/Service/[1;31mMagicMapper.php:1643:48[0m]8;;\ - Parameter $datetime does not exist on function DateTime::__construct (see https://psalm.dev/238)
                        $value = new \DateTime([97;41mdatetime[0m: $value);


[0;31mERROR[0m: NullArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L1826\lib/Service/[1;31mMagicMapper.php:1826:71[0m]8;;\ - Argument 3 of OCP\IConfig::getAppValue cannot be null, null value provided to parameter with type string (see https://psalm.dev/057)
        return $this->config->getAppValue('openregister', $configKey, [97;41mnull[0m);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L2021\lib/Service/[1;31mMagicMapper.php:2021:41[0m]8;;\ - Method OCP\IDBConnection::getSchemaManager does not exist (see https://psalm.dev/181)
            $schemaManager = $this->db->[97;41mgetSchemaManager[0m();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L2065\lib/Service/[1;31mMagicMapper.php:2065:19[0m]8;;\ - Class, interface or enum named Doctrine\DBAL\Schema\Schema does not exist (see https://psalm.dev/019)
        $table  = [97;41m$schema[0m->getTable($tableName);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L2126\lib/Service/[1;31mMagicMapper.php:2126:41[0m]8;;\ - Method OCP\IDBConnection::getSchemaManager does not exist (see https://psalm.dev/181)
            $schemaManager = $this->db->[97;41mgetSchemaManager[0m();


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapper.php#L2221\lib/Service/[1;31mMagicMapper.php:2221:41[0m]8;;\ - Method OCP\IDBConnection::getSchemaManager does not exist (see https://psalm.dev/181)
            $schemaManager = $this->db->[97;41mgetSchemaManager[0m();


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicOrganizationHandler.php#L147\lib/Service/MagicMapperHandlers/[1;31mMagicOrganizationHandler.php:147:21[0m]8;;\ - string does not contain null (see https://psalm.dev/090)
                if ([97;41m$activeOrganisationUuid === null[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L141\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:141:47[0m]8;;\ - '{}' cannot be identical to non-empty-array<array-key, mixed> (see https://psalm.dev/056)
        if (empty($authorization) === true || [97;41m$authorization === '{}'[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L141\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:141:47[0m]8;;\ - Type non-empty-array<array-key, mixed> for $authorization is never =string({}) (see https://psalm.dev/056)
        if (empty($authorization) === true || [97;41m$authorization === '{}'[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L146\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:146:13[0m]8;;\ - Type non-empty-array<array-key, mixed> for $authorization is never string (see https://psalm.dev/056)
        if ([97;41mis_string($authorization)[0m === true) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L147\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:147:39[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
            $authConfig = json_decode([97;41m$authorization[0m, true);


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L200\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:200:47[0m]8;;\ - '{}' cannot be identical to non-empty-array<array-key, mixed> (see https://psalm.dev/056)
        if (empty($authorization) === true || [97;41m$authorization === '{}'[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L200\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:200:47[0m]8;;\ - Type non-empty-array<array-key, mixed> for $authorization is never =string({}) (see https://psalm.dev/056)
        if (empty($authorization) === true || [97;41m$authorization === '{}'[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L205\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:205:13[0m]8;;\ - Type non-empty-array<array-key, mixed> for $authorization is never string (see https://psalm.dev/056)
        if ([97;41mis_string($authorization)[0m === true) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicRbacHandler.php#L206\lib/Service/MagicMapperHandlers/[1;31mMagicRbacHandler.php:206:39[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
            $authConfig = json_decode([97;41m$authorization[0m, true);


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicSearchHandler.php#L367\lib/Service/MagicMapperHandlers/[1;31mMagicSearchHandler.php:367:87[0m]8;;\ - Cannot find referenced variable $tableName (see https://psalm.dev/024)
            $objectEntity = $this->convertRowToObjectEntity($row, $register, $schema, [97;41m$tableName[0m);


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicSearchHandler.php#L516\lib/Service/MagicMapperHandlers/[1;31mMagicSearchHandler.php:516:57[0m]8;;\ - Parameter $activeOrganisationUuid does not exist on function OCA\OpenRegister\Service\MagicMapperHandlers\MagicSearchHandler::searchObjects (see https://psalm.dev/238)
        return $this->searchObjects(query: $countQuery, [97;41mactiveOrganisationUuid[0m: $register, rbac: $schema, multi: $tableName);


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicSearchHandler.php#L516\lib/Service/MagicMapperHandlers/[1;31mMagicSearchHandler.php:516:92[0m]8;;\ - Parameter $rbac does not exist on function OCA\OpenRegister\Service\MagicMapperHandlers\MagicSearchHandler::searchObjects (see https://psalm.dev/238)
        return $this->searchObjects(query: $countQuery, activeOrganisationUuid: $register, [97;41mrbac[0m: $schema, multi: $tableName);


[0;31mERROR[0m: InvalidNamedArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MagicMapperHandlers/MagicSearchHandler.php#L516\lib/Service/MagicMapperHandlers/[1;31mMagicSearchHandler.php:516:107[0m]8;;\ - Parameter $multi does not exist on function OCA\OpenRegister\Service\MagicMapperHandlers\MagicSearchHandler::searchObjects (see https://psalm.dev/238)
        return $this->searchObjects(query: $countQuery, activeOrganisationUuid: $register, rbac: $schema, [97;41mmulti[0m: $tableName);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L117\lib/Service/[1;31mMetricsService.php:117:73[0m]8;;\ - Method OCA\OpenRegister\Service\MetricsService::encodeMetadata does not exist (see https://psalm.dev/022)
                    'metadata'      => $qb->createNamedParameter($this->[97;41mencodeMetadata[0m($metadata)),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L199\lib/Service/[1;31mMetricsService.php:199:31[0m]8;;\ - Method OCA\OpenRegister\Service\MetricsService::calculateSuccessRate does not exist (see https://psalm.dev/022)
        $successRate = $this->[97;41mcalculateSuccessRate[0m($total, $successful);


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L240\lib/Service/[1;31mMetricsService.php:240:44[0m]8;;\ - Method OCP\DB\QueryBuilder\IFunctionBuilder::avg does not exist (see https://psalm.dev/181)
                ->selectAlias($qb->func()->[97;41mavg[0m('duration_ms'), 'avg_ms')


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L255\lib/Service/[1;31mMetricsService.php:255:36[0m]8;;\ - Method OCA\OpenRegister\Service\MetricsService::roundAverageMs does not exist (see https://psalm.dev/022)
                'avg_ms' => $this->[97;41mroundAverageMs[0m($row['avg_ms']),


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L293\lib/Service/[1;31mMetricsService.php:293:54[0m]8;;\ - Method OCP\DB\QueryBuilder\IFunctionBuilder::length does not exist (see https://psalm.dev/181)
        $qb2->select($qb2->func()->sum($qb2->func()->[97;41mlength[0m('embedding')), 'total_bytes')


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L312\lib/Service/[1;31mMetricsService.php:312:47[0m]8;;\ - Method OCA\OpenRegister\Service\MetricsService::calculateAverageVectorsPerDay does not exist (see https://psalm.dev/022)
            'avg_vectors_per_day'   => $this->[97;41mcalculateAverageVectorsPerDay[0m($growthData),


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L341\lib/Service/[1;31mMetricsService.php:341:16[0m]8;;\ - The declared return type 'int' for OCA\OpenRegister\Service\MetricsService::cleanOldMetrics is incorrect, got 'OCP\DB\IResult|int' (see https://psalm.dev/011)
     * @return [97;41mint[0m Number of deleted records


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MetricsService.php#L352\lib/Service/[1;31mMetricsService.php:352:16[0m]8;;\ - The inferred type 'OCP\DB\IResult|int' does not match the declared return type 'int' for OCA\OpenRegister\Service\MetricsService::cleanOldMetrics (see https://psalm.dev/128)
        return [97;41m$qb->execute()[0m;


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MySQLJsonService.php#L297\lib/Service/[1;31mMySQLJsonService.php:297:17[0m]8;;\ - Type list<mixed> for $value is always array<array-key, mixed> (see https://psalm.dev/122)
            if ([97;41mis_array($value)[0m === true) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/MySQLJsonService.php#L313\lib/Service/[1;31mMySQLJsonService.php:313:17[0m]8;;\ - Type never for $value is never bool (see https://psalm.dev/056)
            if ([97;41mis_bool($value)[0m === true) {


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/NamedEntityRecognitionService.php#L467\lib/Service/[1;31mNamedEntityRecognitionService.php:467:40[0m]8;;\ - Method OCA\OpenRegister\Db\GdprEntityMapper::getQueryBuilder does not exist (see https://psalm.dev/022)
            $qb = $this->entityMapper->[97;41mgetQueryBuilder[0m();


[0;31mERROR[0m: InaccessibleMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/NamedEntityRecognitionService.php#L474\lib/Service/[1;31mNamedEntityRecognitionService.php:474:46[0m]8;;\ - Cannot access protected method OCA\OpenRegister\Db\GdprEntityMapper::findEntity from context OCA\OpenRegister\Service\NamedEntityRecognitionService (see https://psalm.dev/003)
            $existing = $this->entityMapper->[97;41mfindEntity[0m($qb);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/NamedEntityRecognitionService.php#L484\lib/Service/[1;31mNamedEntityRecognitionService.php:484:30[0m]8;;\ - Class, interface or enum named Ramsey\Uuid\Uuid does not exist (see https://psalm.dev/019)
            $entity->setUuid([97;41mUuid[0m::v4()->toString());


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/OasService.php#L151\lib/Service/[1;31mOasService.php:151:17[0m]8;;\ - Type non-empty-array<array-key, mixed> for $schemaDefinition is always array<array-key, mixed> (see https://psalm.dev/122)
            if ([97;41m!empty($schemaDefinition) && is_array($schemaDefinition)[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/OasService.php#L151\lib/Service/[1;31mOasService.php:151:46[0m]8;;\ - Type non-empty-array<array-key, mixed> for $schemaDefinition is always array<array-key, mixed> (see https://psalm.dev/122)
            if (!empty($schemaDefinition) && [97;41mis_array($schemaDefinition)[0m) {


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L664\lib/Service/[1;31mObjectCacheService.php:664:9[0m]8;;\ - $this->objectCache with declared type 'array<string, OCA\OpenRegister\Db\ObjectEntity>' cannot be assigned type 'non-empty-array<int|string, OCA\OpenRegister\Db\ObjectEntity>' (see https://psalm.dev/145)
        [97;41m$this->objectCache[0m[$object->getId()] = $object;


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L668\lib/Service/[1;31mObjectCacheService.php:668:13[0m]8;;\ - $this->objectCache with declared type 'array<string, OCA\OpenRegister\Db\ObjectEntity>' cannot be assigned type 'non-empty-array<int|string, OCA\OpenRegister\Db\ObjectEntity>' (see https://psalm.dev/145)
            [97;41m$this->objectCache[0m[$object->getUuid()] = $object;


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1070\lib/Service/[1;31mObjectCacheService.php:1070:41[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\ObjectCacheService::clearSchemaRelatedCaches expects int|null, but int|null|string provided (see https://psalm.dev/012)
        $this->clearSchemaRelatedCaches([97;41m$schemaId[0m, $registerId, $operation);


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1070\lib/Service/[1;31mObjectCacheService.php:1070:52[0m]8;;\ - Argument 2 of OCA\OpenRegister\Service\ObjectCacheService::clearSchemaRelatedCaches expects int|null, but int|null|string provided (see https://psalm.dev/012)
        $this->clearSchemaRelatedCaches($schemaId, [97;41m$registerId[0m, $operation);


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1099\lib/Service/[1;31mObjectCacheService.php:1099:15[0m]8;;\ - Cannot access value on variable $this->objectCache using a int offset, expecting string (see https://psalm.dev/115)
        unset([97;41m$this->objectCache[$object->getId()][0m);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1179\lib/Service/[1;31mObjectCacheService.php:1179:13[0m]8;;\ - Type array<array-key, mixed> for $keyComponents['query'] is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41misset($keyComponents['query']) && is_array($keyComponents['query'])[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1179\lib/Service/[1;31mObjectCacheService.php:1179:47[0m]8;;\ - Type array<array-key, mixed> for $keyComponents['query'] is always array<array-key, mixed> (see https://psalm.dev/122)
        if (isset($keyComponents['query']) && [97;41mis_array($keyComponents['query'])[0m) {


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1371\lib/Service/[1;31mObjectCacheService.php:1371:71[0m]8;;\ - Argument 1 of OCA\OpenRegister\Db\OrganisationMapper::findByUuid expects string, but int|string provided (see https://psalm.dev/012)
                $organisation = $this->organisationMapper->findByUuid([97;41m$identifier[0m);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1372\lib/Service/[1;31mObjectCacheService.php:1372:21[0m]8;;\ - OCA\OpenRegister\Db\Organisation can never contain null (see https://psalm.dev/122)
                if ([97;41m$organisation !== null[0m) {


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1757\lib/Service/[1;31mObjectCacheService.php:1757:55[0m]8;;\ - Method OCA\OpenRegister\Db\ObjectEntityMapper::findInBatches does not exist (see https://psalm.dev/022)
                $objects = $this->objectEntityMapper->[97;41mfindInBatches[0m(


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1915\lib/Service/[1;31mObjectCacheService.php:1915:16[0m]8;;\ - The declared return type 'bool' for OCA\OpenRegister\Service\ObjectCacheService::clearSolrIndex is incorrect, got 'array<array-key, mixed>|bool' (see https://psalm.dev/011)
     * @return [97;41mbool[0m True if clearing was successful


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectCacheService.php#L1950\lib/Service/[1;31mObjectCacheService.php:1950:20[0m]8;;\ - The inferred type 'array<array-key, mixed>|bool' does not match the declared return type 'bool' for OCA\OpenRegister\Service\ObjectCacheService::clearSolrIndex (see https://psalm.dev/128)
            return [97;41m$result[0m;


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/DeleteObject.php#L145\lib/Service/ObjectHandlers/[1;31mDeleteObject.php:145:32[0m]8;;\ - Method JsonSerializable::getRegister does not exist (see https://psalm.dev/181)
                $objectEntity->[97;41mgetRegister[0m(),


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/DeleteObject.php#L146\lib/Service/ObjectHandlers/[1;31mDeleteObject.php:146:32[0m]8;;\ - Method JsonSerializable::getSchema does not exist (see https://psalm.dev/181)
                $objectEntity->[97;41mgetSchema[0m()


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/GetObject.php#L325\lib/Service/ObjectHandlers/[1;31mGetObject.php:325:58[0m]8;;\ - Method OCA\OpenRegister\Db\ObjectEntityMapper::findByRelationUri does not exist (see https://psalm.dev/022)
        $referencingObjects = $this->objectEntityMapper->[97;41mfindByRelationUri[0m(


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ObjectServiceFacetExample.php#L152\lib/Service/ObjectHandlers/[1;31mObjectServiceFacetExample.php:152:20[0m]8;;\ - Method OCA\OpenRegister\Service\ObjectHandlers\ObjectServiceFacetExample::isAuditTrailsEnabled does not exist (see https://psalm.dev/022)
        if ($this->[97;41misAuditTrailsEnabled[0m() === true) {


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ObjectServiceFacetExample.php#L413\lib/Service/ObjectHandlers/[1;31mObjectServiceFacetExample.php:413:49[0m]8;;\ - Method OCA\OpenRegister\Service\ObjectHandlers\ObjectServiceFacetExample::calculatePerformanceImprovement does not exist (see https://psalm.dev/022)
            'performance_improvement' => $this->[97;41mcalculatePerformanceImprovement[0m($legacyTime, $newTime),


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L141\lib/Service/ObjectHandlers/[1;31mRenderObject.php:141:24[0m]8;;\ - The declared return type 'array<string, OCA\OpenRegister\Db\ObjectEntity>' for OCA\OpenRegister\Service\ObjectHandlers\RenderObject::preloadRelatedObjects is incorrect, got 'array<int|string, OCA\OpenRegister\Db\ObjectEntity>' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray<string, ObjectEntity>[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L217\lib/Service/ObjectHandlers/[1;31mRenderObject.php:217:20[0m]8;;\ - The inferred type 'array<int|string, OCA\OpenRegister\Db\ObjectEntity>' does not match the declared return type 'array<string, OCA\OpenRegister\Db\ObjectEntity>' for OCA\OpenRegister\Service\ObjectHandlers\RenderObject::preloadRelatedObjects (see https://psalm.dev/128)
            return [97;41m$indexedObjects[0m;


[0;31mERROR[0m: InvalidNullableReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L872\lib/Service/ObjectHandlers/[1;31mRenderObject.php:872:16[0m]8;;\ - The declared return type 'OCA\OpenRegister\Db\ObjectEntity' for OCA\OpenRegister\Service\ObjectHandlers\RenderObject::renderEntity is not nullable, but 'OCA\OpenRegister\Db\ObjectEntity|null' contains null (see https://psalm.dev/144)
     * @return [97;41mObjectEntity[0m The rendered entity with applied extensions and filters


[0;31mERROR[0m: NullableReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L889\lib/Service/ObjectHandlers/[1;31mRenderObject.php:889:20[0m]8;;\ - The declared return type 'OCA\OpenRegister\Db\ObjectEntity' for OCA\OpenRegister\Service\ObjectHandlers\RenderObject::renderEntity is not nullable, but the function returns 'null' (see https://psalm.dev/139)
            return [97;41m$entity->setObject(['@circular' => true, 'id' => $entity->getUuid()])[0m;


[0;31mERROR[0m: MismatchingDocblockReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1017\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1017:16[0m]8;;\ - Docblock has incorrect return type 'Adbar\Dot|array<array-key, mixed>', should be 'array<array-key, mixed>' (see https://psalm.dev/142)
     * @return [97;41marray|Dot[0m


[0;31mERROR[0m: MismatchingDocblockParamType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1073\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1073:15[0m]8;;\ - Parameter $allFlag has wrong type 'bool|null', should be 'bool' (see https://psalm.dev/141)
     * @param [97;41mbool|null[0m  $allFlag    If we extend all or not.


[0;31mERROR[0m: MismatchingDocblockParamType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1074\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1074:15[0m]8;;\ - Parameter $visitedIds has wrong type 'array<array-key, mixed>|null', should be 'array<array-key, mixed>' (see https://psalm.dev/141)
     * @param [97;41marray|null[0m $visitedIds All ids we already handled.


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1151\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1151:120[0m]8;;\ - Cannot find referenced variable $filter (see https://psalm.dev/024)
                            return $this->renderEntity(entity: $object, extend: $subExtend, depth: $depth + 1, filter: [97;41m$filter[0m ?? [], fields: $fields ?? [], unset: $unset ?? [], visitedIds: $visitedIds)->jsonSerialize();


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1151\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1151:143[0m]8;;\ - Cannot find referenced variable $fields (see https://psalm.dev/024)
                            return $this->renderEntity(entity: $object, extend: $subExtend, depth: $depth + 1, filter: $filter ?? [], fields: [97;41m$fields[0m ?? [], unset: $unset ?? [], visitedIds: $visitedIds)->jsonSerialize();


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1151\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1151:165[0m]8;;\ - Cannot find referenced variable $unset (see https://psalm.dev/024)
                            return $this->renderEntity(entity: $object, extend: $subExtend, depth: $depth + 1, filter: $filter ?? [], fields: $fields ?? [], unset: [97;41m$unset[0m ?? [], visitedIds: $visitedIds)->jsonSerialize();


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1202\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1202:33[0m]8;;\ - Cannot find referenced variable $filter (see https://psalm.dev/024)
                        filter: [97;41m$filter[0m ?? [],


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1203\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1203:33[0m]8;;\ - Cannot find referenced variable $fields (see https://psalm.dev/024)
                        fields: [97;41m$fields[0m ?? [],


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1204\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1204:32[0m]8;;\ - Cannot find referenced variable $unset (see https://psalm.dev/024)
                        unset: [97;41m$unset[0m ?? [],


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/RenderObject.php#L1351\lib/Service/ObjectHandlers/[1;31mRenderObject.php:1351:51[0m]8;;\ - Argument 1 of array_combine expects array<array-key, array-key>, but array<array-key, null|string> provided (see https://psalm.dev/004)
        $objectsToCache     = array_combine(keys: [97;41m$ids[0m, values: $referencingObjects);


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L178\lib/Service/ObjectHandlers/[1;31mSaveObject.php:178:24[0m]8;;\ - The inferred type 'int' does not match the declared return type 'null|string' for OCA\OpenRegister\Service\ObjectHandlers\SaveObject::resolveSchemaReference (see https://psalm.dev/128)
                return [97;41m$schema->getId()[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L196\lib/Service/ObjectHandlers/[1;31mSaveObject.php:196:28[0m]8;;\ - The inferred type 'int' does not match the declared return type 'null|string' for OCA\OpenRegister\Service\ObjectHandlers\SaveObject::resolveSchemaReference (see https://psalm.dev/128)
                    return [97;41m$schema->getId()[0m;


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L205\lib/Service/ObjectHandlers/[1;31mSaveObject.php:205:44[0m]8;;\ - Method OCA\OpenRegister\Db\SchemaMapper::findBySlug does not exist (see https://psalm.dev/022)
            $schema = $this->schemaMapper->[97;41mfindBySlug[0m($slug);


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L261\lib/Service/ObjectHandlers/[1;31mSaveObject.php:261:24[0m]8;;\ - The inferred type 'int' does not match the declared return type 'null|string' for OCA\OpenRegister\Service\ObjectHandlers\SaveObject::resolveRegisterReference (see https://psalm.dev/128)
                return [97;41m$register->getId()[0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L279\lib/Service/ObjectHandlers/[1;31mSaveObject.php:279:28[0m]8;;\ - The inferred type 'int' does not match the declared return type 'null|string' for OCA\OpenRegister\Service\ObjectHandlers\SaveObject::resolveRegisterReference (see https://psalm.dev/128)
                    return [97;41m$register->getId()[0m;


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L288\lib/Service/ObjectHandlers/[1;31mSaveObject.php:288:48[0m]8;;\ - Method OCA\OpenRegister\Db\RegisterMapper::findBySlug does not exist (see https://psalm.dev/022)
            $register = $this->registerMapper->[97;41mfindBySlug[0m($slug);


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L572\lib/Service/ObjectHandlers/[1;31mSaveObject.php:572:17[0m]8;;\ - Type null|string for $imageValue is never array<array-key, mixed> (see https://psalm.dev/056)
            if ([97;41mis_array($imageValue)[0m && !empty($imageValue)) {


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L596\lib/Service/ObjectHandlers/[1;31mSaveObject.php:596:53[0m]8;;\ - Too few arguments for OCA\OpenRegister\Service\FileService::publishFile - expecting file to be passed (see https://psalm.dev/025)
                                $this->fileService->[97;41mpublishFile[0m($fileNode);


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L596\lib/Service/ObjectHandlers/[1;31mSaveObject.php:596:53[0m]8;;\ - Too few arguments for method OCA\OpenRegister\Service\FileService::publishfile saw 1 (see https://psalm.dev/025)
                                $this->fileService->[97;41mpublishFile[0m($fileNode);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L596\lib/Service/ObjectHandlers/[1;31mSaveObject.php:596:65[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\FileService::publishFile expects OCA\OpenRegister\Db\ObjectEntity|string, but OCP\Files\File provided (see https://psalm.dev/004)
                                $this->fileService->publishFile([97;41m$fileNode[0m);


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L643\lib/Service/ObjectHandlers/[1;31mSaveObject.php:643:49[0m]8;;\ - Too few arguments for OCA\OpenRegister\Service\FileService::publishFile - expecting file to be passed (see https://psalm.dev/025)
                            $this->fileService->[97;41mpublishFile[0m($fileNode);


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L643\lib/Service/ObjectHandlers/[1;31mSaveObject.php:643:49[0m]8;;\ - Too few arguments for method OCA\OpenRegister\Service\FileService::publishfile saw 1 (see https://psalm.dev/025)
                            $this->fileService->[97;41mpublishFile[0m($fileNode);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L643\lib/Service/ObjectHandlers/[1;31mSaveObject.php:643:61[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\FileService::publishFile expects OCA\OpenRegister\Db\ObjectEntity|string, but OCP\Files\File provided (see https://psalm.dev/004)
                            $this->fileService->publishFile([97;41m$fileNode[0m);


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L663\lib/Service/ObjectHandlers/[1;31mSaveObject.php:663:24[0m]8;;\ - Type null|string for $imageValue is never array<array-key, mixed> (see https://psalm.dev/056)
            } else if ([97;41mis_array($imageValue)[0m && isset($imageValue['downloadUrl'])) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L666\lib/Service/ObjectHandlers/[1;31mSaveObject.php:666:24[0m]8;;\ - Type null|string for $imageValue is never array<array-key, mixed> (see https://psalm.dev/056)
            } else if ([97;41mis_array($imageValue)[0m && isset($imageValue['accessUrl'])) {


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1015\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1015:39[0m]8;;\ - Redundant cast to string (see https://psalm.dev/262)
            $slug = $this->createSlug([97;41m(string) $value[0m);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1570\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1570:65[0m]8;;\ - Operand of type true is always truthy (see https://psalm.dev/122)
                } else if (is_array($value) && empty($value) && [97;41m$isRequired[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1727\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1727:31[0m]8;;\ - Type array<array-key, mixed> for $selfData is never null (see https://psalm.dev/122)
                    selfData: [97;41m$selfData[0m ?? [],


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1727\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1727:44[0m]8;;\ - Cannot resolve types for $selfData - array<array-key, mixed> does not contain null (see https://psalm.dev/090)
                    selfData: $selfData ?? [97;41m[][0m,


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1766\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1766:23[0m]8;;\ - Type array<array-key, mixed> for $selfData is never null (see https://psalm.dev/122)
            selfData: [97;41m$selfData[0m ?? [],


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1766\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1766:36[0m]8;;\ - Cannot resolve types for $selfData - array<array-key, mixed> does not contain null (see https://psalm.dev/090)
            selfData: $selfData ?? [97;41m[][0m,


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1841\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1841:13[0m]8;;\ - Argument 3 of OCA\OpenRegister\Service\ObjectCacheService::invalidateForObjectChange expects int|null, but null|string provided (see https://psalm.dev/012)
            [97;41m$savedEntity->getRegister()[0m,


[0;31mERROR[0m: InvalidScalarArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L1842\lib/Service/ObjectHandlers/[1;31mSaveObject.php:1842:13[0m]8;;\ - Argument 4 of OCA\OpenRegister\Service\ObjectCacheService::invalidateForObjectChange expects int|null, but null|string provided (see https://psalm.dev/012)
            [97;41m$savedEntity->getSchema()[0m


[0;31mERROR[0m: DuplicateArrayKey - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L3376\lib/Service/ObjectHandlers/[1;31mSaveObject.php:3376:13[0m]8;;\ - Key 'PK' already exists on array (see https://psalm.dev/151)
            [97;41m"\x50\x4B\x03\x04" => false[0m,


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L3462\lib/Service/ObjectHandlers/[1;31mSaveObject.php:3462:24[0m]8;;\ - The declared return type 'array<int, string>' for OCA\OpenRegister\Service\ObjectHandlers\SaveObject::prepareAutoTags is incorrect, got 'list<array<array-key, string>|string>' (see https://psalm.dev/011)
     * @psalm-return   [97;41marray<int, string>[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L3484\lib/Service/ObjectHandlers/[1;31mSaveObject.php:3484:16[0m]8;;\ - The inferred type 'list<array<array-key, string>|string>' does not match the declared return type 'array<int, string>' for OCA\OpenRegister\Service\ObjectHandlers\SaveObject::prepareAutoTags (see https://psalm.dev/128)
        return [97;41m$processedTags[0m;


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObject.php#L3740\lib/Service/ObjectHandlers/[1;31mSaveObject.php:3740:33[0m]8;;\ - Operand of type true is always truthy (see https://psalm.dev/122)
        if (is_array($value) && [97;41m!empty($value)[0m) {


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObjects.php#L1339\lib/Service/ObjectHandlers/[1;31mSaveObjects.php:1339:13[0m]8;;\ - Type array<array-key, mixed> for $bulkResult is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41mis_array($bulkResult)[0m) {


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/SaveObjects.php#L1872\lib/Service/ObjectHandlers/[1;31mSaveObjects.php:1872:86[0m]8;;\ - Cannot find referenced variable $register (see https://psalm.dev/024)
            $selfData['register'] = $selfData['register'] ?? $object['register'] ?? ([97;41m$register[0m ? $register->getId() : null);


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ValidateObject.php#L503\lib/Service/ObjectHandlers/[1;31mValidateObject.php:503:28[0m]8;;\ - Too few arguments for OCA\OpenRegister\Service\ObjectHandlers\ValidateObject::isSelfReference - expecting schemaSlug to be passed (see https://psalm.dev/025)
                if ($this->[97;41misSelfReference[0m($schemaSlug)) {


[0;31mERROR[0m: TooFewArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ValidateObject.php#L503\lib/Service/ObjectHandlers/[1;31mValidateObject.php:503:28[0m]8;;\ - Too few arguments for method OCA\OpenRegister\Service\ObjectHandlers\ValidateObject::isselfreference saw 1 (see https://psalm.dev/025)
                if ($this->[97;41misSelfReference[0m($schemaSlug)) {


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ValidateObject.php#L503\lib/Service/ObjectHandlers/[1;31mValidateObject.php:503:44[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\ObjectHandlers\ValidateObject::isSelfReference expects object, but string provided (see https://psalm.dev/004)
                if ($this->isSelfReference([97;41m$schemaSlug[0m)) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ValidateObject.php#L953\lib/Service/ObjectHandlers/[1;31mValidateObject.php:953:24[0m]8;;\ - Type int|null for $schema is never string (see https://psalm.dev/056)
            } else if ([97;41mis_int($schema) === true || is_string($schema) === true[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ValidateObject.php#L953\lib/Service/ObjectHandlers/[1;31mValidateObject.php:953:52[0m]8;;\ - Type null for $schema is never string (see https://psalm.dev/056)
            } else if (is_int($schema) === true || [97;41mis_string($schema)[0m === true) {


[0;31mERROR[0m: TooManyArguments - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ValidateObject.php#L981\lib/Service/ObjectHandlers/[1;31mValidateObject.php:981:20[0m]8;;\ - Too many arguments for Opis\JsonSchema\ValidationResult::__construct - expecting 1 but saw 2 (see https://psalm.dev/026)
            return [97;41mnew ValidationResult(null, null)[0m;


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectHandlers/ValidateObject.php#L1093\lib/Service/ObjectHandlers/[1;31mValidateObject.php:1093:20[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Db\File does not exist (see https://psalm.dev/019)
            return [97;41mFile[0m::getSchema($this->urlGenerator);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L412\lib/Service/[1;31mObjectService.php:412:40[0m]8;;\ - Argument 1 of setFolder expects null|string, but int provided (see https://psalm.dev/004)
                    $entity->setFolder([97;41m$folderNode->getId()[0m);


[0;31mERROR[0m: TypeDoesNotContainNull - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L590\lib/Service/[1;31mObjectService.php:590:13[0m]8;;\ - OCA\OpenRegister\Db\ObjectEntity does not contain null (see https://psalm.dev/090)
        if ([97;41m$object === null[0m) {


[0;31mERROR[0m: MismatchingDocblockReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L699\lib/Service/[1;31mObjectService.php:699:16[0m]8;;\ - Docblock has incorrect return type 'array<array-key, mixed>', should be 'OCA\OpenRegister\Db\ObjectEntity' (see https://psalm.dev/142)
     * @return [97;41marray[0m The created object.


[0;31mERROR[0m: MismatchingDocblockReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L814\lib/Service/[1;31mObjectService.php:814:16[0m]8;;\ - Docblock has incorrect return type 'array<array-key, mixed>', should be 'OCA\OpenRegister\Db\ObjectEntity' (see https://psalm.dev/142)
     * @return [97;41marray[0m The updated object.


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1306\lib/Service/[1;31mObjectService.php:1306:19[0m]8;;\ - Cannot find referenced variable $config (see https://psalm.dev/024)
        if (isset([97;41m$config[0m['filters']['register']) === true) {


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1312\lib/Service/[1;31mObjectService.php:1312:19[0m]8;;\ - Cannot find referenced variable $config (see https://psalm.dev/024)
        if (isset([97;41m$config[0m['filters']['schema']) === true) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1589\lib/Service/[1;31mObjectService.php:1589:20[0m]8;;\ - Type null for $ids is always !null (see https://psalm.dev/056)
        } else if ([97;41m$ids !== null[0m && $searchIds !== null) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1590\lib/Service/[1;31mObjectService.php:1590:36[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
            $ids = array_intersect([97;41m$ids[0m, $searchIds);


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1591\lib/Service/[1;31mObjectService.php:1591:43[0m]8;;\ - null can never contain null (see https://psalm.dev/122)
        } else if ($searchIds === null && [97;41m$searchIds !== [][0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1835\lib/Service/[1;31mObjectService.php:1835:65[0m]8;;\ - Type array<array-key, mixed> for $current[$part] is always !array<array-key, mixed> (see https://psalm.dev/056)
                        if (isset($current[$part]) === false || [97;41mis_array($current[$part]) === false[0m) {


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1873\lib/Service/[1;31mObjectService.php:1873:17[0m]8;;\ - Type int|string for $register is never array<array-key, mixed> (see https://psalm.dev/056)
            if ([97;41mis_array($register)[0m === true) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1875\lib/Service/[1;31mObjectService.php:1875:67[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
                $query['@self']['register'] = array_map('intval', [97;41m$register[0m);


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1882\lib/Service/[1;31mObjectService.php:1882:17[0m]8;;\ - Type int|string for $schema is never array<array-key, mixed> (see https://psalm.dev/056)
            if ([97;41mis_array($schema)[0m === true) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L1884\lib/Service/[1;31mObjectService.php:1884:65[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
                $query['@self']['schema'] = array_map('intval', [97;41m$schema[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L3568\lib/Service/[1;31mObjectService.php:3568:38[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but array<array-key, mixed> provided (see https://psalm.dev/004)
                            $resolve([97;41m$result[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L3592\lib/Service/[1;31mObjectService.php:3592:34[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but array<int, OCA\OpenRegister\Db\ObjectEntity>|int provided (see https://psalm.dev/004)
                        $resolve([97;41m$result[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L3604\lib/Service/[1;31mObjectService.php:3604:34[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but array<array-key, mixed> provided (see https://psalm.dev/004)
                        $resolve([97;41m$result[0m);


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L3616\lib/Service/[1;31mObjectService.php:3616:34[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but int provided (see https://psalm.dev/004)
                        $resolve([97;41m$result[0m);


[0;31mERROR[0m: UndefinedFunction - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L3718\lib/Service/[1;31mObjectService.php:3718:16[0m]8;;\ - Function React\Async\await does not exist (see https://psalm.dev/021)
        return [97;41m\React\Async\await($promise)[0m;


[0;31mERROR[0m: InaccessibleMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L4348\lib/Service/[1;31mObjectService.php:4348:45[0m]8;;\ - Cannot access private method OCA\OpenRegister\Service\ObjectHandlers\SaveObject::handleInverseRelationsWriteBack from context OCA\OpenRegister\Service\ObjectService (see https://psalm.dev/003)
                        $this->saveHandler->[97;41mhandleInverseRelationsWriteBack[0m($savedObject, $schema, $writeBackData);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L4373\lib/Service/[1;31mObjectService.php:4373:20[0m]8;;\ - Method OCA\OpenRegister\Service\ObjectService::performBulkWriteBackUpdates does not exist (see https://psalm.dev/022)
            $this->[97;41mperformBulkWriteBackUpdates[0m(array_values($bulkWriteBackUpdates));


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L6332\lib/Service/[1;31mObjectService.php:6332:34[0m]8;;\ - Argument 1 expects T:React\Promise\Promise as mixed, but non-empty-array<array-key, OCA\OpenRegister\Db\ObjectEntity> provided (see https://psalm.dev/004)
                        $resolve([97;41m$renderedBatch[0m);


[0;31mERROR[0m: UndefinedFunction - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L6345\lib/Service/[1;31mObjectService.php:6345:20[0m]8;;\ - Function React\Async\await does not exist (see https://psalm.dev/021)
        $results = [97;41m\React\Async\await(\React\Promise\all($promises))[0m;


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L6809\lib/Service/[1;31mObjectService.php:6809:42[0m]8;;\ - Method OCA\OpenRegister\Db\ObjectEntityMapper::getDB does not exist (see https://psalm.dev/022)
        $qb = $this->objectEntityMapper->[97;41mgetDB[0m()->getQueryBuilder();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L6842\lib/Service/[1;31mObjectService.php:6842:85[0m]8;;\ - Class, interface or enum named OCP\DB\IQueryBuilder does not exist (see https://psalm.dev/019)
        ->where($qb->expr()->in('o.id', $qb->createNamedParameter($relationshipIds, [97;41m\OCP\DB\IQueryBuilder[0m::PARAM_STR_ARRAY)))


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L6843\lib/Service/[1;31mObjectService.php:6843:89[0m]8;;\ - Class, interface or enum named OCP\DB\IQueryBuilder does not exist (see https://psalm.dev/019)
        ->orWhere($qb->expr()->in('o.uuid', $qb->createNamedParameter($relationshipIds, [97;41m\OCP\DB\IQueryBuilder[0m::PARAM_STR_ARRAY)))


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L6844\lib/Service/[1;31mObjectService.php:6844:89[0m]8;;\ - Class, interface or enum named OCP\DB\IQueryBuilder does not exist (see https://psalm.dev/019)
        ->orWhere($qb->expr()->in('o.slug', $qb->createNamedParameter($relationshipIds, [97;41m\OCP\DB\IQueryBuilder[0m::PARAM_STR_ARRAY)));


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/ObjectService.php#L6991\lib/Service/[1;31mObjectService.php:6991:44[0m]8;;\ - All possible types for this argument were invalidated - This may be dead code (see https://psalm.dev/179)
                    $schemaObject->hydrate([97;41m$schema[0m);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SchemaService.php#L348\lib/Service/[1;31mSchemaService.php:348:23[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Service\DateTime does not exist (see https://psalm.dev/019)
            $parsed = [97;41mDateTime[0m::createFromFormat(DATE_ISO8601, $value);


[0;31mERROR[0m: TypeDoesNotContainType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SchemaService.php#L1331\lib/Service/[1;31mSchemaService.php:1331:22[0m]8;;\ - Type never for $primaryType is never ~string(double) (see https://psalm.dev/056)
                case [97;41m'double'[0m:


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SearchTrailService.php#L229\lib/Service/[1;31mSearchTrailService.php:229:33[0m]8;;\ - Method OCA\OpenRegister\Service\SearchTrailService::calculatePages does not exist (see https://psalm.dev/022)
            'pages'   => $this->[97;41mcalculatePages[0m($total, $processedConfig['limit']),


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L195\lib/Service/[1;31mSettingsService.php:195:35[0m]8;;\ - Method OCP\App\IAppManager::isEnabled does not exist (see https://psalm.dev/181)
        return $this->appManager->[97;41misEnabled[0m(self::OPENREGISTER_APP_ID) === true;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L1300\lib/Service/[1;31mSettingsService.php:1300:30[0m]8;;\ - Cannot access value on variable $beforeStats using offset value of 'entries', expecting 'total_entries', 'entries_with_ttl', 'memory_cache_size', 'cache_table', 'query_time' or 'timestamp' (see https://psalm.dev/115)
                'cleared' => [97;41m$beforeStats['entries'][0m - $afterStats['entries'],


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L1300\lib/Service/[1;31mSettingsService.php:1300:56[0m]8;;\ - Cannot access value on variable $afterStats using offset value of 'entries', expecting 'total_entries', 'entries_with_ttl', 'memory_cache_size', 'cache_table', 'query_time' or 'timestamp' (see https://psalm.dev/115)
                'cleared' => $beforeStats['entries'] - [97;41m$afterStats['entries'][0m,


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L1333\lib/Service/[1;31mSettingsService.php:1333:30[0m]8;;\ - Cannot access value on variable $beforeStats using offset value of 'entries', expecting 'total_entries', 'by_type', 'memory_cache_size', 'cache_table', 'query_time' or 'timestamp' (see https://psalm.dev/115)
                'cleared' => [97;41m$beforeStats['entries'][0m - $afterStats['entries'],


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L1333\lib/Service/[1;31mSettingsService.php:1333:56[0m]8;;\ - Cannot access value on variable $afterStats using offset value of 'entries', expecting 'total_entries', 'by_type', 'memory_cache_size', 'cache_table', 'query_time' or 'timestamp' (see https://psalm.dev/115)
                'cleared' => $beforeStats['entries'] - [97;41m$afterStats['entries'][0m,


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L1981\lib/Service/[1;31mSettingsService.php:1981:17[0m]8;;\ - Instance property OCA\OpenRegister\Service\SettingsService::$logger is not defined (see https://psalm.dev/041)
                [97;41m$this->logger[0m->warning(message: 'Schema mapper not available for warmup', context: ['error' => $e->getMessage()]);


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L2028\lib/Service/[1;31mSettingsService.php:2028:13[0m]8;;\ - Instance property OCA\OpenRegister\Service\SettingsService::$logger is not defined (see https://psalm.dev/041)
            [97;41m$this->logger[0m->error(


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L2754\lib/Service/[1;31mSettingsService.php:2754:13[0m]8;;\ - Instance property OCA\OpenRegister\Service\SettingsService::$logger is not defined (see https://psalm.dev/041)
            [97;41m$this->logger[0m->warning(message: 'Failed to get default organisation UUID: '.$e->getMessage());


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SettingsService.php#L2774\lib/Service/[1;31mSettingsService.php:2774:13[0m]8;;\ - Instance property OCA\OpenRegister\Service\SettingsService::$logger is not defined (see https://psalm.dev/041)
            [97;41m$this->logger[0m->error(message: 'Failed to set default organisation UUID: '.$e->getMessage());


[0;31mERROR[0m: ForbiddenCode - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrFileService.php#L708\lib/Service/[1;31mSolrFileService.php:708:19[0m]8;;\ - Unsafe shell_exec (see https://psalm.dev/002)
        $result = [97;41mshell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($command)))[0m;


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrFileService.php#L776\lib/Service/[1;31mSolrFileService.php:776:50[0m]8;;\ - Method OCA\OpenRegister\Service\SolrFileService::calculateAvgChunkSize does not exist (see https://psalm.dev/022)
                    'avg_chunk_size'   => $this->[97;41mcalculateAvgChunkSize[0m($chunks),


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrFileService.php#L1271\lib/Service/[1;31mSolrFileService.php:1271:47[0m]8;;\ - Cannot access value on variable $result using offset value of 'chunks_indexed', expecting 'success', 'indexed' or 'collection' (see https://psalm.dev/115)
                    $stats['total_chunks'] += [97;41m$result['chunks_indexed'][0m;


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrFileService.php#L1283\lib/Service/[1;31mSolrFileService.php:1283:64[0m]8;;\ - Cannot access value on variable $result using offset value of 'message', expecting 'success', 'indexed' or 'collection' (see https://psalm.dev/115)
                    $stats['errors'][$fileText->getFileId()] = [97;41m$result['message'][0m ?? 'Unknown error';


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrFileService.php#L1437\lib/Service/[1;31mSolrFileService.php:1437:47[0m]8;;\ - Cannot access value on variable $extractionStats using offset value of 'completed', expecting 'totalFiles', 'untrackedFiles', 'totalChunks', 'totalObjects' or 'totalEntities' (see https://psalm.dev/115)
            'pending_indexing'     => max(0, ([97;41m$extractionStats['completed'][0m ?? 0) - ($fileStats['indexed_files'] ?? 0)),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrObjectService.php#L700\lib/Service/[1;31mSolrObjectService.php:700:47[0m]8;;\ - Method OCA\OpenRegister\Service\SolrObjectService::getProviderOrDefault does not exist (see https://psalm.dev/022)
                    'provider'      => $this->[97;41mgetProviderOrDefault[0m($provider),


[0;31mERROR[0m: RedundantCast - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrSchemaService.php#L546\lib/Service/[1;31mSolrSchemaService.php:546:29[0m]8;;\ - Redundant cast to string (see https://psalm.dev/262)
            $fieldNameStr = [97;41m(string)$fieldName[0m;


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrSchemaService.php#L699\lib/Service/[1;31mSolrSchemaService.php:699:35[0m]8;;\ - Argument 1 of json_decode expects string, but non-empty-array<array-key, mixed> provided (see https://psalm.dev/004)
        $properties = json_decode([97;41m$schemaProperties[0m, true);


[0;31mERROR[0m: ParadoxicalCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrSchemaService.php#L816\lib/Service/[1;31mSolrSchemaService.php:816:13[0m]8;;\ - Condition ($type is string(file)) contradicts a previously-established condition ($type is not string(file)) (see https://psalm.dev/089)
            [97;41m'file' => 'text_general'[0m,       // File content (large text)


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrSchemaService.php#L1523\lib/Service/[1;31mSolrSchemaService.php:1523:49[0m]8;;\ - Method OCA\OpenRegister\Service\SettingsService::getTenantId does not exist (see https://psalm.dev/022)
            $tenantId = $this->settingsService->[97;41mgetTenantId[0m();


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrSchemaService.php#L1524\lib/Service/[1;31mSolrSchemaService.php:1524:55[0m]8;;\ - Method OCA\OpenRegister\Service\SettingsService::getOrganisationId does not exist (see https://psalm.dev/022)
            $organisationId = $this->settingsService->[97;41mgetOrganisationId[0m();


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrSchemaService.php#L1534\lib/Service/[1;31mSolrSchemaService.php:1534:58[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getTenantCollectionName does not exist (see https://psalm.dev/022)
                'solr_collection' => $this->solrService->[97;41mgetTenantCollectionName[0m(),


[0;31mERROR[0m: UndefinedDocblockClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L62\lib/Service/[1;31mSolrService.php:62:5[0m]8;;\ - Docblock-defined class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/200)
    /**
     * Solarium client instance
     *
     * @var Client|null
     */
    [97;41mprivate ?Client $client = null;[0m


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L62\lib/Service/[1;31mSolrService.php:62:13[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
    private [97;41m?Client[0m $client = null;


[0;31mERROR[0m: InvalidPropertyAssignmentValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L76\lib/Service/[1;31mSolrService.php:76:33[0m]8;;\ - $this->solrConfig with declared type 'array{autoCommit: bool, commitWithin: int, core: string, enableLogging: bool, enabled: bool, host: string, password: string, path: string, port: int, scheme: string, timeout: int, username: string}' cannot be assigned type 'array<never, never>' (see https://psalm.dev/145)
    private array $solrConfig = [97;41m[][0m;


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L198\lib/Service/[1;31mSolrService.php:198:24[0m]8;;\ - Method OCA\OpenRegister\Service\SolrService::collectionExists does not exist (see https://psalm.dev/022)
            if ($this->[97;41mcollectionExists[0m($collectionName) === true) {


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L235\lib/Service/[1;31mSolrService.php:235:31[0m]8;;\ - Method OCA\OpenRegister\Service\SolrService::createCollection does not exist (see https://psalm.dev/022)
            $success = $this->[97;41mcreateCollection[0m($collectionName, $baseConfigSet);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L336\lib/Service/[1;31mSolrService.php:336:28[0m]8;;\ - Class, interface or enum named Solarium\Core\Client\Adapter\Curl does not exist (see https://psalm.dev/019)
            $adapter = new [97;41mCurl[0m();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L337\lib/Service/[1;31mSolrService.php:337:36[0m]8;;\ - Class, interface or enum named Symfony\Component\EventDispatcher\EventDispatcher does not exist (see https://psalm.dev/019)
            $eventDispatcher = new [97;41mEventDispatcher[0m();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L338\lib/Service/[1;31mSolrService.php:338:33[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $this->client = new [97;41mClient[0m($adapter, $eventDispatcher);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L345\lib/Service/[1;31mSolrService.php:345:40[0m]8;;\ - Method OCA\OpenRegister\Service\SolrService::collectionExists does not exist (see https://psalm.dev/022)
            $collectionExists = $this->[97;41mcollectionExists[0m($tenantSpecificCollection);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L349\lib/Service/[1;31mSolrService.php:349:28[0m]8;;\ - Method OCA\OpenRegister\Service\SolrService::collectionExists does not exist (see https://psalm.dev/022)
                if ($this->[97;41mcollectionExists[0m($baseCoreName) === true) {


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L445\lib/Service/[1;31mSolrService.php:445:21[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $ping = [97;41m$this->client[0m->createPing();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L446\lib/Service/[1;31mSolrService.php:446:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $result = [97;41m$this->client[0m->ping($ping);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L461\lib/Service/[1;31mSolrService.php:461:18[0m]8;;\ - Class, interface or enum named Solarium\Exception\HttpException does not exist (see https://psalm.dev/019)
        } catch ([97;41mHttpException[0m $e) {


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L465\lib/Service/[1;31mSolrService.php:465:52[0m]8;;\ - Class, interface or enum named Solarium\Exception\HttpException does not exist (see https://psalm.dev/019)
                'message' => 'SOLR HTTP error: ' . [97;41m$e[0m->getMessage(),


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L467\lib/Service/[1;31mSolrService.php:467:36[0m]8;;\ - Class, interface or enum named Solarium\Exception\HttpException does not exist (see https://psalm.dev/019)
                    'http_code' => [97;41m$e[0m->getCode(),


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L503\lib/Service/[1;31mSolrService.php:503:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $update = [97;41m$this->client[0m->createUpdate();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L509\lib/Service/[1;31mSolrService.php:509:13[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Query does not exist (see https://psalm.dev/019)
            [97;41m$update[0m->addDocument($doc);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L514\lib/Service/[1;31mSolrService.php:514:21[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Query does not exist (see https://psalm.dev/019)
                    [97;41m$update[0m->addCommit(false, false, false, $this->solrConfig['commitWithin']);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L516\lib/Service/[1;31mSolrService.php:516:21[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Query does not exist (see https://psalm.dev/019)
                    [97;41m$update[0m->addCommit();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L521\lib/Service/[1;31mSolrService.php:521:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $result = [97;41m$this->client[0m->update($update);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L574\lib/Service/[1;31mSolrService.php:574:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $update = [97;41m$this->client[0m->createUpdate();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L585\lib/Service/[1;31mSolrService.php:585:21[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Query does not exist (see https://psalm.dev/019)
                    [97;41m$update[0m->addDocument($doc);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L600\lib/Service/[1;31mSolrService.php:600:21[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Query does not exist (see https://psalm.dev/019)
                    [97;41m$update[0m->addCommit(false, false, false, $this->solrConfig['commitWithin']);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L602\lib/Service/[1;31mSolrService.php:602:25[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Query does not exist (see https://psalm.dev/019)
                        [97;41m$update[0m->addCommit();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L607\lib/Service/[1;31mSolrService.php:607:27[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
                $result = [97;41m$this->client[0m->update($update);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L671\lib/Service/[1;31mSolrService.php:671:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $update = [97;41m$this->client[0m->createUpdate();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L686\lib/Service/[1;31mSolrService.php:686:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $result = [97;41m$this->client[0m->update($update);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L745\lib/Service/[1;31mSolrService.php:745:22[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $query = [97;41m$this->client[0m->createSelect();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L765\lib/Service/[1;31mSolrService.php:765:26[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $resultSet = [97;41m$this->client[0m->select($query);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L780\lib/Service/[1;31mSolrService.php:780:38[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Result\Result does not exist (see https://psalm.dev/019)
                    'total_found' => [97;41m$resultSet[0m->getNumFound(),


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L790\lib/Service/[1;31mSolrService.php:790:28[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Result\Result does not exist (see https://psalm.dev/019)
                'total' => [97;41m$resultSet[0m->getNumFound(),


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L822\lib/Service/[1;31mSolrService.php:822:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $update = [97;41m$this->client[0m->createUpdate();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L829\lib/Service/[1;31mSolrService.php:829:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $result = [97;41m$this->client[0m->update($update);


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L897\lib/Service/[1;31mSolrService.php:897:16[0m]8;;\ - The declared return type 'array{cores: array<array-key, mixed>, health: array<array-key, mixed>, operations: array<array-key, mixed>, overview: array<array-key, mixed>, performance: array<array-key, mixed>}' for OCA\OpenRegister\Service\SolrService::getDashboardStats is incorrect, got 'array{cores: array{active_core: string, core_status: 'active'|'inactive', endpoint_url: string, tenant_id: string}, generated_at: string, health: array{disk_usage: array<array-key, mixed>, last_optimization: string, memory_usage: array<array-key, mixed>, status: string, uptime: string, warnings: array<array-key, mixed>}, operations: array{commit_frequency: array<array-key, mixed>, optimization_needed: bool, queue_status: array<array-key, mixed>, recent_activity: array<array-key, mixed>}, overview: array{available: bool, connection_status: 'error'|'healthy', index_size: string, last_commit: string, response_time_ms: 0|mixed, total_documents: int}, performance: array{avg_index_time_ms: float, avg_search_time_ms: float, error_rate: float, operations_per_sec: float, total_deletes: mixed, total_index_time: float, total_indexes: mixed, total_search_time: float, total_searches: mixed}}' which is different due to additional array shape fields (generated_at) (see https://psalm.dev/011)
     * @return [97;41marray{overview: array, cores: array, performance: array, health: array, operations: array}[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L965\lib/Service/[1;31mSolrService.php:965:16[0m]8;;\ - The inferred type 'array{cores: array{active_core: string, core_status: 'active'|'inactive', endpoint_url: string, tenant_id: string}, generated_at: string, health: array{disk_usage: array<array-key, mixed>, last_optimization: string, memory_usage: array<array-key, mixed>, status: string, uptime: string, warnings: array<array-key, mixed>}, operations: array{commit_frequency: array<array-key, mixed>, optimization_needed: bool, queue_status: array<array-key, mixed>, recent_activity: array<array-key, mixed>}, overview: array{available: bool, connection_status: 'error'|'healthy', index_size: string, last_commit: string, response_time_ms: 0|mixed, total_documents: int}, performance: array{avg_index_time_ms: float, avg_search_time_ms: float, error_rate: float, operations_per_sec: float, total_deletes: mixed, total_index_time: float, total_indexes: mixed, total_search_time: float, total_searches: mixed}}' does not match the declared return type 'array{cores: array<array-key, mixed>, health: array<array-key, mixed>, operations: array<array-key, mixed>, overview: array<array-key, mixed>, performance: array<array-key, mixed>}' for OCA\OpenRegister\Service\SolrService::getDashboardStats due to additional array shape fields (generated_at) (see https://psalm.dev/128)
        return [97;41m[
            'overview' => $overview,
            'cores' => $cores,
            'performance' => $performance,
            'health' => $health,
            'operations' => $operations,
            'generated_at' => date('c'),
        ][0m;


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L987\lib/Service/[1;31mSolrService.php:987:22[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $query = [97;41m$this->client[0m->createSelect();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L993\lib/Service/[1;31mSolrService.php:993:26[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $resultSet = [97;41m$this->client[0m->select($query);


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1259\lib/Service/[1;31mSolrService.php:1259:41[0m]8;;\ - Cannot access value on variable $units using a int<0, max> offset, expecting int<0, 4> (see https://psalm.dev/115)
        return round($bytes, 2) . ' ' . [97;41m$units[$i][0m;


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1268\lib/Service/[1;31mSolrService.php:1268:16[0m]8;;\ - The declared return type 'array{execution_time_ms: float, operations: array<array-key, mixed>, success: bool}' for OCA\OpenRegister\Service\SolrService::warmupIndex is incorrect, got 'array{error?: string, execution_time_ms: float, operations: array{batches_processed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, commit?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, objects_indexed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, objects_processed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, optimize?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, schemas_processed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, total_objects_found?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, ...<non-empty-literal-string, 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string>}, stats?: array{batchesProcessed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, duration: non-empty-lowercase-string, schemasProcessed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, totalIndexed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, totalObjectsFound: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, totalProcessed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string}, success: bool, timing?: array{cache_warming: non-empty-lowercase-string, commit_optimize: non-empty-lowercase-string, connection_test: non-empty-lowercase-string, object_indexing: non-empty-lowercase-string, schema_mirroring: non-empty-lowercase-string, total: non-empty-lowercase-string}}' which is different due to additional array shape fields (error, timing, stats) (see https://psalm.dev/011)
     * @return [97;41marray{success: bool, operations: array, execution_time_ms: float}[0m Warmup results


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1274\lib/Service/[1;31mSolrService.php:1274:20[0m]8;;\ - The inferred type 'array{error: 'SOLR is not available', execution_time_ms: float(0), operations: array<never, never>, success: false}' does not match the declared return type 'array{execution_time_ms: float, operations: array<array-key, mixed>, success: bool}' for OCA\OpenRegister\Service\SolrService::warmupIndex due to additional array shape fields (error) (see https://psalm.dev/128)
            return [97;41m[
                'success' => false,
                'operations' => [],
                'execution_time_ms' => 0.0,
                'error' => 'SOLR is not available'
            ][0m;


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1340\lib/Service/[1;31mSolrService.php:1340:40[0m]8;;\ - Instance property OCA\OpenRegister\Service\SolrService::$guzzleSolrService is not defined (see https://psalm.dev/041)
                        $indexResult = [97;41m$this->guzzleSolrService[0m->bulkIndexFromDatabaseParallel(1000, $maxObjects, 5);


[0;31mERROR[0m: UndefinedThisPropertyFetch - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1343\lib/Service/[1;31mSolrService.php:1343:40[0m]8;;\ - Instance property OCA\OpenRegister\Service\SolrService::$guzzleSolrService is not defined (see https://psalm.dev/041)
                        $indexResult = [97;41m$this->guzzleSolrService[0m->bulkIndexFromDatabase(1000, $maxObjects);


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1365\lib/Service/[1;31mSolrService.php:1365:35[0m]8;;\ - Cannot access value on variable $operations using offset value of 'collected_errors', expecting 'connection_test', 'schema_mirroring', 'schemas_processed', 'fields_created', 'mirror_errors', 'object_indexing', 'objects_indexed', 'total_objects_found', 'batches_processed', 'execution_mode', 'indexing_errors' or 'indexing_error' (see https://psalm.dev/115)
                        if (isset([97;41m$operations['collected_errors'][0m) === false) {


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1386\lib/Service/[1;31mSolrService.php:1386:44[0m]8;;\ - Cannot access value on variable $operations using offset value of 'objects_processed', expecting 'connection_test', 'schema_mirroring', 'schemas_processed', 'fields_created', 'mirror_errors', 'object_indexing', 'objects_indexed', 'total_objects_found', 'batches_processed', 'execution_mode', 'indexing_errors', 'indexing_error' or 'collected_errors' (see https://psalm.dev/115)
                    'objects_processed' => [97;41m$operations['objects_processed'][0m ?? 0,


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1455\lib/Service/[1;31mSolrService.php:1455:20[0m]8;;\ - The inferred type 'array{execution_time_ms: float, operations: array{commit: bool, objects_indexed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, optimize?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, ...<non-empty-literal-string, 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string>}, stats: array{batchesProcessed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, duration: non-empty-lowercase-string, schemasProcessed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, totalIndexed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, totalObjectsFound: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, totalProcessed: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string}, success: true, timing: array{cache_warming: non-empty-lowercase-string, commit_optimize: non-empty-lowercase-string, connection_test: non-empty-lowercase-string, object_indexing: non-empty-lowercase-string, schema_mirroring: non-empty-lowercase-string, total: non-empty-lowercase-string}}' does not match the declared return type 'array{execution_time_ms: float, operations: array<array-key, mixed>, success: bool}' for OCA\OpenRegister\Service\SolrService::warmupIndex due to additional array shape fields (timing, stats) (see https://psalm.dev/128)
            return [97;41m[
                'success' => true,
                'operations' => $operations,
                'timing' => $timing,
                'execution_time_ms' => $overallDuration,
                'stats' => [
                    'totalProcessed' => $operations['objects_processed'] ?? 0,
                    'totalIndexed' => $operations['objects_indexed'] ?? 0,
                    'totalObjectsFound' => $operations['total_objects_found'] ?? 0,
                    'batchesProcessed' => $operations['batches_processed'] ?? 0,
                    'schemasProcessed' => $operations['schemas_processed'] ?? 0,
                    'duration' => $overallDuration . 'ms'
                ]
            ][0m;


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1477\lib/Service/[1;31mSolrService.php:1477:20[0m]8;;\ - The inferred type 'array{error: string, execution_time_ms: float, operations: array{batches_processed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, commit?: bool, objects_indexed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, objects_processed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, optimize?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, schemas_processed?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, total_objects_found?: 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string, ...<non-empty-literal-string, 0|bool|mixed|non-empty-list<array{class: class-string<Exception>, file: string, line: int, message: string, timestamp: string, type: 'bulk_indexing_error'}>|string>}, success: false}' does not match the declared return type 'array{execution_time_ms: float, operations: array<array-key, mixed>, success: bool}' for OCA\OpenRegister\Service\SolrService::warmupIndex due to additional array shape fields (error) (see https://psalm.dev/128)
            return [97;41m[
                'success' => false,
                'operations' => $operations,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'error' => $e->getMessage()
            ][0m;


[0;31mERROR[0m: UndefinedVariable - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1480\lib/Service/[1;31mSolrService.php:1480:65[0m]8;;\ - Cannot find referenced variable $startTime (see https://psalm.dev/024)
                'execution_time_ms' => round((microtime(true) - [97;41m$startTime[0m) * 1000, 2),


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1855\lib/Service/[1;31mSolrService.php:1855:22[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $query = [97;41m$this->client[0m->createSelect();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1872\lib/Service/[1;31mSolrService.php:1872:13[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Query\Query does not exist (see https://psalm.dev/019)
            [97;41m$query[0m->addSort('score', 'desc');


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L1875\lib/Service/[1;31mSolrService.php:1875:26[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $resultSet = [97;41m$this->client[0m->select($query);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2025\lib/Service/[1;31mSolrService.php:2025:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $update = [97;41m$this->client[0m->createUpdate();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2027\lib/Service/[1;31mSolrService.php:2027:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $result = [97;41m$this->client[0m->update($update);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2048\lib/Service/[1;31mSolrService.php:2048:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $update = [97;41m$this->client[0m->createUpdate();


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2050\lib/Service/[1;31mSolrService.php:2050:23[0m]8;;\ - Class, interface or enum named Solarium\Client does not exist (see https://psalm.dev/019)
            $result = [97;41m$this->client[0m->update($update);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2065\lib/Service/[1;31mSolrService.php:2065:15[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Query does not exist (see https://psalm.dev/019)
     * @param [97;41mUpdateQuery[0m  $update Update query instance


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2071\lib/Service/[1;31mSolrService.php:2071:130[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Document does not exist (see https://psalm.dev/019)
    private function createSolrDocument(UpdateQuery $update, ObjectEntity $object, ?\OCA\OpenRegister\Db\Schema $schema = null): [97;41m\Solarium\QueryType\Update\Query\Document[0m


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2191\lib/Service/[1;31mSolrService.php:2191:15[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Update\Query\Document does not exist (see https://psalm.dev/019)
     * @param [97;41m\Solarium\QueryType\Update\Query\Document[0m $doc        SOLR document


[0;31mERROR[0m: InvalidArgument - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2487\lib/Service/[1;31mSolrService.php:2487:21[0m]8;;\ - Argument 1 of array_map expects callable|null, but string provided (see https://psalm.dev/004)
                    [97;41m$this->getItemTypeMapper($itemType)[0m,


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2644\lib/Service/[1;31mSolrService.php:2644:15[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Query\Query does not exist (see https://psalm.dev/019)
     * @param [97;41mSelectQuery[0m $query        SOLR query


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2676\lib/Service/[1;31mSolrService.php:2676:15[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Query\Query does not exist (see https://psalm.dev/019)
     * @param [97;41mSelectQuery[0m $query        SOLR query


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2702\lib/Service/[1;31mSolrService.php:2702:15[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Query\Query does not exist (see https://psalm.dev/019)
     * @param [97;41mSelectQuery[0m $query        SOLR query


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2723\lib/Service/[1;31mSolrService.php:2723:15[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Result\Result does not exist (see https://psalm.dev/019)
     * @param [97;41m\Solarium\QueryType\Select\Result\Result[0m $resultSet SOLR result set


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L2760\lib/Service/[1;31mSolrService.php:2760:15[0m]8;;\ - Class, interface or enum named Solarium\QueryType\Select\Result\Result does not exist (see https://psalm.dev/019)
     * @param [97;41m\Solarium\QueryType\Select\Result\Result[0m $resultSet SOLR result set


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L3030\lib/Service/[1;31mSolrService.php:3030:13[0m]8;;\ - Type array<array-key, mixed> for $order is always array<array-key, mixed> (see https://psalm.dev/122)
        if ([97;41mis_array($order)[0m === true) {


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L3126\lib/Service/[1;31mSolrService.php:3126:48[0m]8;;\ - Method OCA\OpenRegister\Db\ObjectEntityMapper::getTotalCount does not exist (see https://psalm.dev/022)
            $totalCount = $this->objectMapper->[97;41mgetTotalCount[0m();


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L3143\lib/Service/[1;31mSolrService.php:3143:49[0m]8;;\ - Method OCA\OpenRegister\Db\ObjectEntityMapper::findAllInRange does not exist (see https://psalm.dev/022)
                $objects = $this->objectMapper->[97;41mfindAllInRange[0m($offset, $currentBatchSize);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L3199\lib/Service/[1;31mSolrService.php:3199:46[0m]8;;\ - Method OCA\OpenRegister\Service\SolrService::calculateSuccessRate does not exist (see https://psalm.dev/022)
                    'success_rate' => $this->[97;41mcalculateSuccessRate[0m($totalProcessed, $totalErrors),


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/SolrService.php#L3201\lib/Service/[1;31mSolrService.php:3201:52[0m]8;;\ - Method OCA\OpenRegister\Service\SolrService::calculateObjectsPerSecond does not exist (see https://psalm.dev/022)
                    'objects_per_second' => $this->[97;41mcalculateObjectsPerSecond[0m($totalProcessed, $duration),


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtraction/FileHandler.php#L40\lib/Service/TextExtraction/[1;31mFileHandler.php:40:15[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
     * @param [97;41mIRootFolder[0m     $rootFolder  Nextcloud root folder.


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtraction/FileHandler.php#L95\lib/Service/TextExtraction/[1;31mFileHandler.php:95:18[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
        $files = [97;41m$this->rootFolder[0m->getById($sourceId);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtraction/ObjectHandler.php#L39\lib/Service/TextExtraction/[1;31mObjectHandler.php:39:15[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Db\ObjectMapper does not exist (see https://psalm.dev/019)
     * @param [97;41mObjectMapper[0m    $objectMapper   Object mapper.


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtraction/ObjectHandler.php#L98\lib/Service/TextExtraction/[1;31mObjectHandler.php:98:19[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Db\ObjectMapper does not exist (see https://psalm.dev/019)
        $object = [97;41m$this->objectMapper[0m->find($sourceId);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtraction/ObjectHandler.php#L233\lib/Service/TextExtraction/[1;31mObjectHandler.php:233:19[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Db\ObjectMapper does not exist (see https://psalm.dev/019)
        $object = [97;41m$this->objectMapper[0m->find($sourceId);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtraction/ObjectHandler.php#L259\lib/Service/TextExtraction/[1;31mObjectHandler.php:259:23[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Db\ObjectMapper does not exist (see https://psalm.dev/019)
            $object = [97;41m$this->objectMapper[0m->find($sourceId);


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtractionService.php#L109\lib/Service/[1;31mTextExtractionService.php:109:15[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
     * @param [97;41mIRootFolder[0m          $rootFolder           Nextcloud root folder


[0;31mERROR[0m: InvalidReturnType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtractionService.php#L339\lib/Service/[1;31mTextExtractionService.php:339:22[0m]8;;\ - The declared return type 'list<array{chunk_index: int<0, max>, detection_method: mixed|null, end_offset: int<0, max>|mixed, language: mixed|null, language_confidence: mixed|null, language_level: mixed|null, overlap_size: int, position_reference: array<string, mixed>, start_offset: 0|mixed, text_content: mixed}>' for OCA\OpenRegister\Service\TextExtractionService::textToChunks is incorrect, got 'list<array{checksum: mixed|null, chunk_index: int<0, max>, detection_method: mixed|null, end_offset: int<0, max>|mixed, language: mixed|null, language_confidence: mixed|null, language_level: mixed|null, overlap_size: int, position_reference: array{end?: 0|mixed, path?: mixed|null, start?: 0|mixed, type: 'property-path'|'text-range'}, start_offset: 0|mixed, text_content: mixed}>' which is different due to additional array shape fields (checksum) (see https://psalm.dev/011)
     * @psalm-return [97;41mlist<array{
     *     chunk_index: int<0, max>,
     *     detection_method: mixed|null,
     *     end_offset: int<0, max>|mixed,
     *     language: mixed|null,
     *     language_confidence: mixed|null,
     *     language_level: mixed|null,
     *     overlap_size: int,
     *     position_reference: array<string, mixed>,
     *     start_offset: 0|mixed,
     *     text_content: mixed
     * }>[0m


[0;31mERROR[0m: InvalidReturnStatement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtractionService.php#L387\lib/Service/[1;31mTextExtractionService.php:387:16[0m]8;;\ - The inferred type 'list<array{checksum: mixed|null, chunk_index: int<0, max>, detection_method: mixed|null, end_offset: int<0, max>|mixed, language: mixed|null, language_confidence: mixed|null, language_level: mixed|null, overlap_size: int, position_reference: array{end?: 0|mixed, path?: mixed|null, start?: 0|mixed, type: 'property-path'|'text-range'}, start_offset: 0|mixed, text_content: mixed}>' does not match the declared return type 'list<array{chunk_index: int<0, max>, detection_method: mixed|null, end_offset: int<0, max>|mixed, language: mixed|null, language_confidence: mixed|null, language_level: mixed|null, overlap_size: int, position_reference: array<string, mixed>, start_offset: 0|mixed, text_content: mixed}>' for OCA\OpenRegister\Service\TextExtractionService::textToChunks due to additional array shape fields (checksum) (see https://psalm.dev/128)
        return [97;41m$mappedChunks[0m;


[0;31mERROR[0m: MissingDependency - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtractionService.php#L661\lib/Service/[1;31mTextExtractionService.php:661:22[0m]8;;\ - OCP\Files\IRootFolder depends on class or interface oc\hooks\emitter that does not exist (see https://psalm.dev/157)
            $nodes = [97;41m$this->rootFolder[0m->getById($fileId);


[0;31mERROR[0m: StringIncrement - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/TextExtractionService.php#L1269\lib/Service/[1;31mTextExtractionService.php:1269:63[0m]8;;\ - Possibly unintended string increment (see https://psalm.dev/211)
                    for ($col = 'A'; $col !== $highestColumn; [97;41m$col[0m++) {


[0;31mERROR[0m: NoValue - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/UploadService.php#L103\lib/Service/[1;31mUploadService.php:103:13[0m]8;;\ - All possible types for this return were invalidated - This may be dead code (see https://psalm.dev/179)
            [97;41mreturn $this->getJSONfromFile();[0m


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/UploadService.php#L108\lib/Service/[1;31mUploadService.php:108:13[0m]8;;\ - Method OCP\AppFramework\Http\JSONResponse::offsetSet does not exist (see https://psalm.dev/022)
            [97;41m$phpArray[0m['source'] = $data['url'];


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/UploadService.php#L108\lib/Service/[1;31mUploadService.php:108:13[0m]8;;\ - Method OCP\AppFramework\Http\JSONResponse::offsetGet does not exist (see https://psalm.dev/022)
            [97;41m$phpArray[0m['source'] = $data['url'];


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/UploadService.php#L139\lib/Service/[1;31mUploadService.php:139:18[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Service\GuzzleHttp\Exception\BadResponseException does not exist (see https://psalm.dev/019)
        } catch ([97;41mGuzzleHttp\Exception\BadResponseException[0m $e) {


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/UploadService.php#L140\lib/Service/[1;31mUploadService.php:140:103[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Service\GuzzleHttp\Exception\BadResponseException does not exist (see https://psalm.dev/019)
            return new JSONResponse(data: ['error' => 'Failed to do a GET api-call on url: '.$url.' '.[97;41m$e[0m->getMessage()], statusCode: 400);


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/UploadService.php#L183\lib/Service/[1;31mUploadService.php:183:19[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Service\Exception does not exist (see https://psalm.dev/019)
        throw new [97;41mException[0m('File upload handling is not yet implemented');


[0;31mERROR[0m: UndefinedClass - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/UploadService.php#L217\lib/Service/[1;31mUploadService.php:217:26[0m]8;;\ - Class, interface or enum named OCA\OpenRegister\Service\DoesNotExistException does not exist (see https://psalm.dev/019)
                } catch ([97;41mDoesNotExistException[0m $e) {


[0;31mERROR[0m: InvalidDocblock - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/VectorEmbeddingService.php#L680\lib/Service/[1;31mVectorEmbeddingService.php:680:5[0m]8;;\ - Parenthesis must be preceded by “Closure”, “callable”, "pure-callable" or a valid @method name in docblock for OCA\OpenRegister\Service\VectorEmbeddingService::searchVectorsInSolr (see https://psalm.dev/008)
    /**
     * Search vectors in Solr using dense vector KNN
     *
     * Performs K-Nearest Neighbors search using Solr's dense vector capabilities.
     * Uses the {!knn f=FIELD topK=N} query syntax for efficient vector similarity search.
     *
     * @param array $queryEmbedding Query vector embedding
     * @param int   $limit          Maximum number of results
     * @param array $filters        Additional filters (entity_type, etc.)
     *
     * @return array<int, array<int|float|string|array|null>> Search results
     *
     * @throws \Exception If search fails or Solr is not configured
     *
     * @psalm-return list<array{
     *     chunk_index: 0|mixed,
     *     chunk_text: mixed|null,
     *     dimensions: 0|mixed,
     *     entity_id: string,
     *     entity_type: string,
     *     metadata: array,
     *     model: ''|mixed,
     *     similarity: float(0)|mixed,
     *     total_chunks: 1|mixed,
     *     vector_id: mixed
     * }>
     */
    [97;41mprivate function searchVectorsInSolr([0m


[0;31mERROR[0m: RedundantCondition - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/VectorEmbeddingService.php#L2148\lib/Service/[1;31mVectorEmbeddingService.php:2148:21[0m]8;;\ - string can never contain null (see https://psalm.dev/122)
                if ([97;41m$error !== null[0m && $error !== '') {


[0;31mERROR[0m: MoreSpecificImplementedParamType - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/VectorEmbeddingService.php#L2212\lib/Service/[1;31mVectorEmbeddingService.php:2212:50[0m]8;;\ - Argument 1 of OCA\OpenRegister\Service\_home_rubenlinde_nextcloud_docker_dev_workspace_server_apps_extra_openregister_lib_Service_VectorEmbeddingService_php_2059_80280::embedDocuments has the more specific type 'array<int, LLPhant\Embeddings\Document>', expecting 'array<array-key, LLPhant\Embeddings\Document>' as defined by LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface::embedDocuments (see https://psalm.dev/140)
            public function embedDocuments(array [97;41m$documents[0m): array


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/VectorEmbeddingService.php#L2411\lib/Service/[1;31mVectorEmbeddingService.php:2411:27[0m]8;;\ - Method OCA\OpenRegister\Service\VectorEmbeddingService::searchSimilarVectors does not exist (see https://psalm.dev/022)
        $results = $this->[97;41msearchSimilarVectors[0m(


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/VectorEmbeddingService.php#L2610\lib/Service/[1;31mVectorEmbeddingService.php:2610:47[0m]8;;\ - Method OCA\OpenRegister\Service\VectorEmbeddingService::getModelMismatchMessage does not exist (see https://psalm.dev/022)
                'message'           => $this->[97;41mgetModelMismatchMessage[0m($hasMismatch, $nullModelCount),


[0;31mERROR[0m: InaccessibleMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/Vectorization/FileVectorizationStrategy.php#L117\lib/Service/Vectorization/[1;31mFileVectorizationStrategy.php:117:42[0m]8;;\ - Cannot access protected method OCA\OpenRegister\Db\ChunkMapper::findEntities from context OCA\OpenRegister\Service\Vectorization\FileVectorizationStrategy (see https://psalm.dev/003)
        $allChunks = $this->chunkMapper->[97;41mfindEntities[0m($qb);


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/Vectorization/ObjectVectorizationStrategy.php#L219\lib/Service/Vectorization/[1;31mObjectVectorizationStrategy.php:219:49[0m]8;;\ - Method OCA\OpenRegister\Service\Vectorization\ObjectVectorizationStrategy::getSelfKeys does not exist (see https://psalm.dev/022)
                    '@self_keys'      => $this->[97;41mgetSelfKeys[0m($objectData),


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Service/VectorizationService.php#L306\lib/Service/[1;31mVectorizationService.php:306:49[0m]8;;\ - Cannot access value on variable $embeddingData using offset value of 'error', expecting 'embedding', 'model' or 'dimensions' (see https://psalm.dev/115)
                                'error'      => [97;41m$embeddingData['error'][0m ?? 'Embedding generation failed',


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L266\lib/Setup/[1;31mSolrSetup.php:266:31[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'core', expecting 'host', 'port', 'scheme', 'path', 'username' or 'password' (see https://psalm.dev/115)
        $baseCollectionName = [97;41m$this->solrConfig['core'][0m ?? 'openregister';


[0;31mERROR[0m: UndefinedMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L279\lib/Setup/[1;31mSolrSetup.php:279:36[0m]8;;\ - Method OCA\OpenRegister\Service\GuzzleSolrService::getTenantId does not exist (see https://psalm.dev/022)
        return $this->solrService->[97;41mgetTenantId[0m();


[0;31mERROR[0m: InvalidArrayOffset - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L292\lib/Setup/[1;31mSolrSetup.php:292:26[0m]8;;\ - Cannot access value on variable $this->solrConfig using offset value of 'configSet', expecting 'host', 'port', 'scheme', 'path', 'username' or 'password' (see https://psalm.dev/115)
        $configSetName = [97;41m$this->solrConfig['configSet'][0m ?? '_default';


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1431\lib/Setup/[1;31mSolrSetup.php:1431:44[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getRequest does not exist (see https://psalm.dev/181)
                'url_attempted'     => $e->[97;41mgetRequest[0m() ? $e->getRequest()->getUri() : 'unknown',


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1431\lib/Setup/[1;31mSolrSetup.php:1431:63[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getRequest does not exist (see https://psalm.dev/181)
                'url_attempted'     => $e->getRequest() ? $e->[97;41mgetRequest[0m()->getUri() : 'unknown',


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1436\lib/Setup/[1;31mSolrSetup.php:1436:45[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getRequest does not exist (see https://psalm.dev/181)
                    'request_method' => $e->[97;41mgetRequest[0m() ? $e->getRequest()->getMethod() : 'unknown',


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1436\lib/Setup/[1;31mSolrSetup.php:1436:64[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getRequest does not exist (see https://psalm.dev/181)
                    'request_method' => $e->getRequest() ? $e->[97;41mgetRequest[0m()->getMethod() : 'unknown',


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1437\lib/Setup/[1;31mSolrSetup.php:1437:45[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::hasResponse does not exist (see https://psalm.dev/181)
                    'response_code'  => $e->[97;41mhasResponse[0m() ? $e->getResponse()->getStatusCode() : null,


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1437\lib/Setup/[1;31mSolrSetup.php:1437:65[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getResponse does not exist (see https://psalm.dev/181)
                    'response_code'  => $e->hasResponse() ? $e->[97;41mgetResponse[0m()->getStatusCode() : null,


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1438\lib/Setup/[1;31mSolrSetup.php:1438:45[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::hasResponse does not exist (see https://psalm.dev/181)
                    'response_body'  => $e->[97;41mhasResponse[0m() ? (string) $e->getResponse()->getBody() : null,


[0;31mERROR[0m: UndefinedInterfaceMethod - ]8;;file:///home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Setup/SolrSetup.php#L1438\lib/Setup/[1;31mSolrSetup.php:1438:74[0m]8;;\ - Method GuzzleHttp\Exception\GuzzleException::getResponse does not exist (see https://psalm.dev/181)
                    'response_body'  => $e->hasResponse() ? (string) $e->[97;41mgetResponse[0m()->getBody() : null,


------------------------------
[0;31m660 errors[0m found
------------------------------
1284 other issues found.
You can display them with [30;48;5;195m--show-info=true[0m
------------------------------
Psalm can automatically fix 40 of these issues.
Run Psalm again with 
[30;48;5;195m--alter --issues=LessSpecificReturnType,InvalidReturnType,InvalidNullableReturnType,MismatchingDocblockReturnType,MismatchingDocblockParamType --dry-run[0m
to see what it can fix.
------------------------------

Checks took 30.39 seconds and used 345.351MB of memory
Psalm was able to infer types for 87.8611% of the codebase
Psalm not installed, skipping...
