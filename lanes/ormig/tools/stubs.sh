#!/bin/bash
# Bring an app's tests/Stubs to openregister main for the object-service contract.
# usage: stubs.sh <app-dir>
set -e
APP=$1; OR=/tmp/lanes/ormig/openregister/lib; T=${STUBROOT:-$APP/tests/Stubs}
[ -d "$T" ] || { echo "no tests/Stubs in $APP"; exit 0; }
mkdir -p $T/Kernel/Contracts/Value
for c in ObjectValidationVerdict ObjectFindOptions ObjectSaveOptions ObjectFindTarget SavePolicy SaveActor ObjectDeletion ObjectPatch LockRequest LockRunStep UnlockRequest BulkSaveOptions BulkBatchDefaults; do
  f=$(grep -rl "^final class $c\b\|^class $c\b" $T --include=*.php | head -1)
  [ -n "$f" ] || f=$T/Kernel/Contracts/Value/$c.php
  cp $OR/Kernel/Contracts/Value/$c.php $f
done
# Interfaces: overwrite wherever the app declares them, else add at the PSR-4 path.
put() { # $1 = path under lib, $2 = kind+name regex
  local rel=$1 name=$(basename $1 .php)
  local f=$(grep -rlP "^\\s*(interface|final class|class|abstract class) $name\\b" $T --include=*.php | head -1)
  if [ -z "$f" ] && [ "$3" = "ifexists" ]; then return; fi
  [ -n "$f" ] || { f=$T/$rel; mkdir -p $(dirname $f); }
  cp $OR/$rel $f; echo "stub $rel -> ${f#$APP/}"
}
put Contract/ObjectServiceInterface.php
put Service/Flow/IFlowNode.php x ifexists
put Service/Flow/ICallFlowNode.php
put Service/Flow/FlowNodeCall.php
put BulkAction/BulkActionInterface.php x ifexists
put BulkAction/StepBulkActionInterface.php
put BulkAction/BulkStep.php x ifexists
if [ -z "$SKIP_LIFECYCLE" ]; then
put Lifecycle/LifecycleActionInterface.php x ifexists
put Lifecycle/ContextLifecycleActionInterface.php
put Lifecycle/LifecycleActionContext.php
fi
if grep -rqs 'RefIntegrationProvider' $APP/lib; then
put Service/Integration/IntegrationProvider.php
put Service/Integration/RefIntegrationProvider.php
put Kernel/Contracts/ObjectRef.php
put Kernel/Contracts/RegisterSchemaRef.php
fi
