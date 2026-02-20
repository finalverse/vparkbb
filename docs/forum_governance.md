# VictoriaPark Forum Governance (维园网)

This document defines the baseline forum structure, permissions, moderation workflow, and anti-spam settings for `www.victoriapark.io`.

## 1) Forum Taxonomy

### Categories and boards

1. `生活与移民 | Life Abroad & Immigration`
- `海外生活 | Life Abroad`
- `移民签证 | Immigration`

2. `时政与观点 | Current Affairs`
- `时事政经 | Current Affairs`

3. `科技与投资 | Tech & Investment`
- `科技数码 | Tech`
- `投资理财 | Investment`

4. `文化文娱与社区 | Culture & Community`
- `文化历史 | Culture`
- `八卦娱乐 | Gossip / Entertainment`
- `跳蚤市场 | Marketplace`

### Guest visibility policy

- Guest readable boards:
  - `时事政经 | Current Affairs`
  - `科技数码 | Tech`
  - `投资理财 | Investment`
  - `文化历史 | Culture`
  - `八卦娱乐 | Gossip / Entertainment`
- Guest hidden boards (registered users only):
  - `海外生活 | Life Abroad`
  - `移民签证 | Immigration`
  - `跳蚤市场 | Marketplace`

## 2) Permission Matrix

| Role | Capability | Scope | phpBB implementation |
|---|---|---|---|
| Guest | Read/list only on public boards | Per-board | Group `GUESTS` + `ROLE_FORUM_READONLY` on public boards; `ROLE_FORUM_NOACCESS` on private boards |
| Registered | Create topics, reply, edit/delete own posts, report | All postable boards | Group `REGISTERED` + `ROLE_FORUM_STANDARD` |
| Registered | Edit within time window | Global board behavior | `edit_time=30`, `delete_time=30` |
| Trusted | Standard posting + bypass flood + auto-approve + attachments | All postable boards | Group `TRUSTED` + custom role `ROLE_FORUM_TRUSTED` (cloned from standard and enables `f_ignoreflood`, `f_noapprove`, `f_attach`) |
| Moderator (per-board) | Lock, move, soft-delete, approve queue, warn, split/merge/edit moderation actions | Assigned boards only | Group `BOARD_MODERATORS` + `ROLE_MOD_STANDARD` per target board |
| Moderator (per-board) | Sticky/announcement + full board ops | Assigned boards only | Group `BOARD_MODERATORS` + `ROLE_FORUM_FULL` per target board |
| Admin | Board/global settings, users/groups, roles/permissions, extensions, ads/integrations | Global | Group `ADMINISTRATORS` with `ROLE_ADMIN_FULL` (global) + `ROLE_FORUM_FULL` + `ROLE_MOD_FULL` on boards |

Notes:
- “Per-board moderator” means moderator role assignment is done forum-by-forum, not globally.
- Trusted users are a dedicated group you can manually curate from ACP.

## 3) Moderation SOP

### A. Spam handling

1. Immediate action:
- Soft-delete spam post/topic.
- Warn user if low-volume; otherwise ban account/IP for obvious bot spam.

2. Account-level controls:
- For obvious automation: ban username + email + IP range pattern.
- If suspicious but uncertain: place under moderation queue (do not permanently ban immediately).

3. Cleanup:
- Remove malicious links in quoted replies.
- Mark related reports as resolved with a short note.

4. Escalation:
- If repeated coordinated spam appears in multiple boards within 30 minutes, escalate to Admin for CAPTCHA/registration tightening.

### B. Abuse / harassment / doxxing

1. Immediate action:
- Soft-delete abusive or personal-information content.
- Issue warning for first severe offense or temporary ban for repeated offense.

2. Evidence and audit:
- Preserve moderator note: topic URL, post ID, action, reason.
- Keep internal log for at least 90 days.

3. Escalation:
- Threats of violence, extortion, targeted harassment, or doxxing are immediate Admin escalation and account suspension pending review.

### C. Politically sensitive content

1. Moderation principle:
- Allow policy and current-affairs discussion.
- Enforce behavior standards: no direct incitement, no explicit calls for violence, no doxxing, no coordinated harassment.

2. Action ladder:
- Minor rule break: edit/remove specific violating segment and warn.
- Major or repeated violation: soft-delete, temporary lock topic, queue future posts from user where needed.

3. Escalation rules:
- If legal/regulatory risk is unclear, lock thread and escalate to Admin for final decision.
- High-visibility incidents (rapid reposts, cross-board amplification, legal complaint) require Admin review before reopening.

### D. Queue management targets

- First response to reports: within 30 minutes during staffed hours.
- Post-approval queue SLA: within 6 hours.
- Ban appeal SLA: within 48 hours.

## 4) Minimal phpBB Security/Registration Settings

These are the baseline values applied by the automation script.

| ACP Section | Setting | Value |
|---|---|---|
| General > User registration settings | Account activation | `By admin` (`require_activation=2`) |
| General > User registration settings | Enable visual confirmation | `Yes` (`enable_confirm=1`) |
| General > Spambot countermeasures | Installed plugin | `GD CAPTCHA` (`captcha_plugin=core.captcha.plugins.gd`) |
| Posting > Flood interval | Flood interval | `30` seconds (`flood_interval=30`) |
| Posting > Post settings | Search flood interval | `10` seconds (`search_interval=10`) |
| Posting > Post settings | Time limit on editing/deleting | `30` minutes (`edit_time=30`, `delete_time=30`) |
| General > User registration settings | New member post limit | `5` (`new_member_post_limit=5`) |
| General > User registration settings | Set newly registered users to default group | `Yes` (`new_member_group_default=1`) |
| General > Security settings | Max registration attempts | `3` (`max_reg_attempts=3`) |
| General > Security settings | IP login attempts/time window | `10` attempts per `3600` sec (`ip_login_limit_max=10`, `ip_login_limit_time=3600`) |
| General > Security settings | DNSBL check | `Enabled` (`check_dnsbl=1`) |

## 5) Apply / Re-Apply Configuration

Run from repo root:

```bash
cd ~/VictoriaPark/victoriapark
chmod +x scripts/configure_forums.sh
```

Local:

```bash
./scripts/configure_forums.sh --local --project victoriapark-local
```

Production:

```bash
./scripts/configure_forums.sh --project victoriapark
```

## 6) Post-Apply Manual Steps (ACP)

1. Add trusted members:
- `ACP > Users and Groups > Manage groups > TRUSTED > Add users`

2. Assign per-board moderators:
- `ACP > Permissions > Group forum moderators`
- Group: `BOARD_MODERATORS`
- Forum selection: choose board(s)
- Role: `Standard Moderator`

3. Optional stricter production settings:
- Switch activation to user-email after SMTP is configured (`require_activation=1`).
- Increase CAPTCHA complexity if attack volume increases.
