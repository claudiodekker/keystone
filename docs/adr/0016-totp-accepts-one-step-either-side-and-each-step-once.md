# TOTP accepts one step either side, and each step once

`keystone-totp` accepts a code from the current 30-second step and, by default, from one step either side (`window_steps = 1`), so a code typed as it rolls over, or on a phone whose clock drifts by up to 30 seconds, still gets in. RFC 6238 §5.2 recommends at most one step of backward drift; ASVS 5.0 V6.5.5 asks that a time-based code be valid for no more than 30 seconds. We take one step forward as well as back because phone clocks drift both ways, and we let the app narrow it to `0` (the current step only) or widen it, since a wider window only changes how many codes each guess can hit. This is a deviation from V6.5.5 at the default: a code stays valid for up to 90 seconds.

A window of ±n accepts 2n+1 codes, so each guess succeeds with a probability of about (2n+1)/10⁶: 3 in a million at the default. The failed-attempt limit is what keeps that small: TOTP failures share one count across the challenge, recovery and sudo (20 an hour), under a fixed ceiling of 100 a day that `keystone.rate_limits` doesn't change.

Every accepted code moves the credential's last accepted step forward, and a code from that step or an earlier one is refused as `totp.replayed` (V6.5.1, RFC 6238 §5.2). The new step comes back to core in the proof, and core writes it only while the row still holds the secret that was verified, so two requests racing with the same code sign in once.

## Consequences

- `window_steps` must be a whole number of at least 0; anything else refuses boot. There is no setting for the step length or digit count, since authenticator apps ignore them.
- A code typed on a phone more than one step off is refused like a wrong code. The user fixes the phone's clock; Keystone offers no resync.
- After an accepted code from the step after now, the current step's code is refused until the next step begins.
