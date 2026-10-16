# Several accounts may hold an address unverified, and the first to verify it keeps it

Keystone lets any number of accounts hold the same address unverified. Only an active account's verified hold blocks others. An account that holds no verified address treats its unverified addresses as verified for itself (sign-in, alerts, recovery), but that is derived and never stored in `verified_address`. A deleted or invalidated account blocks no one.

These rules apply while `email_verification.required` is on. While it is off, registration stores the address unverified, so turning verification on later doesn't verify it retroactively. Until then every active account's address holds it, verified or not: no other account can register, add or verify it, and nothing is reclaimed. Once verification is on, those addresses are ordinary unverified ones and the rules here apply to them.

The first account to verify an address keeps it. In the same transaction, every other account loses its row for that address. Its primary moves to another address, a verified one first. The removal is silent: no event is recorded and no alert is sent. The losing account never proved it owns the address, so it gains nothing from hearing about it. An alert would tell a squatter that the owner just verified the address, which an attacker holding many addresses could act on.

Version 3 kept a unique constraint on each address. It created a placeholder user for an address nobody had proven, and an ownership contest decided who kept it. Version 4 has no claimed rows. A registration creates a real account only after the inbox is proven, so nothing needs a placeholder, and nobody can lock an address by typing it first.

## Consequences

- Registration checks the claimants before it settles, and only an active account holding the address verified stops it. A registration through an emailed link verifies the address, so it takes the row from accounts that only counted it as verified. One left with no address is invalidated, as below. A registration without a link only runs while verification is off, so it verifies nothing and takes no row, and any active holder refuses it.
- Registration locks the losing accounts in id order before it creates the account, so two finishes can't deadlock on the same set.
- Verifying an address from its link locks the verifying account and every holder in one statement in id order, checks the claimants under those locks, and takes the row from every holder but the verifier in the same transaction, so two accounts verifying one address can't deadlock.
- A finish that loses the race for the address rolls back whole. The losing accounts keep their rows, and nothing is recorded or mailed.
- An account may find an address it added unverified gone without notice. When losing an address leaves an active account with none, that account's invalidation gets its own event, `account.invalidated`, and no alert. The address it lost is gone, so an alert would go to an inbox that belongs to someone else now.
- Every path that verifies an address, such as a verification link or recovery, settles the same way through the same removal. A path that can leave an active account with no address must invalidate it.
