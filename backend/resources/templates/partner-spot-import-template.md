# Partner spot import — CSV format

One row per position. The file describes a tree: `external_user_id` is the node,
`external_parent_id` is the edge to the node above it.

## Please do not send us personal data

These four columns are the whole file. **No names, no email addresses, no phone
numbers, no addresses, no join dates.** We are importing positions in a structure,
not people — each member supplies their own details to us directly when they claim
their position, and until then all we need to know about a position is where it
sits and which code opens it.

Any other column in the file is ignored: the values are discarded on read, not
stored, and we report the column names back so you know what we dropped. If your
export cannot omit those columns, send it anyway — but it is better for both of us
if the data never leaves your system.

## Handling the file

`activation_code` is a column of live credentials. Anyone holding a user ID and its
code can take ownership of that position and everything below it, so send the file
over something other than plain email, and expect us to destroy our copy once the
import is committed. We store only a hash of each code from that point on — we
cannot read them back, and neither can anyone who gets into our database.

## Linking your members straight to the claim page

Your claim page is at `https://app.q3.life/partner/<your slug>`. You can put both
credentials in the link so your member arrives at a form that is already filled in:

```
https://app.q3.life/partner/<your slug>?uid=<external_user_id>&code=<activation_code>
```

Both parameters are URL-encoded as usual. `uid` also accepts `user_id` or
`external_user_id`; `code` also accepts `activation_code`. The page immediately
redirects to the clean URL, so the code is out of the address bar before anything
renders — it is not in what they screenshot, bookmark or forward on.

Nothing is claimed by following the link: it only fills the boxes. A link scanner or
prefetcher cannot take somebody's position, and cannot use up an attempt.

Be aware that the code is in a URL, so it will appear in your mailer's click logs and
in our web server access log. The code is single-use and the position locks after five
wrong attempts, so a code recovered from a log after its owner has claimed is worthless
— but if that is still not acceptable to you, tell us and we will hand out short-lived
opaque tokens for the links instead.

## Columns

| Column | Required | Notes |
| --- | --- | --- |
| `external_user_id` | Yes | The ID this member already has with the partner company. Unique within the file. This is half of what they type on the claim page, so it must be the ID they actually know themselves by — not an internal database key. It is also the only label we will ever have for the position until somebody claims it, so an admin has to be able to match it against your own export. |
| `activation_code` | Yes | The one-time code that proves this position is theirs. Unique across the whole file. Treat this column as a password list: send the file over something that is not email, and expect us to destroy our copy after the import commits. |
| `external_parent_id` | No | The external_user_id of the position directly above this one. Leave blank ONLY for the top of a leg — those rows get connected to an existing Quantum partner by hand, on screen, before the import runs. Every non-blank value must appear as an external_user_id somewhere in this same file. |
| `external_sponsor_id` | No | Who recruited this member, if you track that separately from position. Leave the whole column blank if recruitment and placement are the same thing in your system — we will use the parent. |
