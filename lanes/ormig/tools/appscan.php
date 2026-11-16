<?php
require '/tmp/lanes/ormig/tools/vendor/autoload.php';
use PhpParser\ParserFactory; use PhpParser\NodeTraverser; use PhpParser\NodeVisitor\NameResolver; use PhpParser\Node; use PhpParser\NodeVisitorAbstract; use PhpParser\NodeVisitor\ParentConnectingVisitor;
$root=$argv[1]; $refs=[];
$parser=(new ParserFactory)->create(ParserFactory::PREFER_PHP7);
const OR_='OCA\\OpenRegister\\';
function isOr($n){ return is_string($n) && strncmp(ltrim($n,'\\'),OR_,strlen(OR_))===0; }
function tnames($t){ if($t===null) return []; if($t instanceof Node\NullableType) return tnames($t->type); if($t instanceof Node\UnionType||$t instanceof Node\IntersectionType){$r=[];foreach($t->types as $x)$r=array_merge($r,tnames($x));return $r;} if($t instanceof Node\Name) return [$t]; return []; }
function docTypes($doc,$uses,$ns){ $r=[]; foreach(preg_split('/[|<>,\s]+/',$doc) as $p){ $p=ltrim($p,'?'); if($p==='') continue; if($p[0]==='\\'){ $r[]=ltrim($p,'\\'); continue;} $first=explode('\\',$p)[0]; if(isset($uses[strtolower($first)])) { $r[]=$uses[strtolower($first)].substr($p,strlen($first)); } } return $r; }
function strClass($e){ if($e instanceof Node\Expr\ClassConstFetch && $e->class instanceof Node\Name && strtolower($e->name->toString())==='class') return $e->class->toString(); if($e instanceof Node\Scalar\String_) return ltrim($e->value,'\\'); return null; }
function argsOf($args,$pp){ $r=[]; foreach($args as $a){ if($a instanceof Node\VariadicPlaceholder){$r[]=['name'=>null,'unpack'=>true];continue;} $r[]=['name'=>$a->name? $a->name->toString():null,'unpack'=>$a->unpack]; } return $r; }
function tryCtx($node){ $r=[]; $n=$node; while($n=$n->getAttribute('parent')){ if($n instanceof Node\Stmt\TryCatch){ foreach($n->catches as $c){ foreach($c->types as $t) $r[]=$t->toString(); } } if($n instanceof Node\Stmt\ClassMethod || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Expr\Closure) break; } return array_values(array_unique($r)); }
$retIdx=[];
$it0=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach($it0 as $f0){ if(substr($f0,-4)!=='.php') continue; $c0=file_get_contents($f0); if(strpos($c0,'OpenRegister')===false) continue;
 try{$a0=$parser->parse($c0);}catch(\Throwable $e){continue;}
 $t0=new NodeTraverser(); $t0->addVisitor(new NameResolver()); $a0=$t0->traverse($a0);
 $u0=[]; $n0='';
 foreach((new PhpParser\NodeFinder())->findInstanceOf($a0,Node\Stmt\UseUse::class) as $u){ $u0[strtolower($u->getAlias()->toString())]=$u->name->toString(); }
 foreach((new PhpParser\NodeFinder())->findInstanceOf($a0,Node\Stmt\ClassMethod::class) as $mm0){
  $ts=[]; foreach(tnames($mm0->returnType) as $t) $ts[]=$t->toString();
  $d=$mm0->getDocComment(); if($d && preg_match('/@return\s+(\S+)/',$d->getText(),$mx)) $ts=array_merge($ts,docTypes($mx[1],$u0,$n0));
  foreach($ts as $t) if(isOr($t)) $retIdx[$mm0->name->toString()][$t]=1;
 }
}
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach($it as $f){ if(substr($f,-4)!=='.php') continue; $code=file_get_contents($f); if(strpos($code,'OpenRegister')===false) continue;
 try{$ast=$parser->parse($code);}catch(\Throwable $e){continue;}
 $tr=new NodeTraverser(); $tr->addVisitor(new NameResolver(null,['replaceNodes'=>false])); $tr->addVisitor(new ParentConnectingVisitor()); $ast=$tr->traverse($ast);
 $tr2=new NodeTraverser(); $tr2->addVisitor(new NameResolver()); 
 $file=substr($f,strlen($root)+1);
 $uses=[]; $ns='';
 $finder=new PhpParser\NodeFinder();
 foreach($finder->findInstanceOf($ast,Node\Stmt\Namespace_::class) as $n){ $ns=$n->name?$n->name->toString():''; }
 foreach($finder->findInstanceOf($ast,Node\Stmt\UseUse::class) as $u){ $uses[strtolower($u->getAlias()->toString())]=$u->name->toString(); }
 $res=function($name) use($uses,$ns){ if($name instanceof Node\Name){ $rn=$name->getAttribute('resolvedName'); if($rn) return $rn->toString(); return $name->toString(); } return null; };
 foreach($finder->findInstanceOf($ast,Node\Stmt\ClassLike::class) as $cls){
  $cname=$cls->name?$cls->name->toString():'';
  $ptypes=[];
  if($cls instanceof Node\Stmt\Class_){ if($cls->extends && isOr($res($cls->extends))) $refs[]=['k'=>'extends','file'=>$file,'line'=>$cls->getStartLine(),'class'=>$res($cls->extends),'self'=>$cname];
   foreach($cls->implements as $i){ if(isOr($res($i))) $refs[]=['k'=>'implements','file'=>$file,'line'=>$cls->getStartLine(),'class'=>$res($i),'self'=>$cname]; } }
  foreach($finder->findInstanceOf([$cls],Node\Stmt\Property::class) as $p){ $ts=array_map($res,tnames($p->type)); $doc=$p->getDocComment(); if($doc && preg_match('/@var\s+(\S+)/',$doc->getText(),$m)) $ts=array_merge($ts,docTypes($m[1],$uses,$ns)); foreach($p->props as $pp) $ptypes[$pp->name->toString()]=$ts; }
  // methods (incl. their bodies)
  $methods=$finder->findInstanceOf([$cls],Node\Stmt\ClassMethod::class);
  foreach($methods as $m){ if($m->name->toString()==='__construct'){ foreach($m->params as $p){ if($p->flags){ $ts=[]; foreach(tnames($p->type) as $t) $ts[]=$res($t); $ptypes[$p->var->name]=$ts; } } } }
  // record implemented method signatures for implements/extends checks
  $own=[]; foreach($methods as $m){ $own[$m->name->toString()]=['params'=>array_map(fn($p)=>['name'=>$p->var->name,'default'=>$p->default!==null,'variadic'=>$p->variadic,'type'=>$p->type? (new PhpParser\PrettyPrinter\Standard)->prettyPrint([$p->type]):null],$m->params),'return'=>$m->returnType?(new PhpParser\PrettyPrinter\Standard)->prettyPrint([$m->returnType]):null,'line'=>$m->getStartLine()]; }
  $refs[]=['k'=>'classdef','file'=>$file,'self'=>$cname,'methods'=>$own];
  foreach($methods as $m){
   global $retIdx;
   $vtypes=[];
   foreach($m->params as $p){ $ts=[]; foreach(tnames($p->type) as $t) $ts[]=$res($t); $vtypes[$p->var->name]=$ts; if($p->type){ foreach($ts as $t) if(isOr($t)) $refs[]=['k'=>'typehint','file'=>$file,'line'=>$p->getStartLine(),'class'=>$t]; } }
   $doc=$m->getDocComment(); if($doc){ preg_match_all('/@param\s+(\S+)\s+\$(\w+)/',$doc->getText(),$mm,PREG_SET_ORDER); foreach($mm as $x){ $vtypes[$x[2]]=array_merge($vtypes[$x[2]]??[],docTypes($x[1],$uses,$ns)); } }
   foreach(tnames($m->returnType) as $t){ if(isOr($res($t))) $refs[]=['k'=>'returntype','file'=>$file,'line'=>$m->getStartLine(),'class'=>$res($t)]; }
   $nodes=$finder->find($m->stmts??[],fn($n)=>true);
   // pass 1: local var types
   foreach($nodes as $n){
     if($n instanceof Node\Expr\Assign && $n->var instanceof Node\Expr\Variable && is_string($n->var->name)){
       $e=$n->expr; $t=null;
       if($e instanceof Node\Expr\New_ && $e->class instanceof Node\Name) $t=[$res($e->class)];
       elseif($e instanceof Node\Expr\MethodCall && $e->name instanceof Node\Identifier && $e->name->toString()==='get' && count($e->args)>0 && ($c=strClass($e->args[0]->value)) ) { $t=[$c instanceof Node\Name?$res($c):(strpos($c,'\\')===false && isset($uses[strtolower($c)])?$uses[strtolower($c)]:$c)]; }
       elseif($e instanceof Node\Expr\StaticCall && $e->name instanceof Node\Identifier && $e->name->toString()==='get' && count($e->args)>0 && ($c=strClass($e->args[0]->value))) { $t=[$c]; }
       elseif($e instanceof Node\Expr\PropertyFetch && $e->var instanceof Node\Expr\Variable && $e->var->name==='this' && $e->name instanceof Node\Identifier) $t=$ptypes[$e->name->toString()]??null;
       elseif(($e instanceof Node\Expr\MethodCall || $e instanceof Node\Expr\NullsafeMethodCall || $e instanceof Node\Expr\StaticCall) && $e->name instanceof Node\Identifier && isset($retIdx[$e->name->toString()])) $t=array_keys($retIdx[$e->name->toString()]);
       if($t) $vtypes[$n->var->name]=array_merge($vtypes[$n->var->name]??[],$t);
     }
     if(($dc=$n->getDocComment()) && preg_match_all('/@var\s+(\S+)\s+\$(\w+)/',$dc->getText(),$mm,PREG_SET_ORDER)){ foreach($mm as $x) $vtypes[$x[2]]=array_merge($vtypes[$x[2]]??[],docTypes($x[1],$uses,$ns)); }
     if($n instanceof Node\Expr\Instanceof_ && $n->expr instanceof Node\Expr\Variable && is_string($n->expr->name) && $n->class instanceof Node\Name){ $vtypes[$n->expr->name]=array_merge($vtypes[$n->expr->name]??[],[$res($n->class)]); }
   }
   $recvType=function($e) use(&$recvType,$vtypes,$ptypes,$res,$uses){
     if($e instanceof Node\Expr\Variable && is_string($e->name)) return $vtypes[$e->name]??[];
     if($e instanceof Node\Expr\PropertyFetch && $e->var instanceof Node\Expr\Variable && $e->var->name==='this' && $e->name instanceof Node\Identifier) return $ptypes[$e->name->toString()]??[];
     if($e instanceof Node\Expr\New_ && $e->class instanceof Node\Name) return [$res($e->class)];
     if(($e instanceof Node\Expr\MethodCall||$e instanceof Node\Expr\StaticCall) && $e->name instanceof Node\Identifier && $e->name->toString()==='get' && count($e->args)>0 && ($c=strClass($e->args[0]->value))) return [strpos($c,'\\')===false && isset($uses[strtolower($c)])?$uses[strtolower($c)]:$c];
     if(($e instanceof Node\Expr\MethodCall || $e instanceof Node\Expr\NullsafeMethodCall) && $e->name instanceof Node\Identifier && isset($GLOBALS['retIdx'][$e->name->toString()])) return array_keys($GLOBALS['retIdx'][$e->name->toString()]);
     return [];
   };
   foreach($nodes as $n){
     $line=$n->getStartLine();
     if($n instanceof Node\Expr\New_ && $n->class instanceof Node\Name && isOr($res($n->class))) $refs[]=['k'=>'new','file'=>$file,'line'=>$line,'class'=>$res($n->class),'method'=>'__construct','args'=>argsOf($n->args,null),'catch'=>tryCtx($n),'fn'=>$m->name->toString()];
     elseif($n instanceof Node\Expr\StaticCall && $n->class instanceof Node\Name && $n->name instanceof Node\Identifier){ $c=$res($n->class); if(isOr($c)) $refs[]=['k'=>'static','file'=>$file,'line'=>$line,'class'=>$c,'method'=>$n->name->toString(),'args'=>argsOf($n->args,null),'catch'=>tryCtx($n),'fn'=>$m->name->toString()]; }
     elseif($n instanceof Node\Expr\ClassConstFetch && $n->class instanceof Node\Name && $n->name instanceof Node\Identifier && strtolower($n->name->toString())!=='class'){ $c=$res($n->class); if(isOr($c)) $refs[]=['k'=>'const','file'=>$file,'line'=>$line,'class'=>$c,'const'=>$n->name->toString()]; }
     elseif(($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall) && $n->name instanceof Node\Identifier){
       $ts=array_values(array_unique(array_filter($recvType($n->var),'isOr')));
       $rec=['k'=>'call','file'=>$file,'line'=>$line,'method'=>$n->name->toString(),'args'=>argsOf($n->args,null),'catch'=>tryCtx($n),'types'=>$ts,'fn'=>$m->name->toString(),'usesResult'=>!($n->getAttribute('parent') instanceof Node\Stmt\Expression)];
       $refs[]=$rec;
     }
     elseif($n instanceof Node\Expr\Instanceof_ && $n->class instanceof Node\Name && isOr($res($n->class))) $refs[]=['k'=>'instanceof','file'=>$file,'line'=>$line,'class'=>$res($n->class)];
     elseif($n instanceof Node\Stmt\Catch_){ foreach($n->types as $t) if(isOr($res($t))) $refs[]=['k'=>'catch','file'=>$file,'line'=>$line,'class'=>$res($t)]; }
     elseif(($n instanceof Node\Scalar\String_) && isOr($n->value) && preg_match('/^\\\\?OCA\\\\OpenRegister\\\\[\w\\\\]+$/',$n->value)) $refs[]=['k'=>'string','file'=>$file,'line'=>$line,'class'=>ltrim($n->value,'\\')];
     elseif($n instanceof Node\Expr\ClassConstFetch && $n->class instanceof Node\Name && strtolower($n->name->toString())==='class' && isOr($res($n->class))) $refs[]=['k'=>'classref','file'=>$file,'line'=>$line,'class'=>$res($n->class)];
   }
  }
 }
}
echo json_encode($refs, JSON_UNESCAPED_SLASHES);
