# Checking a licence key

For whoever builds a product we sell with its source code (§4, X18). The software asks
this endpoint whether its key is good for the site it runs on. There is no account and no
token: the key is the credential.

## The request

```
POST https://<our site>/api/v1/licences/check
Content-Type: application/json
Accept: application/json

{
  "key": "UBS-7K3QX-M2D9F-...",
  "site": "clinic.example.com",
  "product": "clinic-desk",
  "version": "1.3.0"
}
```

| Field | | |
|---|---|---|
| `key` | required | As the buyer pasted it. Case, spaces and dashes do not matter. |
| `site` | required | The address the software is served at. A full URL is fine; the host is taken from it, without `www.`. |
| `product` | recommended | The product's slug, as in its page address. A key for another product is answered as not valid. |
| `version` | optional | The version running, so the answer can say whether a newer one is out. |

## The answer

Always `200` with JSON, except `422` when `site` is not an address and `429` when an
address asks too often (30 a minute, 300 an hour).

A good key:

```json
{
  "valid": true,
  "status": "active",
  "product": "clinic-desk",
  "site": "clinic.example.com",
  "development": false,
  "licence": {
    "tier": "Single site",
    "sites": { "used": 1, "limit": 1 },
    "updates_until": "2027-11-18",
    "support_until": "2027-02-18"
  },
  "updates": { "latest_version": "1.4.0", "newer_than_yours": true },
  "check_again_after": 86400
}
```

Not good, with `status` saying why:

| `status` | Meaning |
|---|---|
| `invalid` | No such key, or a key for another product. Both are answered the same. |
| `revoked` | The licence was withdrawn, after a refund for instance. |
| `site_limit` | Every place the tier allows is taken by other sites. The answer carries `sites`. The buyer frees one under Orders in their account. |

Every answer has a `message` to show an administrator when it is not valid, and
`check_again_after`, in seconds.

## Rules for the software

- **Check about once a day, not on every page.** Keep the last answer and ask again after
  `check_again_after`. The site runs on shared hosting, where every request counts.
- **Do not stop working because the check could not be made.** A network failure, a
  timeout or a `429` is not an answer: keep the last good one, and try later.
- **A site takes a place the first time it checks** and keeps it until the buyer frees it.
  Moving to a new domain means freeing the old one first.
- **Trying the code out takes no place.** `localhost`, private and loopback addresses, and
  hosts ending in `.localhost`, `.test`, `.local`, `.example` or `.invalid` are answered
  with `development: true`.
- **Show updates, never fetch them.** `newer_than_yours` is a prompt. Downloads are made
  from the buyer's account, through links that expire, and only for versions released
  inside the licence's update window.
- **Never send anything but the four fields above.** The answer says nothing about who
  holds the licence, and the request should not either.
