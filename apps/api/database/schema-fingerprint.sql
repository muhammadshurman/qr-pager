-- A deterministic text fingerprint of everything this task's migrations create.
--
-- Used to prove the acceptance clause "migrate:rollback runs clean and a re-apply
-- returns the identical schema" (QRPAGER-1). It reads the catalog rather than
-- pg_dump, so it does not depend on the client version matching the server, and it
-- covers the parts a casual table diff would miss: RLS flags, policy expressions,
-- policy roles, column-level grants and whether a trigger is ENABLE ALWAYS.
\pset tuples_only on
\pset format unaligned

SELECT string_agg(line, E'\n' ORDER BY line) FROM (
    SELECT format('table %s rls=%s force=%s owner=%s',
                  c.relname, c.relrowsecurity, c.relforcerowsecurity, pg_get_userbyid(c.relowner)) AS line
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind = 'r'

    UNION ALL
    SELECT format('column %s.%s %s nullable=%s default=%s',
                  table_name, column_name, data_type, is_nullable, coalesce(column_default, '-'))
    FROM information_schema.columns WHERE table_schema = 'public'

    UNION ALL
    SELECT format('policy %s.%s cmd=%s roles=%s using=%s check=%s',
                  c.relname, p.polname, p.polcmd,
                  array_to_string(p.polroles::regrole[], '+'),
                  coalesce(pg_get_expr(p.polqual, p.polrelid), '-'),
                  coalesce(pg_get_expr(p.polwithcheck, p.polrelid), '-'))
    FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid

    UNION ALL
    SELECT format('grant %s %s.%s %s', grantee, table_name, column_name, privilege_type)
    FROM information_schema.column_privileges
    WHERE table_schema = 'public' AND grantee <> 'qrpager_owner'

    UNION ALL
    SELECT format('index %s', indexdef) FROM pg_indexes WHERE schemaname = 'public'

    UNION ALL
    SELECT format('constraint %s.%s %s', c.relname, co.conname, pg_get_constraintdef(co.oid))
    FROM pg_constraint co JOIN pg_class c ON c.oid = co.conrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public'

    UNION ALL
    SELECT format('trigger %s.%s enabled=%s %s',
                  c.relname, t.tgname, t.tgenabled, pg_get_triggerdef(t.oid))
    FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal

    UNION ALL
    SELECT format('enum %s = %s', t.typname, string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder))
    FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid
    JOIN pg_namespace n ON n.oid = t.typnamespace
    WHERE n.nspname = 'public' GROUP BY t.typname

    UNION ALL
    SELECT format('function %s secdef=%s', p.proname, p.prosecdef)
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public'
) parts;
