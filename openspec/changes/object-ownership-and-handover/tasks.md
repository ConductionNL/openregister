# Tasks: object ownership and handover

- [x] 1.1 Refuse a save that names an owner other than the acting user or the stored one
- [x] 1.2 Read the owning group in `ObjectScopeResolver` and admit its members unconditionally
- [x] 1.3 Emit the owning group on the list path so both emitters agree with the verdict
- [x] 1.4 Write the owner as one targeted column update, never through the save path
- [x] 1.5 Take ownership as the acting user, under the rules' update verdict
- [x] 1.6 Record every handover as its own typed audit action naming both owners
- [x] 1.7 Tell the previous owner, with a renderer so the notification is not silent
- [x] 1.8 Assign one record, and reassign many, as an administrator
- [x] 1.9 Carry a handover to children that shared the owner
- [x] 1.10 Route the endpoints on a plain controller so a refusal keeps its status
