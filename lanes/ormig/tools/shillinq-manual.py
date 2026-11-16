import subprocess, os
os.chdir('/tmp/lanes/ormig/shillinq')
files = subprocess.run('grep -rlE "LeafProvider" tests --include=*.php', shell=True, capture_output=True, text=True).stdout.split()
print(subprocess.run(['php', '/tmp/lanes/ormig/tools/refcall.php', r'/provider|Provider|leaf|Leaf/'] + files, capture_output=True, text=True).stdout[-300:])
T = '/tmp/lanes/ormig/tools/rewrite.php'
def run(*a): print(subprocess.run(['php', T, '--apply'] + list(a), capture_output=True, text=True).stdout[-150:])
pos = subprocess.run('grep -rlE "store->(setSchema\\([^)]*\\)->)?(patchObject|saveObject|deleteObject)\\(" tests --include=*.php', shell=True, capture_output=True, text=True).stdout.split()
# Seeds that call the in-memory store positionally in the release's own order.
if pos:
    run('--method=patchObject', '--order=objectId,data,register,schema', '--recv=/store/', *pos)
    run('--method=saveObject', '--order=object,register,schema,uuid', '--recv=/store/', *pos)
    run('--method=deleteObject', '--order=uuid,register,schema', '--recv=/store/', *pos)
# Register/schema entity doubles stand in for OpenRegister's rows, which main's ObjectFindOptions types.
import re
stub = 'tests/stubs/OpenRegister/Kernel/Contracts/'
for name in ['RegisterInterface', 'SchemaInterface']:
    open(stub + name + '.php', 'w').write(f"""<?php

/**
 * Marker for test doubles that stand in for an OpenRegister {name[:-9].lower()} row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\\OpenRegister\\Kernel\\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.conduction.nl
 */

declare(strict_types=1);

namespace OCA\\OpenRegister\\Kernel\\Contracts;

if (interface_exists(__NAMESPACE__ . '\\\\{name}', false) === false) {{
	/**
	 * The real interface declares the row's accessors; the doubles only need the type.
	 */
	interface {name} {{
	}}
}}
""")
IMPL = ' implements \\OCA\\OpenRegister\\Kernel\\Contracts\\RegisterInterface, \\OCA\\OpenRegister\\Kernel\\Contracts\\SchemaInterface'
def mark(p, linenos_pattern):
    s = open(p).read()
    s2, n = re.subn(linenos_pattern, lambda m: m.group(1) + IMPL + ' {', s)
    print('MARK', p, n); open(p, 'w').write(s2)
mark('tests/Unit/Util/Rw124FindOneProbeTest.php', r"(return new class\(\$id, \$slug\)|new class\(\$magic\)|\$this->register = new class\(\)|\$this->schema = new class\(\)) \{")
mark('tests/Unit/Util/ObjectIdentifierScopedFindTest.php', r"(return new class\(\$id, \$slug\)|return new class\(\)) \{")
mark('tests/Unit/Service/Support/InMemoryObjectServiceStub.php', r"(return new class\(\$slug\)) \{")
# The duck adapter hands the release's named values on; a double in main's form takes option objects.
p = 'tests/Unit/Service/Support/DuckObjectServiceAdapter.php'
s = open(p).read()
old = "\t\t$args = [];\n\t\tforeach ($reflected->getParameters() as $index => $parameter) {"
if old in s and 'mainShaped(' not in s:
    s = s.replace(old, "\t\t[$primary, $named] = $this->mainShaped(method: $method, primary: $primary, named: $named);\n" + old, 1)
    helper = '''
	/**
	 * The arguments in OpenRegister main's form: option objects carry register, schema and the rest.
	 *
	 * @param string               $method  The object-service method.
	 * @param mixed                $primary The first argument in the release's form.
	 * @param array<string, mixed> $named   The release's named values.
	 *
	 * @return array{0: mixed, 1: array<string, mixed>} The first argument and the named values, options added.
	 */
	private function mainShaped(string $method, mixed $primary, array $named): array {
		$target = new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindTarget(register: ($named['register'] ?? null), schema: ($named['schema'] ?? null));
		if ($method === 'find') {
			$named['options'] = new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindOptions(register: ($named['register'] ?? null), schema: ($named['schema'] ?? null), extend: ($named['_extend'] ?? []), files: ($named['files'] ?? false));
		}

		if ($method === 'saveObject') {
			$named['options'] = new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectSaveOptions(
				target: $target,
				uuid: ($named['uuid'] ?? null),
				extend: ($named['extend'] ?? []),
				uploadedFiles: ($named['uploadedFiles'] ?? null),
				policy: new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\SavePolicy(silent: ($named['silent'] ?? false), validation: ($named['_validation'] ?? true), failIfExists: ($named['failIfExists'] ?? false)),
				actor: new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\SaveActor(currentUser: ($named['currentUser'] ?? null))
			);
		}

		$first = (new \\ReflectionMethod($this->inner, $method))->getParameters()[0] ?? null;
		$type = (string)$first?->getType();
		if ($method === 'deleteObject' && str_contains($type, 'ObjectDeletion') === true) {
			$primary = new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectDeletion(uuid: (string)$primary, target: $target, currentUser: ($named['currentUser'] ?? null));
		}

		if ($method === 'patchObject' && str_contains($type, 'ObjectPatch') === true) {
			$primary = new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectPatch(objectId: (string)$primary, data: ($named['data'] ?? []), target: $target);
		}

		return [$primary, $named];
	}//end mainShaped()
'''
    i = s.index('\t}//end invokeInner()') + len('\t}//end invokeInner()\n')
    s = s[:i] + helper + s[i:]
    open(p, 'w').write(s)
    print('DUCK')
# FxRateImportJob and HorizonRollingJob call saveObject() positionally in an order neither OpenRegister
# signature has (register in the extend slot); those calls are left as they are, so their doubles are too.
subprocess.run(['git', 'checkout', 'HEAD', '--', 'tests/Unit/BackgroundJob/HorizonRollingJobRunTest.php', 'tests/Unit/BackgroundJob/FxRateImportJobTest.php', 'tests/Unit/BackgroundJob/FxRateImportJobRunEdgesTest.php'])
# The SchemaMapper double is not the object service.
_p = 'tests/Unit/Service/Platform/SchemaDefinitionsTest.php'
_s = open(_p).read().replace("\t\t\t\t$args = LegacyObjectArgs::positional(method: 'find', args: $args, trim: true);\n", ''); open(_p, 'w').write(_s)
# Provider calls in the provider tests, whatever the variable is called.
files = subprocess.run('grep -rlE "LeafProvider" tests/Unit/Integration --include=*.php', shell=True, capture_output=True, text=True).stdout.split()
print(subprocess.run(['php', '/tmp/lanes/ormig/tools/refcall.php', r'/^\$\w+$/'] + files, capture_output=True, text=True).stdout[-200:])
