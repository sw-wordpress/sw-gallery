# Security policy

## Reporting a vulnerability

Please report vulnerabilities **privately** through the repository's GitHub security advisories:
<https://github.com/sw-wordpress/sw-gallery/security/advisories/new>

Do not open a public issue for a security problem. Include the plugin version, WordPress and PHP versions, the steps to
reproduce and the impact you see. Test only against your own throw-away installation.

Supported: the latest released version. The threat model, the review checklist and the known limitations are in
[`docs/security.md`](docs/security.md).

In short, the known limitations (details there): private, draft and password galleries hide the markup, not the upload URLs; no download protection;
registered layouts are trusted PHP code; no telemetry and no outbound requests.
