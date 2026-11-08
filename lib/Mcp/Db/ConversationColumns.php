<?php

/**
 * OpenRegister Conversation columns
 *
 * The column types of the Conversation entity.
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

/**
 * Column map of the Conversation entity.
 */
final class ConversationColumns
{
    /**
     * Column name => entity type, in declaration order.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'uuid'         => 'string',
        'title'        => 'string',
        'userId'       => 'string',
        'organisation' => 'string',
        'agentId'      => 'integer',
        'metadata'     => 'json',
        'deletedAt'    => 'datetime',
        'created'      => 'datetime',
        'updated'      => 'datetime',
    ];
}//end class
