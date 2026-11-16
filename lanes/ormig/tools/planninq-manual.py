import os
os.chdir('/tmp/lanes/ormig/planninq')
p = 'tests/bootstrap-unit.php'; s = open(p).read()
if 'Support/LegacyObjectArgs.php' not in s:
    s = s.rstrip('\n') + "\n\n// Test helpers for OpenRegister's option objects.\nrequire_once __DIR__ . '/unit/Support/ReleaseObjectSignatures.php';\nrequire_once __DIR__ . '/unit/Support/LegacyObjectArgs.php';\n"
    open(p, 'w').write(s)
p = 'tests/unit/Service/DependencyDeleteGoldenTest.php'; s = open(p).read()
s = s.replace("return parent::find($uuid, $_extend, $files, $register, $table, $_rbac, $_multitenancy, $_render, $_audit);",
              "return parent::find($uuid, new \\OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindOptions(register: $register, schema: $table, extend: $_extend, files: $files, render: $_render, audit: $_audit), $_rbac, $_multitenancy);")
open(p, 'w').write(s)
p = 'tests/unit/Service/BoardColumnServiceTest.php'; s = open(p).read()
s = s.replace("""					$uuid = sprintf('00000000-0000-4000-8000-%012d', $this->next);
				}
				return parent::saveObject($object, $options, $_rbac, $_multitenancy);""", """					$uuid = sprintf('00000000-0000-4000-8000-%012d', $this->next);
				}
				$options = new ObjectSaveOptions(target: $options->target, uuid: $uuid, extend: $options->extend, uploadedFiles: $options->uploadedFiles, policy: $options->policy, actor: $options->actor);
				return parent::saveObject($object, $options, $_rbac, $_multitenancy);""")
open(p, 'w').write(s)
p = 'tests/unit/Service/DependencyDeleteGoldenTest.php'; s = open(p).read()
s = s.replace("return parent::deleteObject($hit, $register, $schema, $_rbac, $_multitenancy);",
              "return parent::deleteObject(new ObjectDeletion(uuid: $hit, target: $deletion->target, currentUser: $deletion->currentUser, permanent: $deletion->permanent), $_rbac, $_multitenancy);")
open(p, 'w').write(s)
p = 'tests/unit/Service/TimetableScenarioPublisherTest.php'; s = open(p).read()
s = s.replace("unset($this->stored[(string)$rest['schema']][$uuid]);", "unset($this->stored[(string)$deletion->target->schema][$uuid]);")
open(p, 'w').write(s)
p = 'tests/unit/Service/LabelCascadeGoldenTest.php'; s = open(p).read()
old = "\t\t\t\t$args = LegacyObjectArgs::flatten(args: $args);\n\t\t\t\t$this->calls[] = ['delete', $args];"
if old in s:
    s = s.replace(old, "\t\t\t\t$args = LegacyObjectArgs::flatten(args: $args);\n\t\t\t\t// The recording lists the uuid after the register and schema it was named with.\n\t\t\t\t$args = array_merge(array_diff_key($args, ['uuid' => true]), ['uuid' => $args['uuid']]);\n\t\t\t\t$this->calls[] = ['delete', $args];")
open(p, 'w').write(s)
