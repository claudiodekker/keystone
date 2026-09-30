# TOTP keeps HMAC-SHA1

`keystone-totp` makes its codes with HMAC-SHA1, six digits and 30-second steps, the defaults of RFC 6238, and implements the algorithm itself on top of `paragonie/constant_time_encoding` rather than depending on a TOTP library. The implementation is a few lines, is checked against RFC 6238 Appendix B, and leaves no third party between a typed code and the account.

ASVS 5.0 V11.4.1 asks for approved hash functions, and SHA-1 is no longer approved for signatures. HMAC-SHA1 is still sound as a keyed function: its security rests on the key, not on SHA-1's collision resistance, which is what is broken. Every widely used authenticator app implements SHA-1, while SHA-256 and SHA-512, though in the RFC, are ignored or mishandled by several, which would show a working QR code that makes wrong codes. We keep SHA-1 and record this as a deviation from V11.4.1.

## Consequences

- Enrollment will make 160-bit keys. A key of another length, such as an imported 80-bit one, still works.
- There is no algorithm setting. Adding SHA-256 later would store the algorithm with each credential, not switch every account at once.
