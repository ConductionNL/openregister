# Design: computed-values-by-json-ast

## D-1. Auditable beats expressive, for an administered field

Twig can do more. That is the argument against it here: an expression a
functional administrator writes is read later by an auditor, a colleague
and a court. A JSON AST is a tree with named operators, so it diffs
cleanly in a schema history, it cannot reach a function nobody reviewed,
and it is safe by construction rather than safe by sandbox.

Twig stays for schemas authored in code, where the author is a developer
and the review is a pull request.

## D-2. The catalogue is generated from the dispatch

The operator list comes from the evaluator's own dispatch, the way the
property vocabulary comes from the validator. A hand-written list would be
wrong the week `calc-engine-scalar-functions` lands its seven operators.

## D-3. Dependencies are read out of the expression

`dependsOn` written beside an expression is a second source of truth, and
the failure is silent: a dependency you forgot means a value that does not
refresh. The AST is a tree, so the properties it reads are derivable. The
circular detection already in the spec runs on the derived list.

## D-4. Try it before you save it

An expression that is only testable by saving the schema and then saving
an object is an expression nobody experiments with. Evaluation against a
sample payload returns the value or the error, with no schema write, which
is the same shape as the rules engine's dry run.

## D-5. kind

Code, in OpenRegister. dossiq adds one key and one map entry.
