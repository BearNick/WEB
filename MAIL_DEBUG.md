# MAIL_DEBUG.md for slaynet.fun

This playbook diagnoses inbound mail problems for `hello@slaynet.fun` and the
unexpected behavior of `mail.slaynet.fun`.

Do not change DNS from these commands. Run them from a terminal and compare the
results with the records required by the external mail hosting provider.

## 1. Public DNS checks through Cloudflare resolver

Use Cloudflare's public resolver first. These commands show what most clients
are likely to see after cache propagation.

```bash
dig @1.1.1.1 slaynet.fun A +noall +answer
dig @1.1.1.1 slaynet.fun AAAA +noall +answer
dig @1.1.1.1 slaynet.fun CNAME +noall +answer
dig @1.1.1.1 slaynet.fun MX +noall +answer
dig @1.1.1.1 slaynet.fun TXT +noall +answer

dig @1.1.1.1 mail.slaynet.fun A +noall +answer
dig @1.1.1.1 mail.slaynet.fun AAAA +noall +answer
dig @1.1.1.1 mail.slaynet.fun CNAME +noall +answer
dig @1.1.1.1 mail.slaynet.fun MX +noall +answer
dig @1.1.1.1 mail.slaynet.fun TXT +noall +answer
```

Interpretation:

- `slaynet.fun MX` must point to the exact hostnames provided by the external
  mail host. If it points to `mail.slaynet.fun` by mistake, inbound mail depends
  on that host being a real mail server.
- `mail.slaynet.fun A/AAAA/CNAME` should match the mail provider's required
  setup. If it resolves to the website hosting IP, GitHub Pages, Cloudflare
  parking, or another unrelated server, mail clients and senders may connect to
  the wrong place.
- `mail.slaynet.fun CNAME` and `A/AAAA` should not normally coexist. If both are
  visible, check the authoritative records and Cloudflare dashboard.
- `slaynet.fun TXT` should include at most one SPF record beginning with
  `v=spf1`. Multiple SPF TXT records are invalid.

## 2. Find and query authoritative Cloudflare nameservers

First discover the authoritative nameservers:

```bash
dig @1.1.1.1 slaynet.fun NS +short
```

If the domain uses Cloudflare DNS, the results should be Cloudflare names such as
`name.ns.cloudflare.com`. Query each returned nameserver directly. Replace
`<CF_NS_1>` and `<CF_NS_2>` with the names returned above.

```bash
dig @<CF_NS_1> slaynet.fun A +noall +answer
dig @<CF_NS_1> slaynet.fun AAAA +noall +answer
dig @<CF_NS_1> slaynet.fun CNAME +noall +answer
dig @<CF_NS_1> slaynet.fun MX +noall +answer
dig @<CF_NS_1> slaynet.fun TXT +noall +answer

dig @<CF_NS_1> mail.slaynet.fun A +noall +answer
dig @<CF_NS_1> mail.slaynet.fun AAAA +noall +answer
dig @<CF_NS_1> mail.slaynet.fun CNAME +noall +answer
dig @<CF_NS_1> mail.slaynet.fun MX +noall +answer
dig @<CF_NS_1> mail.slaynet.fun TXT +noall +answer

dig @<CF_NS_2> slaynet.fun A +noall +answer
dig @<CF_NS_2> slaynet.fun AAAA +noall +answer
dig @<CF_NS_2> slaynet.fun CNAME +noall +answer
dig @<CF_NS_2> slaynet.fun MX +noall +answer
dig @<CF_NS_2> slaynet.fun TXT +noall +answer

dig @<CF_NS_2> mail.slaynet.fun A +noall +answer
dig @<CF_NS_2> mail.slaynet.fun AAAA +noall +answer
dig @<CF_NS_2> mail.slaynet.fun CNAME +noall +answer
dig @<CF_NS_2> mail.slaynet.fun MX +noall +answer
dig @<CF_NS_2> mail.slaynet.fun TXT +noall +answer
```

Interpretation:

- If authoritative Cloudflare answers differ from `@1.1.1.1`, wait for cache TTL
  or flush local/public resolver cache if available.
- If both Cloudflare authoritative nameservers disagree with each other, the DNS
  zone may be in transition or not fully synchronized.
- If `dig @1.1.1.1 slaynet.fun NS +short` does not return Cloudflare
  nameservers, Cloudflare is not authoritative for the live domain even if the
  zone exists in the Cloudflare dashboard.

## 3. Identify the mail targets from MX

List the MX hosts:

```bash
dig @1.1.1.1 slaynet.fun MX +short
```

