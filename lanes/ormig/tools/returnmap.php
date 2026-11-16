<?php
// ->method('find'|'saveObject'|...)->willReturnMap([...]) => ->willReturnCallback(LegacyObjectArgs::returnMap(method: 'X', map: [...]))
require __DIR__ . '/vendor/autoload.php';
use PhpParser\Lexer\Emulative; use PhpParser\Node; use PhpParser\NodeFinder; use PhpParser\ParserFactory;
$support = null; $files = [];
foreach (array_slice($argv, 1) as $a) { if (str_starts_with($a, '--support=')) { $support = substr($a, 10); continue; } $files[] = $a; }
$lexer = new Emulative(['usedAttributes' => ['startLine', 'endLine', 'startFilePos', 'endFilePos']]);
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
$M = ['find', 'findSilent', 'saveObject', 'deleteObject', 'patchObject'];
foreach ($files as $file) {
	$code = file_get_contents($file); $ast = $parser->parse($code); $edits = [];
	foreach ((new NodeFinder())->findInstanceOf($ast, Node\Expr\MethodCall::class) as $c) {
		if (!$c->name instanceof Node\Identifier || $c->name->toString() !== 'willReturnMap') continue;
		$n = $c->var; $method = null;
		while ($n instanceof Node\Expr\MethodCall) { if ($n->name instanceof Node\Identifier && $n->name->toString() === 'method' && isset($n->args[0]) && $n->args[0]->value instanceof Node\Scalar\String_) $method = $n->args[0]->value->value; $n = $n->var; }
		if (!in_array($method, $M, true)) continue;
		$recvText = substr($code, $n->getAttribute('startFilePos'), $n->getAttribute('endFilePos') - $n->getAttribute('startFilePos') + 1);
		if (!preg_match('/bj|^\$os$|^\$this->os$|^\$service$|^\$mock$/', $recvText)) { echo "MAPRECV\t$file\t$recvText\n"; continue; }
		$nameStart = $c->name->getAttribute('startFilePos'); $open = strpos($code, '(', $c->name->getAttribute('endFilePos'));
		$close = $c->getAttribute('endFilePos');
		$edits[] = [$close, $close, '))'];
		$edits[] = [$nameStart, $open, "willReturnCallback(LegacyObjectArgs::returnMap(method: '$method', map: "];
		echo "MAP\t$file:" . $c->getLine() . "\t$method\n";
	}
	if ($edits === []) continue;
	usort($edits, fn($a, $b) => $b[0] <=> $a[0]);
	foreach ($edits as $e) $code = substr($code, 0, $e[0]) . $e[2] . substr($code, $e[1] + 1);
	$short = substr($support, strrpos($support, '\\') + 1);
	if (!preg_match('/^use ' . preg_quote($support, '/') . ';/m', $code)) {
		preg_match_all('/^use\s+[A-Za-z0-9_\\\\]+(\s+as\s+\w+)?\s*;[ \t]*$/m', $code, $mm, PREG_OFFSET_CAPTURE);
		$last = end($mm[0]); $pos = $last[1] + strlen($last[0]);
		$code = substr($code, 0, $pos) . "\nuse $support;" . substr($code, $pos);
	}
	file_put_contents($file, $code);
}
