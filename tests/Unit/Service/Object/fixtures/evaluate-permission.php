<?php

/**
 * Recorded outcomes for PermissionHandlerEvaluatePermissionTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'unresolvable rules' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'error: [PermissionHandler] Failed to get register for schema; denying access (fail-closed) {"schemaId":41,"error":"database gone"}',
      1 => 'error: [PermissionHandler] Authorization unresolvable; denying action (fail-closed) {"schemaId":41,"action":"read","userId":"ana","error":"Unable to resolve register for schema 41"}',
    ),
  ),
  'unresolvable rules anonymous' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'error: [PermissionHandler] Failed to get register for schema; denying access (fail-closed) {"schemaId":41,"error":"database gone"}',
      1 => 'error: [PermissionHandler] Authorization unresolvable; denying action (fail-closed) {"schemaId":41,"action":"read","userId":null,"error":"Unable to resolve register for schema 41"}',
    ),
  ),
  'schema rules skip the register' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'deny enforced' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action denied by an explicit deny rule {"schemaId":41,"action":"read","userId":"ana","principal":"behandelaars","rule":"behandelaars"}',
    ),
  ),
  'deny staged' => 
  array (
    'verdict' => true,
    'log' => 
    array (
      0 => 'warning: [DenyEnforcementMode] A deny rule would refuse this action; staging, so it was allowed {"mode":"staging","action":"read","userId":"ana","principal":"behandelaars","rule":"behandelaars","conditional":false,"schemaId":41,"path":"object"}',
    ),
  ),
  'deny other verb' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'deny for admin' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'private refuses non-owner' => 
  array (
    'verdict' => false,
    'log' => 
    array (
    ),
  ),
  'private admits owner' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'private admits invited' => 
  array (
    'verdict' => false,
    'log' => 
    array (
    ),
  ),
  'organisation scope' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'per-object rules override' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":"11111111-2222-3333-4444-555555555555","action":"read","userId":"ana","principals":["behandelaars"],"rule":["anderen"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
  'owner bypass on object' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'no rules at all' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'no rules at all anonymous read' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'no rules at all anonymous create' => 
  array (
    'verdict' => false,
    'log' => 
    array (
    ),
  ),
  'anonymous create public granted' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'anonymous update not granted' => 
  array (
    'verdict' => false,
    'log' => 
    array (
    ),
  ),
  'anonymous delete public granted' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'anonymous read public' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'anonymous read not public' => 
  array (
    'verdict' => false,
    'log' => 
    array (
    ),
  ),
  'anonymous custom verb' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'unknown user public' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'unknown user not public' => 
  array (
    'verdict' => false,
    'log' => 
    array (
    ),
  ),
  'admin' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'admin custom verb' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'session user' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'group grant' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'group grant second group' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'no matching group' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"read","userId":"ana","principals":["behandelaars"],"rule":["anderen"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
  'no rule for verb' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"update","userId":"ana","principals":["behandelaars"],"rule":null,"reason":"no authorization block names this verb"}',
    ),
  ),
  'authenticated grant' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'user override grant' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'inherit from public' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'inherit from public switched off' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"read","userId":"ana","principals":["behandelaars"],"rule":["public"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
  'derived group grant' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'derived admin is ignored' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"read","userId":"ana","principals":["behandelaars"],"rule":["niemand"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
  'custom verb listener allows' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'custom verb listener denies' => 
  array (
    'verdict' => false,
    'log' => 
    array (
    ),
  ),
  'custom verb undeclared vote' => 
  array (
    'verdict' => true,
    'log' => 
    array (
      0 => 'warning: [PermissionHandler] A listener decided a verb no app declares; it cannot be offered or audited {"action":"sign","verdict":true,"schemaId":41}',
    ),
  ),
  'custom verb listener throws' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'warning: [PermissionHandler] CustomScopeEvaluatingEvent dispatch failed — denying {"action":"sign","error":"listener failed"}',
    ),
  ),
  'custom verb declared, no vote' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'warning: [PermissionHandler] A declared custom verb has no evaluator; refusing {"action":"sign","declaredBy":"zaken-app","schemaId":41,"userId":"ana"}',
    ),
  ),
  'custom verb undeclared, no vote' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'custom verb without a dispatcher' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'custom verb canonical declaration' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"sign","userId":"ana","principals":["behandelaars"],"rule":["anderen"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
  'canonical verb with a dispatcher' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'verb create granted' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'verb create refused' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"create","userId":"ana","principals":["behandelaars"],"rule":["anderen"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
  'verb update granted' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'verb update refused' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"update","userId":"ana","principals":["behandelaars"],"rule":["anderen"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
  'verb delete granted' => 
  array (
    'verdict' => true,
    'log' => 
    array (
    ),
  ),
  'verb delete refused' => 
  array (
    'verdict' => false,
    'log' => 
    array (
      0 => 'info: [PermissionHandler] Action refused; the rule that decided it is named below {"schemaId":41,"objectUuid":null,"action":"delete","userId":"ana","principals":["behandelaars"],"rule":["anderen"],"reason":"the rule for this verb does not name this caller"}',
    ),
  ),
);