For every MX hostname returned, test its address records:

```bash
dig @1.1.1.1 <MX_HOST> A +noall +answer
dig @1.1.1.1 <MX_HOST> AAAA +noall +answer
```

Interpretation:

- MX hostnames must resolve to mail infrastructure operated by the mail provider.
- If an MX hostname has no A/AAAA answer, senders cannot deliver reliably.
- If the MX target is proxied through Cloudflare orange-cloud HTTP proxy, that is
  wrong for mail. Mail hostnames used for SMTP/IMAP/POP should be DNS-only.

## 4. SMTP connectivity tests

Run these against each MX hostname from the previous step. Replace `<MX_HOST>`.

Plain SMTP banner on port 25:

```bash
nc -vz <MX_HOST> 25
openssl s_client -starttls smtp -connect <MX_HOST>:25 -servername <MX_HOST> -crlf
```

Submission ports, if the provider supports authenticated sending:

```bash
nc -vz <MX_HOST> 587
openssl s_client -starttls smtp -connect <MX_HOST>:587 -servername <MX_HOST> -crlf

nc -vz <MX_HOST> 465
openssl s_client -connect <MX_HOST>:465 -servername <MX_HOST> -crlf
```

Manual SMTP recipient check after the `openssl` connection succeeds:

```text
EHLO test.local
MAIL FROM:<postmaster@example.com>
RCPT TO:<hello@slaynet.fun>
QUIT
```

Interpretation:

- A working inbound MX should accept a TCP connection on port `25`.
- `openssl s_client -starttls smtp` should show a certificate and SMTP banner.
  Certificate hostname mismatch can indicate the DNS target is not the expected
  mail provider host.
- `RCPT TO:<hello@slaynet.fun>` should not return `550 Administrative
  prohibition` for a valid mailbox or alias.
- Some residential/hosting networks block outbound port `25`. If `25` times out
  locally, repeat from another network or a VPS before assuming the MX is down.

## 5. POP3S and IMAPS connectivity tests

Use the provider's mailbox server hostname. If the provider says users should
connect to `mail.slaynet.fun`, use that only after confirming DNS points to the
provider. Replace `<MAIL_HOST>`.

IMAPS:

```bash
nc -vz <MAIL_HOST> 993
openssl s_client -connect <MAIL_HOST>:993 -servername <MAIL_HOST> -crlf
```

POP3S:

```bash
nc -vz <MAIL_HOST> 995
openssl s_client -connect <MAIL_HOST>:995 -servername <MAIL_HOST> -crlf
```

Optional STARTTLS variants if the provider documents them:

```bash
nc -vz <MAIL_HOST> 143
openssl s_client -starttls imap -connect <MAIL_HOST>:143 -servername <MAIL_HOST> -crlf

nc -vz <MAIL_HOST> 110
openssl s_client -starttls pop3 -connect <MAIL_HOST>:110 -servername <MAIL_HOST> -crlf
```

Interpretation:

- Successful TCP plus a valid TLS certificate means the mailbox access endpoint
  is reachable.
- If `mail.slaynet.fun` connects but the certificate is for an unrelated domain,
  DNS likely points to the wrong host.
- IMAP/POP success does not prove inbound mail delivery. Inbound delivery depends
  mainly on `slaynet.fun MX`, provider routing, and mailbox/alias state.

## 6. SPF, DKIM and DMARC validation checklist

SPF:

```bash
dig @1.1.1.1 slaynet.fun TXT +short
```

Checklist:

- Exactly one TXT record starts with `v=spf1`.
- The SPF value matches the external mail provider's required include or IP
  mechanism.
- The SPF record ends with the provider-recommended policy, usually `~all` or
  `-all`.
- No obsolete duplicate SPF records exist.

DKIM:

Get the DKIM selector from the mail provider. Replace `<SELECTOR>`.

```bash
dig @1.1.1.1 <SELECTOR>._domainkey.slaynet.fun TXT +short
dig @<CF_NS_1> <SELECTOR>._domainkey.slaynet.fun TXT +short
dig @<CF_NS_2> <SELECTOR>._domainkey.slaynet.fun TXT +short
```

Checklist:

- The DKIM TXT record exists at the exact selector hostname.
- The value begins with `v=DKIM1`.
- Long DKIM values are not truncated or split incorrectly in the DNS provider UI.
- The selector in outbound message headers matches the selector published in DNS.

DMARC:

