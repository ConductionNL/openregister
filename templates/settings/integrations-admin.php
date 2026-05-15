<?php
/**
 * Admin settings template — pluggable integration registry overview.
 *
 * Server-rendered table (no JS bundle) listing every registered
 * IntegrationProvider with its auth status, OpenConnector source,
 * and an action column for Configure / Test connection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-23
 */

declare(strict_types=1);

/** @var array<int,array<string,mixed>> $_['rows'] */
$rows = $_['rows'] ?? [];

$statusBadge = static function (string $status): string {
    return match ($status) {
        'ok'         => '<span class="cn-status cn-status--ok">' . p('ok') . '</span>',
        'degraded'   => '<span class="cn-status cn-status--degraded">' . p('degraded') . '</span>',
        'unavailable'=> '<span class="cn-status cn-status--unavailable">' . p('unavailable') . '</span>',
        default      => '<span class="cn-status">' . p($status) . '</span>',
    };
};

$authBadge = static function (string $authStatus): string {
    return match ($authStatus) {
        'configured' => '<span class="cn-status cn-status--ok">' . p('configured') . '</span>',
        'missing'    => '<span class="cn-status cn-status--unavailable">' . p('missing') . '</span>',
        'expired'    => '<span class="cn-status cn-status--degraded">' . p('expired') . '</span>',
        default      => '<span class="cn-status">' . p($authStatus) . '</span>',
    };
};
?>

<div id="openregister-integrations-admin" class="section">
    <h2><?php p($l->t('Integrations')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Every IntegrationProvider registered on this Nextcloud. External integrations route their CRUD through OpenConnector — use the Configure action to manage their credentials there.')); ?>
    </p>

    <?php if (empty($rows) === true) : ?>
        <p><em><?php p($l->t('No integrations registered yet.')); ?></em></p>
    <?php else : ?>
        <table class="grid">
            <thead>
                <tr>
                    <th><?php p($l->t('ID')); ?></th>
                    <th><?php p($l->t('Label')); ?></th>
                    <th><?php p($l->t('Group')); ?></th>
                    <th><?php p($l->t('Storage')); ?></th>
                    <th><?php p($l->t('Required app')); ?></th>
                    <th><?php p($l->t('Status')); ?></th>
                    <th><?php p($l->t('Auth')); ?></th>
                    <th><?php p($l->t('OpenConnector source')); ?></th>
                    <th><?php p($l->t('Actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><code><?php p((string) $row['id']); ?></code></td>
                        <td><?php p((string) $row['label']); ?></td>
                        <td><?php p((string) ($row['group'] ?? '')); ?></td>
                        <td><code><?php p((string) $row['storage']); ?></code></td>
                        <td>
                            <?php if ($row['requiredApp'] === null) : ?>
                                <em><?php p($l->t('any')); ?></em>
                            <?php else : ?>
                                <code><?php p((string) $row['requiredApp']); ?></code>
                                <?php if (($row['requiredAppOk'] ?? false) === false) : ?>
                                    <span class="cn-status cn-status--unavailable"><?php p($l->t('not installed')); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php print_unescaped($statusBadge((string) $row['status'])); ?></td>
                        <td><?php print_unescaped($authBadge((string) $row['authStatus'])); ?></td>
                        <td>
                            <?php if ($row['openConnectorSource'] !== null) : ?>
                                <code><?php p((string) $row['openConnectorSource']); ?></code>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($row['configureUrl'] ?? null) !== null) : ?>
                                <a class="button" href="<?php p((string) $row['configureUrl']); ?>">
                                    <?php p($l->t('Configure')); ?>
                                </a>
                            <?php endif; ?>
                            <?php if (($row['testConnectionUrl'] ?? null) !== null) : ?>
                                <a class="button" href="<?php p((string) $row['testConnectionUrl']); ?>" target="_blank" rel="noopener">
                                    <?php p($l->t('Test connection')); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
#openregister-integrations-admin table.grid { width: 100%; border-collapse: collapse; margin-top: 1rem; }
#openregister-integrations-admin table.grid th,
#openregister-integrations-admin table.grid td { padding: 6px 10px; border-bottom: 1px solid var(--color-border); text-align: left; vertical-align: top; font-size: 13px; }
#openregister-integrations-admin .cn-status { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
#openregister-integrations-admin .cn-status--ok          { background: var(--color-success); color: white; }
#openregister-integrations-admin .cn-status--degraded    { background: var(--color-warning); color: white; }
#openregister-integrations-admin .cn-status--unavailable { background: var(--color-error); color: white; }
#openregister-integrations-admin .settings-hint { color: var(--color-text-maxcontrast); margin: 0.5em 0 1em; max-width: 80ch; }
#openregister-integrations-admin .button { display: inline-block; padding: 4px 10px; margin-right: 4px; border-radius: var(--border-radius); border: 1px solid var(--color-border); text-decoration: none; font-size: 12px; color: var(--color-main-text); }
#openregister-integrations-admin .button:hover { background: var(--color-background-hover); }
</style>
