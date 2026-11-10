<?php

/**
 * OpenRegister Agent columns
 *
 * The column types of the Agent entity and the way hydrate() reads each
 * column from an input array.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Entity
 * @package  OCA\OpenRegister\Mcp\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Db;

use Symfony\Component\Uid\Uuid;

/**
 * Column map of the Agent entity.
 */
final class AgentColumns
{
    /**
     * Column name => entity type, in declaration order.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'uuid'              => 'string',
        'name'              => 'string',
        'description'       => 'string',
        'type'              => 'string',
        'provider'          => 'string',
        'model'             => 'string',
        'prompt'            => 'string',
        'temperature'       => 'float',
        'maxTokens'         => 'integer',
        'configuration'     => 'json',
        'organisation'      => 'string',
        'owner'             => 'string',
        'active'            => 'boolean',
        'enableRag'         => 'boolean',
        'ragSearchMode'     => 'string',
        'ragNumSources'     => 'integer',
        'ragIncludeFiles'   => 'boolean',
        'ragIncludeObjects' => 'boolean',
        'requestQuota'      => 'integer',
        'tokenQuota'        => 'integer',
        'views'             => 'json',
        'searchFiles'       => 'boolean',
        'searchObjects'     => 'boolean',
        'isPrivate'         => 'boolean',
        'invitedUsers'      => 'json',
        'groups'            => 'json',
        'tools'             => 'json',
        'user'              => 'string',
        'created'           => 'datetime',
        'updated'           => 'datetime',
    ];

    /**
     * Hydrated column => [snake_case fallback key or null, default], in the
     * order hydrate() sets them (after the uuid).
     *
     * @var array<string, array{0: string|null, 1: mixed}>
     */
    private const HYDRATED = [
        'name'              => [null, null],
        'description'       => [null, null],
        'type'              => [null, null],
        'provider'          => [null, null],
        'model'             => [null, null],
        'prompt'            => [null, null],
        'temperature'       => [null, null],
        'maxTokens'         => ['max_tokens', null],
        'configuration'     => [null, null],
        'organisation'      => [null, null],
        'owner'             => [null, null],
        'active'            => [null, true],
        'enableRag'         => ['enable_rag', false],
        'ragSearchMode'     => ['rag_search_mode', null],
        'ragNumSources'     => ['rag_num_sources', null],
        'ragIncludeFiles'   => ['rag_include_files', false],
        'ragIncludeObjects' => ['rag_include_objects', false],
        'requestQuota'      => ['request_quota', null],
        'tokenQuota'        => ['token_quota', null],
        'views'             => [null, null],
        'searchFiles'       => ['search_files', true],
        'searchObjects'     => ['search_objects', true],
        'isPrivate'         => ['is_private', true],
        'invitedUsers'      => ['invited_users', null],
        'groups'            => [null, null],
        'tools'             => [null, null],
        'user'              => [null, null],
    ];

    /**
     * The value of every hydrated column, read camelCase first, then the
     * snake_case fallback, then the default. A missing or empty uuid gets a
     * fresh v4 uuid.
     *
     * @param array<string, mixed> $object The input array
     *
     * @return array<string, mixed> Column => value, uuid first
     */
    public static function hydrationValues(array $object): array
    {
        $uuid = Uuid::v4()->toRfc4122();
        if (empty($object['uuid'] ?? null) === false) {
            $uuid = $object['uuid'];
        }

        $values = ['uuid' => $uuid];
        foreach (self::HYDRATED as $column => [$fallback, $default]) {
            $values[$column] = $object[$column] ?? $object[$fallback ?? $column] ?? $default;
        }

        return $values;
    }//end hydrationValues()
}//end class
