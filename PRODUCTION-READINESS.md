# Production readiness and operational sign-off

## Deployment
- [ ] Render web service is deployed from the intended GitHub main branch.
- [ ] The custom/onrender.com hostname redirects HTTP to HTTPS and presents a valid certificate.
- [ ] Set APP_URL and PESAPAL_CALLBACK_URL to the exact HTTPS hostname.
- [ ] APP_DEBUG=false; SESSION_SECURE=true; secrets are only in Render environment variables.
- [ ] Confirm the Supabase project URL/ref matches the production database before applying migrations.

## Database and security
- [ ] Apply database/upgrade_v3.sql after schema.sql and upgrade_v2.sql (or after the existing v2 schema).
- [ ] Confirm the v3 tables exist and run Supabase Security and Performance Advisors.
- [ ] Confirm RLS is enabled on every public-schema table and no service-role key is in browser assets.
- [ ] Configure Supabase automated backups/PITR appropriate to the subscribed plan; verify retention and export options.
- [ ] Perform a restore drill into a separate non-production project and document recovery time/data-loss objectives.
- [ ] Configure Render deploy-failure notifications, review application/request logs, and monitor `/health` plus `/ready` from an external uptime monitor.
- [ ] Confirm the daily Render Cron Job has the production Supabase URL/service-role key and has completed at least one successful run.
- [ ] Review account suspension, audit history, rate limiting, upload validation and abuse reports.

## Pesapal
- [ ] Complete seller advert checkout in Pesapal sandbox.
- [ ] Complete seller promotion-bid checkout in Pesapal sandbox.
- [ ] Test success, pending, failed, cancelled, duplicate IPN, delayed callback, mismatched amount, currency and tracking ID.
- [ ] Verify no advert or promotion becomes active from a browser redirect alone.
- [ ] Obtain Pesapal production merchant approval and live credentials; switch to live only after sandbox sign-off.
- [ ] Record transaction references and evidence for sign-off.

## Functional tests
- [ ] Register, login, logout, password recovery and email/account recovery.
- [ ] Buyer-to-correct-seller messaging and cross-account access denial.
- [ ] Owner/Admin dashboards, seller suspension, moderation, audit history and support queue.
- [ ] Legal links, category/search/listing pages, footer links and mobile/desktop layout.
- [ ] Replace all legal/support contact placeholders with verified company contact details and obtain Ugandan legal review.
- [ ] Advert expiry and stale pending-checkout cleanup cron.

A checked item requires a real test in the deployed environment. A code commit or a token-connectivity check is not proof that the corresponding workflow passed.
