# The activity page lists every event and folds none

Folding repeated events was v3's burst idea: ten wrong answers from one address in a minute collapse into one line with a count. Keystone lists every event as its own entry.

- **Pages are made of rows, and folding is made of runs.** The page is cursor-paginated by 15 events. A run of identical events can start on one page and end on the next, so folding would either split the run into two entries with two counts or make the page read the next page's rows to merge them. The first misreports how many times something happened, and the second makes each page cost more than its size and lets the cursor skip or repeat a row.
- **The detail is what the page is for.** A user looking for an intruder wants each wrong answer's exact time, address and device. A fold keeps the first and last time and drops the rest, so a burst that changed address halfway shows one address or several.
- **A flood is already bounded.** The rate limiter caps the wrong answers per window, and records `limit.tripped` once per window rather than once per refusal. The `challenge.abandoned` digest alert counts its events already. A burst can't write unbounded rows.

## Consequences

- A long burst takes several entries, and so several pages. The failed entries are flagged, which makes them easy to scan past.
- An app that wants folded activity renders it in its own page: the page value hands over plain entries, each with its type, time, address and device, and the app's response hook can fold them.
- If a real account turns out to drown in repeats, the fold belongs in the query, grouping by type, address and device within a day, with the count as a column of the page value. Reopen this decision then.
