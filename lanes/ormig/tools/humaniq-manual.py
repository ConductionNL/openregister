import os, re, shutil, glob
os.chdir('/tmp/lanes/ormig/humaniq')
OR = '/tmp/lanes/ormig/openregister/lib/Kernel/Contracts/Value/'
d = 'tests/stubs/OpenRegister/Kernel/Contracts/Value/'
os.makedirs(d, exist_ok=True)
for c in ['ObjectFindOptions', 'ObjectSaveOptions', 'ObjectFindTarget', 'SavePolicy', 'SaveActor', 'ObjectDeletion', 'ObjectPatch', 'LockRequest', 'LockRunStep', 'UnlockRequest', 'BulkSaveOptions', 'BulkBatchDefaults']:
    shutil.copy(OR + c + '.php', d)
p = 'tests/bootstrap.php'; s = open(p).read()
if 'stubs/OpenRegister/Kernel/Contracts/Value' not in s:
    s = s.rstrip('\n') + """

// OpenRegister's object-service option objects, from main, when OpenRegister itself is absent.
spl_autoload_register(
	static function (string $class): void {
		$prefix = 'OCA\\\\OpenRegister\\\\Kernel\\\\Contracts\\\\Value\\\\';
		if (str_starts_with($class, $prefix) === true) {
			$file = __DIR__ . '/stubs/OpenRegister/Kernel/Contracts/Value/' . substr($class, strlen($prefix)) . '.php';
			if (is_file($file) === true) {
				require_once $file;
			}
		}
	}
);
"""
    open(p, 'w').write(s)
p = 'tests/stubs/OpenRegisterFlowStub.php'; s = open(p).read()
if 'interface ICallFlowNode' not in s:
    i = s.index('final class FlowNodeCall {')
    j = s.rfind('/**', 0, i)
    s = s[:j] + """/**
 * A flow node in main's call form: execute() takes one FlowNodeCall.
 */
interface ICallFlowNode extends IFlowNode {
	/**
	 * Run the node.
	 *
	 * @param FlowNodeCall $call The items, the step config and the run context.
	 *
	 * @return array<int, array<string, mixed>> The resulting items.
	 */
	public function execute(FlowNodeCall $call): array;
}

""" + s[j:]
    open(p, 'w').write(s)
import subprocess
T = '/tmp/lanes/ormig/tools/rewrite.php'
print(subprocess.run(['php', T, '--apply', '--method=saveObject', '--order=object,register,schema,uuid', '--recv=/^\\$fake$/', 'tests/Unit/Service/HrDocumentServiceTest.php'], capture_output=True, text=True).stdout[-120:])
print(subprocess.run(['php', T, '--apply', '--method=deleteObject', '--order=uuid,register,schema', '--recv=/store/', 'tests/Unit/Service/LoonaangifteCorrectionServiceTest.php'], capture_output=True, text=True).stdout[-120:])
