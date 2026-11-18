# Account verification and recovery

The Laravel primary uses session-based authentication and requires verified email addresses before access to the NexCore AI workspace.

## Registration and verification

- `POST /auth/register` creates a basic active user only; it does not accept roles, organization memberships, or admin flags.
- Registration sends a signed verification email.
- `GET /auth/email/verify/{id}/{hash}` accepts a temporary signed link. It can be opened without an authenticated session because the URL signature and email hash bind the link to the account.
- `POST /auth/login` allows an unverified user to establish a restricted session and returns `email_verification_required: true`. The user can then request another verification email.
- `POST /auth/email/verification-notification` requires an authenticated session and is rate-limited.
- NexCore AI routes require authentication, verified email, and a server-verified active organization.

## Password recovery

- `POST /auth/forgot-password` accepts an email address and always returns the same generic response to avoid disclosing whether an account exists.
- Laravel stores only a hashed password-reset token in `password_reset_tokens`.
- `GET /auth/reset-password/{token}?email=...` is the named reset-link landing endpoint and returns the token/email for the frontend to present.
- `POST /auth/reset-password` requires email, token, a strong confirmed password, and consumes the token. Successful resets rotate the remember token and write an audit event.
- Reset requests and submissions are rate-limited.

## SMTP configuration

Configure these variables in `infra/.env.primary` before using real mail delivery:

- `MAIL_MAILER=smtp`
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
- `MAIL_SCHEME` (leave blank for the transport default, or set a scheme supported by the SMTP provider)
- `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`

The Compose stack passes these settings into the Laravel container. Do not commit credentials. The example values are placeholders; a local log mailer is useful for development but does not deliver email to users.

## Verification boundaries

This is an API/backend foundation. A polished frontend verification notice and password-reset form still need to be wired into the web experience. Configure and test SMTP delivery in a controlled environment before enabling public signups. Do not treat CI passing as proof that external email delivery works.
