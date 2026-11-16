import os, re
os.chdir('/tmp/lanes/ormig/pipelinq')
p='tests/Stubs/Service/ObjectService.php'; s=open(p).read()
if 'function validateObject' not in s:
    i=s.rindex('}//end class')
    add='''
		/**
		 * Validate an object without saving it. Not modelled by the stub.
		 *
		 * @param array<string, mixed> $object   The object.
		 * @param string|int           $register The register.
		 * @param string|int           $schema   The schema.
		 * @param string|null          $id       The object id when it exists.
		 *
		 * @return \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectValidationVerdict Never.
		 */
		public function validateObject(array $object, string|int $register, string|int $schema, ?string $id = null): \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectValidationVerdict {
			throw new \\LogicException('ObjectService stub: validateObject() is not modelled');
		}//end validateObject()

	'''
    s=s[:i].rstrip()+'\n'+add+s[i:]
    open(p,'w').write(s)
import subprocess
_files = subprocess.run('grep -rlE "ProvisionContactIdentityNode|CompetitorWatchRunNode|JourneyActionNode" tests --include=*.php', shell=True, capture_output=True, text=True).stdout.split()
print(subprocess.run(['php', '/tmp/lanes/ormig/tools/executecall.php'] + _files, capture_output=True, text=True).stdout)
# Seeds that call a fake in its own (object, register, schema) order.
print(subprocess.run(['php', '/tmp/lanes/ormig/tools/rewrite.php', '--apply', '--method=saveObject', '--order=object,register,schema', '--recv=/^\\$this->store$/', 'tests/Unit/Service/MessagingOptOutWiringTest.php'], capture_output=True, text=True).stdout[-200:])
import re as _re
def _sub(p, pat, rep, count=0):
    s = open(p).read(); s2, n = _re.subn(pat, rep, s, count=count)
    if n == 0: print('NOSUB', p, pat[:60])
    open(p, 'w').write(s2)
def add_use(s, fq):
    if _re.search(r'^use ' + _re.escape(fq) + r';', s, _re.M): return s
    ms = list(_re.finditer(r'^use [^;]+;[ \t]*$', s, _re.M)); line = 'use ' + fq + ';'
    return s[:ms[-1].end()] + '\n' + line + s[ms[-1].end():]
# Reflection recorders name main's parameters; flatten the option objects into the names they carry.
_p = 'tests/Unit/Service/BerichtenboxQueueGoldenTest.php'
_sub(_p, r"(\n(\t+))\$this->calls\[\] = \['call' => \$target\[1\], 'args'", r"\1$named = LegacyObjectArgs::flatten(args: $named);\1$this->calls[] = ['call' => $target[1], 'args'", count=1)
_s = open(_p).read(); _s = add_use(_s, 'OCA\\Pipelinq\\Tests\\Support\\LegacyObjectArgs'); open(_p, 'w').write(_s)
_p = 'tests/Unit/Repair/BackfillContactChannelArraysGoldenTest.php'
_sub(_p, r"\$args = LegacyObjectArgs::positional\(method: 'saveObject', args: \$args\);\n(\s*)\$named = \$this->named\(method: 'saveObject', args: \$args\);", r"$named = LegacyObjectArgs::flatten(args: $this->named(method: 'saveObject', args: $args));")
# A recorder that keeps the arguments a caller passed, by position.
_p = 'tests/Unit/Service/ContactVcardGoldenTest.php'
_sub(_p, r"\$args = LegacyObjectArgs::positional\(method: 'find', args: \$args\);\n(\s*)\$this->record\(call: 'objects.find', args: \$this->passed\(class: ObjectServiceInterface::class, method: 'find', args: \$args\)", r"$args = LegacyObjectArgs::positional(method: 'find', args: $args, trim: true);\n\1$this->record(call: 'objects.find', args: $args")
_p = 'tests/Unit/Service/Cti/DispositionTaskCreationTest.php'
_sub(_p, r"\$args = LegacyObjectArgs::flatten\(args: \$args\);\n(\s*)return \$this->test->recordSave", r"$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args, trim: true);\n\1return $this->test->recordSave")
_sub(_p, r"('task',\n)\t+null,\n", r"\1")
print(subprocess.run(['php', '/tmp/lanes/ormig/tools/rewrite.php', '--apply', '--method=find', '--order=id,register,schema', '--recv=/^\\$this->objectService$/', 'tests/Integration/BlastWorkflowTest.php'], capture_output=True, text=True).stdout[-200:])
_p = 'tests/Unit/Service/PointsLedgerGoldenTest.php'
_sub(_p, r"\$args = LegacyObjectArgs::positional\(method: 'saveObject', args: \$args\);\n(\s*)\$named = \$this->named\(class: ObjectServiceInterface::class, method: 'saveObject', args: \$args\);", r"$named = LegacyObjectArgs::flatten(args: $this->named(class: ObjectServiceInterface::class, method: 'saveObject', args: $args));")
_p = 'tests/Unit/Controller/BrpLookupGoldenTest.php'
_sub(_p, r"return \$this->saved\(object: \$args\[0\], schema: \(string\)\$args\[3\]\);", "return $this->saved(object: $args[0], schema: (string)$args[1]->target->schema);")
_sub(_p, r"(\n(\t+))\$this->calls\[\] = \[\$this->shortName\(name: \$name\) => \$named\];", r"\1$named = LegacyObjectArgs::flatten(args: $named);\1$this->calls[] = [$this->shortName(name: $name) => $named];", count=1)
_sub(_p, r"\$named\[\$param->getName\(\)\] = \$this->plain\(value: \$args\[\$i\]\);", lambda m: "$named[$param->getName()] = ($args[$i] instanceof \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectSaveOptions || $args[$i] instanceof \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindOptions) ? $args[$i] : $this->plain(value: $args[$i]);")
_s = open(_p).read(); _s = add_use(_s, 'OCA\\Pipelinq\\Tests\\Support\\LegacyObjectArgs'); open(_p, 'w').write(_s)
_sub('tests/Unit/Repair/BackfillLeadRelationsTest.php', r"return parent::saveObject\(\$object, \$extend, \$register, \$schema, \$uuid\);", "return parent::saveObject($object, $options);")
