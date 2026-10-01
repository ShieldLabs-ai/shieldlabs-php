# Shared test fixtures

Shared test data for the ShieldLabs server SDKs: History API responses, webhook
deliveries, signature vectors, Management API profiles, error responses and the
expected normalized identifications. Every ShieldLabs server SDK runs the same
files, so do not edit them by hand. The `.raw.txt` files hold exact request bodies:
keep their bytes unchanged (no added newline).

They come from `contract/` in [shieldlabs-openapi](https://github.com/ShieldLabs-ai/shieldlabs-openapi/tree/main/contract): `contract-sync.json` maps each file, `.shieldlabs-contract.lock` records the release they come from, CI runs `python3 scripts/sync_contract.py --check`, and the `contract-sync.yml` workflow opens a pull request when a new release changes them.