```bash
dig @1.1.1.1 _dmarc.slaynet.fun TXT +short
dig @<CF_NS_1> _dmarc.slaynet.fun TXT +short
dig @<CF_NS_2> _dmarc.slaynet.fun TXT +short
```

Checklist:

- A TXT record exists and begins with `v=DMARC1`.
- Start with a safe policy such as `p=none` while diagnosing, then tighten later
  only after SPF/DKIM alignment is confirmed.
- `rua=<aggregate-report-address>` is optional but useful for aggregate reports.
- DMARC affects sender authentication. It usually does not fix inbound delivery
  to `hello@slaynet.fun`.

## 7. Probable causes of `550 Administrative prohibition`

Use the exact SMTP response context. A `550 Administrative prohibition` during
`RCPT TO:<hello@slaynet.fun>` usually means the receiving server rejected the
recipient or domain by policy.

Likely causes:

- `slaynet.fun MX` points to the wrong mail server, so the server receiving the
  message is not configured to accept mail for `slaynet.fun`.
- `mail.slaynet.fun` resolves to website hosting or another non-mail endpoint,
  and MX records point there.
- The mailbox or alias `hello@slaynet.fun` does not exist in the external mail
  hosting control panel.
- The domain was not fully added, verified, or activated in the mail provider.
- The provider requires specific MX records and the current records still point
  to an old provider.
- Cloudflare DNS proxy is enabled for a mail hostname that must be DNS-only.
- The recipient, sender, or source IP is blocked by an allow/block rule in the
  mail provider.
- The provider is rejecting unauthenticated relay attempts because the SMTP test
  was run against a submission server instead of the inbound MX server.
- SPF/DKIM/DMARC failures can cause outbound or forwarded mail rejection, but
  they are less likely to be the direct cause of inbound `RCPT TO` rejection for
  a local mailbox.

## 8. Decision tree

If `slaynet.fun MX` is empty:

Likely cause: no inbound route exists. Add the provider's required MX records in
DNS after confirming them in the provider dashboard.

If `slaynet.fun MX` points to `mail.slaynet.fun`:

Likely cause: mail delivery depends on `mail.slaynet.fun`. Verify that
`mail.slaynet.fun A/AAAA/CNAME` points to the mail provider, not the website.

If `mail.slaynet.fun` points to the website host:

Likely cause: the mail hostname is configured as a web host record or proxied
record. Use the provider's documented mail host target and keep mail DNS records
DNS-only.

If authoritative Cloudflare answers are correct but `@1.1.1.1` answers are old:

Likely cause: DNS cache propagation. Wait for TTL or test with another resolver.

If both authoritative Cloudflare nameservers return wrong records:

Likely cause: the live Cloudflare DNS zone contains wrong records. Fix in
Cloudflare only after comparing with the provider's exact instructions.

If port `25` to the MX host is closed or times out from multiple networks:

Likely cause: MX target is wrong, mail provider service is unavailable, or the
hostname is not an inbound SMTP server.

If port `25` connects but `RCPT TO:<hello@slaynet.fun>` returns
`550 Administrative prohibition`:

Likely cause: wrong MX target, domain not enabled at provider, mailbox/alias
missing, or provider-side policy block.

If IMAPS/POP3S works but inbound mail still fails:

Likely cause: mailbox login service is working, but inbound MX routing or
recipient provisioning is wrong.

If SPF has multiple `v=spf1` records:

Likely cause: invalid SPF. Merge them into one provider-approved SPF record.
This affects authentication and deliverability, not basic mailbox existence.

## 9. Safe next actions

1. Save command output with timestamps before making changes.
2. Get the exact DNS requirements from the external mail provider dashboard.
3. Compare provider-required MX, SPF, DKIM and DMARC records with authoritative
   Cloudflare answers.
4. Confirm `hello@slaynet.fun` exists as a mailbox or alias at the provider.
5. Confirm any mail-related DNS records in Cloudflare are DNS-only, not proxied.
6. Change one DNS issue at a time, then re-run the relevant commands after TTL.
7. Do not delete existing DNS records until you understand whether they are used
   for website hosting, verification, or mail authentication.

## 10. Live diagnostics run - 2026-04-14

Scope: diagnostics only for `slaynet.fun`, `mail.slaynet.fun`, and
`hello@slaynet.fun`. No DNS, frontend, or mail server configuration was changed.

### Commands and results

DNS:

