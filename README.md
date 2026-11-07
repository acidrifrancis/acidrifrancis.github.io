# NOTHERN UGANDA MARKET CONNECT

A PHP + HTML/CSS/JavaScript marketplace starter for connecting local sellers and buyers in Northern Uganda. Supabase provides authentication, Postgres and advert image storage. Pesapal handles advert checkout. Render runs the PHP application and GitHub hosts the source code.

## Included in this release

- Public marketplace, search and category filtering.
- Supabase email/password registration and login; new accounts are confirmed server-side so sellers can access their accounts immediately.
- Paid advert submission with duration-based pricing.
- Server-side Pesapal checkout creation, IPN and browser-return verification.
- Payment amount and currency checks; adverts are published automatically only after Pesapal confirms payment.
- Buyer/seller inbox, conversation threads, message sending and unread counts.
- Seller dashboard showing advert status.
- Admin overview, advert approval/rejection/suspension, reports moderation, user role management, categories, advert pricing, payment ledger and payment connection checks.
- Admin-configurable pass-through Pesapal fee (fixed, percentage, or both), added to seller checkout.
- Advert image upload to Supabase Storage.
- CSRF protection for mutating API requests.
- Expiry endpoint for a scheduled Render cron job.

## 1. Supabase setup

1. Create a Supabase project.
2. For a new database, open **SQL Editor** and run `database/schema.sql` once.
3. If you already installed the first version's schema, run `database/upgrade_v2.sql` instead. Do not run both files on an already initialized database.
4. For a fresh database, also run `database/upgrade_v2.sql` and then `database/upgrade_v3.sql`. For an existing v2 database, run only `database/upgrade_v3.sql`.
5. In Project Settings → API, copy the Project URL, anon/publishable key, and service-role key.
6. Keep the service-role key private. Put it only in Render environment variables; never put it in browser JavaScript or GitHub.
7. Create a separate website-owner account using the one-time secure setup page described in **Owner account setup** below. Do not register the owner through the seller registration form or promote a seller account.
### Supabase Auth settings

- Configure the Site URL and allowed redirect URLs for your exact HTTPS Render domain, including `https://YOUR-RENDER-DOMAIN/reset-password` for password recovery.
- The application confirms newly registered marketplace accounts server-side so sellers can log in without an email-confirmation wait. Existing unconfirmed accounts are confirmed on their first valid login attempt; Supabase still checks the supplied password before issuing a session.
- The app's PHP backend uses the service-role key only on the server and checks session ownership/administrator permissions before database actions.

### Storage

The SQL setup creates a public `advert-images` bucket with a 5 MB limit and JPG/PNG/WebP MIME restrictions. Advert images are public by design because they appear on public adverts.

### Separate website-owner account setup

The owner is a distinct `owner` role in `public.profiles`; ordinary registrations always receive the `user` role. The owner has the admin dashboard, can publish adverts free of charge, and cannot be demoted through the ordinary user-management controls.

1. In Render → your web service → **Environment**, add:
   - `OWNER_EMAIL`: the private email address reserved for the website owner.
   - `OWNER_SETUP_TOKEN`: a unique random secret of at least 32 characters. Generate a long random value (for example, 48+ random characters). Do not reuse your Supabase or Pesapal credentials.
2. Save the environment changes and wait for Render to redeploy.
3. Open `https://YOUR-RENDER-DOMAIN/owner-setup`.
4. Enter the owner's full name, the exact email configured in `OWNER_EMAIL`, a new password of at least 12 characters, and the `OWNER_SETUP_TOKEN`.
5. Submit once. The page creates the separate Supabase Auth account, confirms its email, and assigns the protected `owner` role. The setup endpoint refuses a second owner account.
6. Go to the normal website login page and sign in with the owner's email and password. Do not use the setup token as the login password.
7. After successful setup, remove `OWNER_SETUP_TOKEN` from Render (or replace it with a newly generated secret stored securely). The owner setup endpoint will no longer accept setup requests until a valid token is configured; the existing owner login continues working.

