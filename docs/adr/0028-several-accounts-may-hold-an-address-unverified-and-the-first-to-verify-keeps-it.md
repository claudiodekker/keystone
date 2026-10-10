# Several accounts may hold an address unverified, and the first to verify it keeps it

Keystone lets any number of accounts hold the same address unverified. Only an active account's verified hold blocks others, and so does an active account that holds the address while it holds no verified address, because its unverified addresses count as verified. A deleted or invalidated account blocks no one.

The first account to verify an address keeps it. In the same transaction, every other account loses its row for that address. Its primary moves to another address, a verified one first. The removal is silent: no event is recorded and no alert is sent. The losing account never proved it owns the address, so it gains nothing from hearing about it. An alert would tell a squatter that the owner just verified the address, which an attacker holding many addresses could act on. The spec's `address.lost` event and alert are dropped for that reason.

Version 3 kept a unique constraint on each address. It created a placeholder user for an address nobody had proven, and an ownership contest decided who kept it. Version 4 has no claimed rows. A registration creates a real account only after the inbox is proven, so nothing needs a placeholder, and nobody can lock an address by typing it first.

## Consequences

- Registration needs no invalidation. It checks the claimants before it settles, and an active account holding the address with no verified address is a claimant that stops the registration. So an active account that loses a row at registration always keeps a verified address.
- Registration locks the losing accounts in id order before it creates the account, so two finishes can't deadlock on the same set.
- A finish that loses the race for the address rolls back whole. The losing accounts keep their rows, and nothing is recorded or mailed.
- An account may find an address it added unverified gone without notice. When losing an address leaves an active account with none, that account's invalidation gets its own event and alert.
- Other paths that verify an address, such as a verification link or recovery, will settle the same way through the same removal. A path that can leave an active account with no address must invalidate it.
