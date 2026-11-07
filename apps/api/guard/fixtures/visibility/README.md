# Rule 10 fixtures — object ACLs in application code

`SourceGuard::scanObjectVisibility()` applies to `app/` and `routes/` only (`§1`: "nothing under
`apps/` may depend on which one is running"). `config/` is excluded because the local `public`
disk legitimately declares a visibility for a filesystem that has no ACLs, and `tests/` because
a test proving a refusal has to be able to make the call.

`sets-public-visibility.php.fixture` carries all four matched forms — the `visibility` option
key, the `setVisibility` call, the flysystem `Visibility` constant and a canned `ACL` — plus one
line that must NOT be flagged, `Storage::disk('public')`.