**Owner login credentials**
- Login page: `https://YOUR-RENDER-DOMAIN/` → **Log in**
- Email/username: the value you entered in `OWNER_EMAIL`
- Password: the password you chose on the owner setup page
- Setup code: `OWNER_SETUP_TOKEN` (used once to create the owner account; it is not the login password)

Keep the owner email and password private. Store them in a password manager. Never put the password, setup token, Supabase service-role key, or Pesapal consumer secret in GitHub, SQL, or public messages.

### Owner dashboard features

The owner can access:
- Business overview and marketplace activity totals.
- Advert moderation: inspect, approve where payment rules permit, reject or suspend adverts.
- Seller/user management and administrator assignment (owner role remains protected).
- Report queue for fraud, prohibited items and misleading adverts.
- Advert duration and UGX pricing management.
- Marketplace category management.
- Payment ledger with seller, advert, order reference, amount and status.
- Pesapal fee pass-through settings and Supabase/Pesapal connection test.
- Free owner adverts with immediate publication and selected expiry duration.

Ordinary sellers retain free registration/login and only pay when submitting an advert. Owner privileges do not remove the payment requirement for seller adverts.

## 2. GitHub

1. Create a GitHub repository.
2. Upload/extract the contents of this ZIP into the repository root (the `Dockerfile`, `render.yaml`, `public`, `src`, and `database` folders should be at the root).
3. Commit and push to the `main` branch.
4. Do not commit `.env` or any secret keys. `.gitignore` excludes `.env`.

## 3. Render deployment

1. In Render, create a new **Blueprint** from the GitHub repository, or create a Docker web service using the included `Dockerfile`.
2. Add the environment variables listed in `.env.example`. `render.yaml` marks secrets and URLs as values you must supply.
3. Set `APP_URL` to your exact HTTPS Render service URL.
4. Set `PESAPAL_CALLBACK_URL` to `https://YOUR-RENDER-DOMAIN/payment/return`.
5. Use the service's HTTPS domain as the IPN notification URL: `https://YOUR-RENDER-DOMAIN/pesapal/ipn`.
6. Start with `PESAPAL_ENV=sandbox`. Use sandbox consumer credentials until all sandbox tests pass.
7. Confirm `https://YOUR-RENDER-DOMAIN/health` returns JSON with `ok: true`.

### Required environment variables

- `APP_URL`
- `SUPABASE_URL`
- `SUPABASE_ANON_KEY`
- `SUPABASE_SERVICE_ROLE_KEY`
- `PESAPAL_ENVIRONMENT` (`sandbox` initially; preferred name; `PESAPAL_ENV` is supported as a legacy alias)
- `PESAPAL_CURRENCY` (`UGX`)
- `PESAPAL_NOTIFICATION_ID` (preferred IPN ID name; `PESAPAL_IPN_ID` is supported as a legacy alias)
- `PESAPAL_CONSUMER_KEY`
- `PESAPAL_CONSUMER_SECRET`
- `PESAPAL_CALLBACK_URL`
- `PESAPAL_IPN_ID` (optional; if blank the app attempts to register the IPN URL through Pesapal)
- `CRON_SECRET` (generate a long random value)

Set `APP_DEBUG=false` in production. Never share your service-role key, Pesapal secret, or CRON secret.

## 4. Pesapal configuration and fee pass-through

1. Create/configure your Pesapal merchant account and obtain sandbox consumer key/secret from Pesapal.
2. Set `PESAPAL_ENV=sandbox`, the sandbox consumer key and secret, `PESAPAL_CURRENCY=UGX`, and the callback URL in Render.
3. Register the IPN URL in Pesapal or allow the app to register it and save the returned IPN ID as `PESAPAL_NOTIFICATION_ID` in Render (legacy alias: `PESAPAL_IPN_ID`).
4. Log in as admin → **Fees & settings**.
5. Configure how the platform passes the payment cost to the seller: no fee, fixed UGX, percentage, or fixed + percentage. Confirm the exact applicable Pesapal merchant charges with Pesapal before entering them. The application does not guess Pesapal's actual fee.
6. The amount charged at checkout is advert price + configured pass-through fee. The seller is the payer; the marketplace owner does not absorb the configured fee.