```text
$ dig A slaynet.fun +noall +answer
slaynet.fun.          300 IN A 172.67.180.27
slaynet.fun.          300 IN A 104.21.75.182

$ dig A www.slaynet.fun +noall +answer
www.slaynet.fun.      300 IN A 104.21.75.182
www.slaynet.fun.      300 IN A 172.67.180.27

$ dig A mail.slaynet.fun +noall +answer
mail.slaynet.fun.     300 IN A 31.31.197.80

$ dig AAAA mail.slaynet.fun +noall +answer
No answer.

$ dig CNAME mail.slaynet.fun +noall +answer
No answer.

$ dig MX slaynet.fun +noall +answer
slaynet.fun.          300 IN MX 10 sm41.hosting.reg.ru.

$ dig TXT slaynet.fun +noall +answer
slaynet.fun.          300 IN TXT "google-site-verification=kftw5wnMaTcuk7KaEc8qPv4-v4Vm12S29Tyjmh4VRcE"
slaynet.fun.          300 IN TXT "v=spf1 a mx ip4:31.31.197.80 include:_spf.hosting.reg.ru ~all"

$ dig TXT dkim._domainkey.slaynet.fun +noall +answer
dkim._domainkey.slaynet.fun. 300 IN TXT "v=DKIM1; k=rsa; s=email; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC2nsrRavgCJQQ8PlWtqOtIfFtbF7i3a6NT37dic6byxMNeHoBw3YjTZn5Zcn79h6v4XA//QFaUaB5zXiTMeQGb0K+AVHIQP5j4qR87mV8jMWFXJtLe56pudLEPtCKp+NynemEtdRzvg/hsqQL639D9JzOCPfz0/m5GjGb8JXZ1rwIDAQAB"

$ dig TXT default._domainkey.slaynet.fun +noall +answer
No answer.

$ dig TXT _dmarc.slaynet.fun +noall +answer
No answer.

$ dig NS slaynet.fun +noall +answer
slaynet.fun.          21600 IN NS dara.ns.cloudflare.com.
slaynet.fun.          21600 IN NS houston.ns.cloudflare.com.

$ dig @1.1.1.1 A mail.slaynet.fun +noall +answer
mail.slaynet.fun.     300 IN A 31.31.197.80

$ dig @dara.ns.cloudflare.com A mail.slaynet.fun +noall +answer
mail.slaynet.fun.     300 IN A 31.31.197.80

$ dig @houston.ns.cloudflare.com A mail.slaynet.fun +noall +answer
mail.slaynet.fun.     300 IN A 31.31.197.80

$ dig A sm41.hosting.reg.ru +noall +answer
sm41.hosting.reg.ru.  21600 IN A 31.31.197.80

$ dig AAAA sm41.hosting.reg.ru +noall +answer
No answer.
```

Connectivity:

```text
$ nc -vz -w 8 mail.slaynet.fun 25
nc: connect to mail.slaynet.fun (31.31.197.80) port 25 (tcp) timed out: Operation now in progress

$ nc -vz -w 8 mail.slaynet.fun 465
nc: connect to mail.slaynet.fun (31.31.197.80) port 465 (tcp) timed out: Operation now in progress

$ nc -vz -w 8 mail.slaynet.fun 587
nc: connect to mail.slaynet.fun (31.31.197.80) port 587 (tcp) timed out: Operation now in progress

$ nc -vz -w 8 mail.slaynet.fun 993
Connection to mail.slaynet.fun (31.31.197.80) 993 port [tcp/imaps] succeeded!

$ nc -vz -w 8 mail.slaynet.fun 995
Connection to mail.slaynet.fun (31.31.197.80) 995 port [tcp/pop3s] succeeded!
```

TLS:

```text
$ openssl s_client -connect mail.slaynet.fun:465 -servername mail.slaynet.fun -brief
No TLS result. The command hung while connecting and was stopped after the
corresponding TCP check had already timed out.

$ openssl s_client -connect mail.slaynet.fun:993 -servername mail.slaynet.fun -brief
CONNECTION ESTABLISHED
Protocol version: TLSv1.3
Ciphersuite: TLS_AES_256_GCM_SHA384
Peer certificate: CN = *.hosting.reg.ru
Hash used: SHA256
Signature type: RSA-PSS
Verification: OK
Server Temp Key: X25519, 253 bits
DONE

$ openssl s_client -connect mail.slaynet.fun:995 -servername mail.slaynet.fun -brief
CONNECTION ESTABLISHED
Protocol version: TLSv1.3
Ciphersuite: TLS_AES_256_GCM_SHA384
Peer certificate: CN = *.hosting.reg.ru
Hash used: SHA256
Signature type: RSA-PSS
Verification: OK
Server Temp Key: X25519, 253 bits
DONE
```

### Diagnosis

