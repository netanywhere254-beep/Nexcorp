# Registration hardening — replaced source snapshots

These files are exact snapshots of the implementation branch immediately before the public registration endpoint was added.

They are retained for audit and recovery. Do not edit or overwrite these snapshots. New replacements must be archived in a new dated folder.

## Snapshots

- backend/laravel/routes/web.php
- backend/laravel/app/Http/Controllers/SessionController.php
- backend/laravel/tests/Feature/SessionAuthenticationTest.php

## Why they were replaced

The prior Laravel foundation supported session login, organization selection, tenant context, and logout, but did not yet expose a registration endpoint. The new implementation adds account-only public registration with strict input allow-listing, password confirmation/strength checks, IP throttling, audit logging, and tests proving forged administrator and tenant fields are ignored.

## Restore guidance

Restore a snapshot only after reviewing the current change register and confirming that doing so will not remove later security fixes or tenant authorization behavior.
