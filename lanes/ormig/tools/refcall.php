<?php
// $provider->list|get|create|update|delete($register, $schema, $objectId, ...rest) => (new ObjectRef(register:, schema:, id:), ...rest)
// usage: php refcall.php <receiver-regex> file...
require __DIR__ . '/vendor/autoload.php';
use PhpParser\Lexer\Emulative; use PhpParser\Node; use PhpParser\NodeFinder; use PhpParser\ParserFactory;
$lexer = new Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos']]);
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
$recv = $argv[1];
foreach (array_slice($argv, 2) as $file) {
	$code = file_get_contents($file); $ast = $parser->parse($code); $edits = [];
	foreach ((new NodeFinder())->findInstanceOf($ast, Node\Expr\MethodCall::class) as $c) {
		if (!$c->name instanceof Node\Identifier || !in_array($c->name->toString(), ['list', 'get', 'create', 'update', 'delete'], true) || count($c->args) < 3) continue;
		$rt = substr($code, $c->var->getAttribute('startFilePos'), $c->var->getAttribute('endFilePos') - $c->var->getAttribute('startFilePos') + 1);
		if (!preg_match($recv, $rt)) continue;
		$t = fn($a) => substr($code, $a->value->getAttribute('startFilePos'), $a->value->getAttribute('endFilePos') - $a->value->getAttribute('startFilePos') + 1);
		foreach ($c->args as $a) { if ($a->unpack) continue 2; }
		if ($c->args[0]->value instanceof Node\Expr\New_ && $c->args[0]->value->class instanceof Node\Name && $c->args[0]->value->class->getLast() === 'ObjectRef') continue;
		$named = []; $pos = [];
		foreach ($c->args as $i => $a) { if ($a->name) $named[$a->name->toString()] = $t($a); else $pos[] = $t($a); }
		$reg = $named['register'] ?? $pos[0] ?? null; $sch = $named['schema'] ?? $pos[1] ?? null; $oid = $named['objectId'] ?? $pos[2] ?? null;
		if ($reg === null || $sch === null || $oid === null) continue;
		unset($named['register'], $named['schema'], $named['objectId']);
		$rest = [];
		foreach (array_slice($pos, 3) as $p) $rest[] = $p;
		foreach ($named as $k => $v) $rest[] = "$k: $v";
		$open = strpos($code, '(', $c->name->getAttribute('endFilePos')); $close = $c->getAttribute('endFilePos');
		$edits[] = [$open, $close, '(' . implode(', ', array_merge(["new ObjectRef(register: $reg, schema: $sch, id: $oid)"], $rest)) . ')'];
		echo "REF\t$file:" . $c->getLine() . "\n";
	}
	if ($edits === []) continue;
	usort($edits, fn($a, $b) => $b[0] <=> $a[0]);
	foreach ($edits as $e) $code = substr($code, 0, $e[0]) . $e[2] . substr($code, $e[1] + 1);
	if (!preg_match('/^use OCA\\\\OpenRegister\\\\Kernel\\\\Contracts\\\\ObjectRef;/m', $code)) {
		preg_match_all('/^use\s+[A-Za-z0-9_\\\\]+(\s+as\s+\w+)?\s*;[ \t]*$/m', $code, $mm, PREG_OFFSET_CAPTURE);
		$last = end($mm[0]); $pos = $last[1] + strlen($last[0]);
		$code = substr($code, 0, $pos) . "\nuse OCA\\OpenRegister\\Kernel\\Contracts\\ObjectRef;" . substr($code, $pos);
	}
	file_put_contents($file, $code);
}