`mail.slaynet.fun` resolves to `31.31.197.80`. The MX target
`sm41.hosting.reg.ru` also resolves to `31.31.197.80`, so current DNS is
internally consistent if REG.RU hosting is the intended mail provider.

Cloudflare is authoritative for the domain. Both authoritative nameservers,
`dara.ns.cloudflare.com` and `houston.ns.cloudflare.com`, return the same A
record for `mail.slaynet.fun` as the public resolver: `31.31.197.80`. This does
not look like a DNS propagation disagreement.

MX is present and points to `sm41.hosting.reg.ru`. It is syntactically valid.
Whether it is correct depends on the provider dashboard, but it matches the same
REG.RU IP as `mail.slaynet.fun`.

SPF is present and has exactly one `v=spf1` record. It is syntactically sane:
`a mx ip4:31.31.197.80 include:_spf.hosting.reg.ru ~all`.

DKIM is present at `dkim._domainkey.slaynet.fun` and begins with `v=DKIM1`.
There is no record at `default._domainkey.slaynet.fun`. That is acceptable only
if the active provider selector is `dkim`, not `default`.

DMARC is not present at `_dmarc.slaynet.fun`. Missing DMARC is not the usual
cause of inbound `550 Administrative prohibition`, but it should be added later
once the provider-required SPF/DKIM setup is confirmed.

Mail access ports `993` and `995` are reachable and present a valid
`*.hosting.reg.ru` certificate. SMTP-related ports `25`, `465`, and `587` timed
out from this host. A port 25 timeout can be caused by local outbound filtering,
provider firewalling, or SMTP service policy. The previous `550 Administrative
prohibition` symptom means at least one SMTP server did answer and reject by
policy or recipient state, so provider-side mailbox/domain configuration remains
a strong suspect.

### Separated issue classes

DNS issue:

- No authoritative DNS mismatch was found for `mail.slaynet.fun`.
- `mail.slaynet.fun` has A only, no AAAA and no CNAME. That is clean.
- MX exists and resolves to a host on the same REG.RU IP.
- Missing DMARC and missing `default` DKIM may affect authentication, but they
  do not directly explain inbound mailbox rejection unless the provider expects
  a different selector.

Mail host issue:

- IMAPS and POP3S are reachable on `31.31.197.80` with a REG.RU certificate.
- SMTP ports `25`, `465`, and `587` timed out from this diagnostic host.
- If the same timeout appears from multiple networks, the likely issue is that
  the REG.RU host, service plan, firewall, or mail routing is not accepting SMTP
  for this domain.
- If SMTP connects elsewhere but returns `550 Administrative prohibition`, the
  likely issue is that `hello@slaynet.fun` is missing, disabled, not routed, or
  the domain is not fully enabled in the provider's mail control panel.

Client/Gmail issue:

- Gmail/client settings are not the primary suspect for inbound delivery if
  senders receive `550 Administrative prohibition`.
- Gmail POP/IMAP import or SMTP-send setup may fail because `465` and `587`
  timed out from this host. For mailbox reading, `993` and `995` are reachable.
- Client issues should be investigated only after confirming the mailbox exists
  and REG.RU accepts inbound SMTP for `hello@slaynet.fun`.

### Top likely causes

1. `hello@slaynet.fun` is not provisioned correctly at REG.RU, or the domain is
   not fully activated/routed for mail there. This best matches
   `550 Administrative prohibition`.
2. SMTP service or firewall policy for `31.31.197.80` is not accepting SMTP on
   `25`, `465`, or `587` from this test host. Repeat from another network or use
   the provider's mail test tools before making DNS changes.
3. Provider-required authentication records are incomplete or selector-specific:
   DKIM exists only at `dkim._domainkey`, and DMARC is missing. This is less
   likely to block inbound delivery, but it can affect outbound deliverability
   and provider verification checks.

### Prioritized next actions

1. In the REG.RU mail/hosting panel, verify that `slaynet.fun` is attached to
   the mail service and that `hello@slaynet.fun` exists as an active mailbox or
   alias.
2. Ask REG.RU or check the provider panel for the exact required MX, SPF, DKIM
   selector, and mail host values. Compare those exact values with the
   authoritative Cloudflare results above.
3. Re-test SMTP port `25` from a different network or external SMTP diagnostic
   service. If it times out everywhere, escalate to REG.RU as an SMTP service or
   firewall issue.
4. Add DMARC only after confirming SPF/DKIM with the provider. Start with a
   safe monitoring policy such as `v=DMARC1; p=none`.
