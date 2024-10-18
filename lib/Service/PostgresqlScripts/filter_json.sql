SELECT * FROM public.oc_openregister_objects
WHERE register = '2' AND object#>>'{tooi}' = '0268' OR object#>>'{tooi}' = '0935'
ORDER BY id ASC 