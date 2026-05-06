# Security Policy

## Reporting a vulnerability

Open a private advisory on GitHub (`Security` → `Report a vulnerability`) or email `sander@hihaho.com`. Please do not file public issues for security bugs.

## Supported versions

Only the latest minor release receives security fixes. Pin to a version you can keep updated.

## Operator responsibilities

`laravel-x402` signs outbound payments and verifies inbound payments. It does **not** custody funds — but it does sign with operator-supplied EVM private keys and persist nonces against replay.

### Private key handling

`X402_PRIVATE_KEY` is the buyer-side signing credential. Treat it as a production secret:

- **Never** commit it; use `.env` plus your platform's secret manager (AWS Secrets Manager, GCP Secret Manager, HashiCorp Vault, etc.).
- The default `PrivateKeyWallet` keeps the key in PHP process memory for the duration of a request. This is acceptable for development and single-tenant hosts. Multi-tenant deployments should bind a custom `Wallet` implementation that delegates to a remote signer.
- Rotate immediately on any incident — the on-chain authorization signature cannot be revoked, only out-bid by a higher-nonce settlement.

### Nonce store

`Cache::add()` (Redis/Memcached/Database driver) is required for atomic replay protection across multiple workers. The default Laravel cache store works only when it points at a shared backend. **A single-process array driver in production means no cross-request replay protection.**

### Recipient address

`X402_RECIPIENT` cannot be overridden by inbound traffic — it's read from config at request time. But if your host's config can be poisoned (e.g. a writable `.env`), an attacker could redirect payments. Keep config files read-only at the OS level.

## Facilitator trust

Default facilitator: `https://x402.org/facilitator` (Coinbase). The facilitator can refuse to settle a payment but cannot redirect funds — the EIP-3009 signature cryptographically binds `to` and `value`. For self-hosting use `config('x402.facilitator.url')` to point at your own endpoint (e.g. `x402-rs`).

## Authorization

The middleware gates routes at the HTTP layer. It does **not** check operator-defined ACLs — if your route requires "user X paid AND has role Y", combine `x402` middleware with your existing auth middleware. The order matters: place auth before payment so unauthenticated users get 401 (not 402, which would invite them to pay for something they can't access).
