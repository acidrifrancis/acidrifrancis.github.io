# Pesapal sandbox and production test plan

The application supports two separate payment products: seller advert publication and paid seller promotion. Both must be verified server-to-server. A browser return URL is never payment proof.

## Environment
- Start with `PESAPAL_ENVIRONMENT=sandbox` (or legacy `PESAPAL_ENV=sandbox`).
- Set sandbox `PESAPAL_CONSUMER_KEY` and `PESAPAL_CONSUMER_SECRET`.
- Set `PESAPAL_CURRENCY=UGX`, exact HTTPS `APP_URL`, and `PESAPAL_CALLBACK_URL=https://YOUR-DOMAIN/payment/return`.
- Register `https://YOUR-DOMAIN/pesapal/ipn` in Pesapal and store its ID as `PESAPAL_NOTIFICATION_ID` (legacy alias: `PESAPAL_IPN_ID`).
- Never put secrets in GitHub, SQL, screenshots or support messages.

## Connection check is not transaction testing
Admin → System checks verifies Supabase access and that Pesapal returns an API token. It does not create an order, collect money, verify an IPN, or certify production readiness.

## Required end-to-end test matrix
1. Seller registration and login: free access; no advert fee during account creation.
2. Seller advert checkout: selected duration price plus disclosed fee; advert remains hidden while pending.
3. Successful payment: complete a sandbox payment; verify Pesapal status by tracking ID; amount and currency must match the stored order; only then advert becomes active and expiry starts.
4. Failed, cancelled, pending and unknown statuses: no publication.
5. Callback and IPN: test each independently, duplicate notifications, out-of-order delivery, missing tracking ID, wrong merchant reference, wrong amount and wrong currency.
6. Replay/idempotency: repeated success notifications must not duplicate payment records or reset the original expiry.
7. Promotion: bid checkout amount is charged through a distinct promotion order; no promotional slot until server verification and admin approval; failed/abandoned bid checkout remains inactive.
8. Buyer messaging: connect from advert A and prove only advert A's seller receives the conversation; third-party account cannot read it.
9. Account recovery, suspension, reports, support tickets and disputes.
10. Owner and Admin role boundaries; seller cannot access admin endpoints.
11. Mobile, tablet and desktop; keyboard and screen-reader basics.

Record order reference, tracking ID, verified amount/currency, callback result, IPN result, advert URL and test timestamp for sign-off.

## Documentation rule
A verified successful advert payment publishes the advert automatically. Owner/Admin can later reject or suspend it for moderation. This replaces older instructions that said successful payments enter a mandatory pending-review state.

## Production approval
Switch to live only after all sandbox cases pass, Pesapal confirms merchant production approval and the merchant account is configured for UGX. Then run a low-value controlled live transaction with the business owner's approval, reconcile the Pesapal dashboard against the marketplace payment ledger, and record the sign-off. Never use real customer payment details for testing.
