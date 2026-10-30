# A re-save hands a string-typed JSON value back as stored

## Why
After REFRESH-7, `occ openregister:rematerialise-calculations dossiq case` failed on 46 of 75 cases with "Property 'statusHistory' should be type 'string or null' but is 'array'".

dossiq declares `case.statusHistory` (and 18 sibling properties) as `type: string` holding JSON, on purpose: changing them to `array` would be a breaking schema change. The read path (`SchemaTypeConverter::convertString`) decodes such a value. A PATCH already restores it before saving (`restoreStringTypedValues`). The three system re-saves of a stored row did not, so they handed the decoded array to validation:
- the rematerialise command;
- the referenced-calculation refresher;
- the temporal calculation sweep.

## What changes
All three re-saves restore the stored form of untouched `type: string` values (`SchemaTypeConverter::restoreStringTypedValues`) before saving, exactly as a PATCH does. A caller that deliberately writes an array into a string property is still refused.
