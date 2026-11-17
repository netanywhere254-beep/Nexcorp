# Nexora primary application — Laravel + MySQL

This directory is the primary application foundation. It contains a minimal Laravel 12 application, a MySQL-ready Docker image, a health endpoint, and the first shared organization/user/audit migrations.

## Local development

Requirements: PHP 8.2+, Composer 2, and MySQL 8.

1. Copy the .env.example file to .env.
2. Set a unique APP_KEY by running php artisan key:generate.
3. Set your local MySQL connection values in .env.
4. Run composer install.
5. Run php artisan migrate.
6. Start the server with php artisan serve.

The application root is this directory. Do not run Composer from the repository root.

## Scope

This is a bootable application foundation, not the finished NexISP or Loopza product. Authentication screens, roles/permissions, tenant policies, ISP billing, router provisioning, Loopza campaign workflows, and payment integrations still need implementation and tests.

The Docker entrypoint generates a process-local APP_KEY if none is supplied. For persistent environments, set and retain a stable secret in the environment manager. Migrations are deliberately not run automatically on container startup.
