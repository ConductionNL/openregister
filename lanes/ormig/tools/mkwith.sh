#!/bin/bash
# mkwith.sh <app>: list files with ->method('X')->with( for object-service methods, in the release's order.
cd /tmp/lanes/ormig/$1; : > ../tools/$1-with.txt
for m in find saveObject deleteObject patchObject; do case $m in find) n=id,_extend,files,register,schema,_rbac,_multitenancy,_render,_audit;; saveObject) n=object,extend,register,schema,uuid,_rbac,_multitenancy,silent,_validation,uploadedFiles,currentUser,failIfExists;; deleteObject) n=uuid,register,schema,_rbac,_multitenancy,_retentionSweep,currentUser,permanent;; patchObject) n=objectId,data,register,schema,_rbac,_multitenancy,currentUser;; esac
for f in $(grep -rlzP "method\('$m'\)\s*->with\(" tests --include=*.php | grep -viE 'tests/stubs/'); do echo "$f $m $n" >> ../tools/$1-with.txt; done; done; wc -l < ../tools/$1-with.txt