## 5. Payment verification lifecycle

- A seller submits an advert and selects a duration.
- The server reads the current duration price from Supabase and calculates the configured fee.
- The server creates a `pending_payment` advert and a unique payment record, then requests a Pesapal checkout URL.
- Pesapal redirects the seller to checkout.
- The browser return and Pesapal IPN both ask Pesapal's API for transaction status. A browser redirect alone never publishes an advert.
- The backend checks the stored order reference, tracking ID, amount and currency.
- A completed, amount-matched and currency-matched payment changes the advert directly to `active` and starts its expiry clock.
- Admins can still reject or suspend adverts later for moderation or policy reasons.
- Failed/pending payment statuses do not publish the advert. Duplicate successful notifications are handled idempotently.

## 6. Messaging and moderation

- A logged-in buyer clicks **CONNECT** and sends the seller a private message.
- The seller opens **My dashboard → Open messages** to reply.
- Conversations are tied to an advert and only participants can access them through the API.
- Public users can report an advert after logging in.
- Admins can review/dismiss reports, approve/reject/suspend adverts, manage categories and prices, and promote/demote other users.

## 7. Advert expiry and cleanup

The repository Blueprint defines a daily UTC Cron Job at 00:15 that runs `src/cleanup.php`. It expires published adverts, abandons unpaid advert/promotion checkouts older than 24 hours and prunes old rate-limit events. **Important:** your currently existing Render web service was created as a standalone web service, not as a synced Blueprint, so this YAML change does not create the cron service automatically. Create the Cron Job in Render (or adopt/sync the Blueprint after reviewing any plan changes), and set `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY` to the exact same production database credentials as the web service. Do not paste secrets into GitHub.

The manual endpoint is:

`https://YOUR-RENDER-DOMAIN/cron/expire-adverts?key=YOUR_CRON_SECRET`

The new CLI cleanup job is preferred because it does not expose a secret in a URL. The legacy endpoint remains for manual recovery only; use the exact `CRON_SECRET` stored on the web service and never publish it.

## 8. Policies and customer support

Public policy pages are served at `/policies/privacy.html`, `/policies/terms.html`, `/policies/buyers.html`, `/policies/sellers.html`, and `/policies/refunds.html`. The policy drafts contain contact/legal review placeholders. Add verified support contact details before launch. Replace these with verified business contact details and obtain Ugandan legal review before public launch.

## 9. Sandbox test checklist

See `docs/PESAPAL-SANDBOX-TESTING.md` for detailed test cases. At minimum, verify:

- Supabase connectivity and Pesapal token test from Admin → Payment test.
- Successful sandbox payment with correct amount/currency.
- Failed/cancelled payment does not publish.
- A forged or mismatched amount/currency does not publish.
- Duplicate IPN does not extend or duplicate publication.
- Successful advert payment publishes automatically after verified Pesapal status; Owner/Admin may subsequently reject or suspend it for policy reasons.
- Seller can message buyer and buyer can reply.
- A user cannot open a conversation they do not participate in.
- Reports appear in the admin moderation queue.

## Important production notes

This ZIP contains a working application codebase and integrations, but a real Pesapal transaction cannot be certified until you supply merchant sandbox credentials and complete the live sandbox checkout on your merchant account. The payment test button verifies Supabase connectivity and Pesapal authentication only; it does not charge a card or fake a completed payment.

Before going live, complete all sandbox tests, review your legal/privacy/terms pages, configure backups and monitoring, confirm Pesapal production approval, and switch to live credentials only after successful sandbox sign-off.
