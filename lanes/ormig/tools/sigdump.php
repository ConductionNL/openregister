<?php
require '/tmp/lanes/ormig/tools/vendor/autoload.php';
use PhpParser\ParserFactory; use PhpParser\NodeTraverser; use PhpParser\NodeVisitor\NameResolver; use PhpParser\Node; use PhpParser\PrettyPrinter\Standard;
$root=$argv[1]; $out=[];
$parser=(new ParserFactory)->create(ParserFactory::PREFER_PHP7); $pp=new Standard();
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
function t($ty,$pp){ if($ty===null) return null; return $pp->prettyPrint([$ty]); }
foreach($it as $f){ if(substr($f,-4)!=='.php') continue;
 try{$ast=$parser->parse(file_get_contents($f));}catch(\Throwable $e){continue;}
 $tr=new NodeTraverser(); $tr->addVisitor(new NameResolver()); $ast=$tr->traverse($ast);
 $finder=new PhpParser\NodeFinder();
 foreach($finder->find($ast,fn($n)=>$n instanceof Node\Stmt\ClassLike && isset($n->namespacedName)) as $c){
  $fq=$c->namespacedName->toString();
  $e=['file'=>substr($f,strlen($root)+1),'kind'=>strtolower(substr(strrchr(get_class($c),'\\'),1)),'extends'=>[],'implements'=>[],'methods'=>[],'consts'=>[],'props'=>[]];
  if($c instanceof Node\Stmt\Class_){ if($c->extends) $e['extends'][]=$c->extends->toString(); foreach($c->implements as $i) $e['implements'][]=$i->toString(); $e['final']=$c->isFinal(); $e['abstract']=$c->isAbstract(); }
  if($c instanceof Node\Stmt\Interface_){ foreach($c->extends as $i) $e['extends'][]=$i->toString(); }
  foreach($c->stmts as $s){
   if($s instanceof Node\Stmt\ClassMethod){
    $ps=[];
    foreach($s->params as $p){ $ps[]=['name'=>$p->var->name,'type'=>t($p->type,$pp),'default'=>$p->default!==null,'variadic'=>$p->variadic,'byRef'=>$p->byRef,'promoted'=>$p->flags!==0]; }
    $vis=$s->isPublic()?'public':($s->isProtected()?'protected':'private');
    $e['methods'][$s->name->toString()]=['vis'=>$vis,'static'=>$s->isStatic(),'abstract'=>$s->isAbstract(),'params'=>$ps,'return'=>t($s->returnType,$pp),'line'=>$s->getStartLine()];
   } elseif($s instanceof Node\Stmt\ClassConst){ foreach($s->consts as $k){ $e['consts'][$k->name->toString()]=($s->isPublic()?'public':($s->isProtected()?'protected':'private')); } }
   elseif($s instanceof Node\Stmt\Property){ foreach($s->props as $p){ if($s->isPublic()) $e['props'][]=$p->name->toString(); } }
   elseif($s instanceof Node\Stmt\TraitUse){ foreach($s->traits as $t) $e['traits'][]=$t->toString(); }
  }
  $out[$fq]=$e;
 }
}
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
