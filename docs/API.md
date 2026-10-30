# BenNeta API Microservices Specification

BenNeta exposes a lightweight, performant REST API for email intelligence, system automation, security telemetry, and AI-powered web research via Brave Search.

## Base URL
- Local Installation: `http://localhost:8765/api.php`
- Production Domain: `https://api.mftechnologies.org/api.php` or `https://benneta.mftechnologies.org/api.php`

## Authentication & Security Middleware
Every request is filtered by `security_middleware.php`:
- Path traversal and malicious SQL injection payloads are automatically blocked with HTTP 403.
- Rapid velocity bursts (>20 requests/10s) trigger automatic temporary IP bans.
- Security events are persisted to SQLite database `data/assistant.db` table `security_events`.

---

## Endpoints

### 1. System & Assistant Status
- **Method**: `GET`
- **Query Parameter**: `action=status`
- **Response**:
```json
{
  "success": true,
  "app_name": "BenNeta",
  "version": "1.0.0",
  "user_name": "Musa",
  "user_email": "musamwange2@gmail.com",
  "notifications_mode": "ON",
  "pending_tasks_count": 0,
  "daily_tasks_count": 3,
  "blocked_ips": 0
}
```

---

### 2. Live Today's Gmail Inbox
- **Method**: `GET`
- **Query Parameter**: `action=get_gmail_today`
- **Description**: Connects live over IMAP SSL to `musamwange2@gmail.com`, searches emails received today, sanitizes text (zero emojis), executes multi-factor spam/phishing classification, and flags actionable items.
- **Response**:
```json
{
  "success": true,
  "data": {
    "status": "SUCCESS",
    "account": "musamwange2@gmail.com",
    "total_today": 25,
    "spam_detected_count": 0,
    "actionable_tasks_count": 20,
    "emails": [
      {
        "id": "10547",
        "subject": "Security alert",
        "sender": "no-reply@accounts.google.com",
        "date": "2026-10-30 11:45:00",
        "is_spam": false,
        "is_promo": false,
        "spam_score": 10,
        "task": {
          "title": "Security alert",
          "priority": "HIGH"
        }
      }
    ]
  }
}
```

---

### 3. Brave Search Web Research
- **Method**: `POST` or `GET`
- **Endpoint**: `api.php?action=brave_search`
- **Request Body (JSON)**:
```json
{
  "query": "mftechnologies cloud security"
}
```
- **Response**:
```json
{
  "success": true,
  "data": {
    "success": true,
    "query": "mftechnologies cloud security",
    "total": 3,
    "results": [
      {
        "title": "MF Technologies Security Suite",
        "url": "https://mftechnologies.org/security",
        "description": "Enterprise cloud security, authentication, and endpoint defense...",
        "page_age": "2026-09-12"
      }
    ]
  }
}
```

---

### 4. Smart Email Research & Reply Draft
- **Method**: `POST`
- **Endpoint**: `api.php?action=research_and_draft_reply`
- **Request Body (JSON)**:
```json
{
  "subject": "Urgent client meeting on API integration",
  "sender": "client@mftechnologies.org",
  "body": "Can we schedule a call to discuss the OAuth 2.0 integration?"
}
```
- **Response**:
```json
{
  "success": true,
  "data": {
    "draft_id": 1,
    "recipient": "client@mftechnologies.org",
    "subject": "Re: Urgent client meeting on API integration",
    "draft_body": "Hello client,\n\nThank you for reaching out...\n\nBest regards,\nMusa Mwange",
    "research": []
  }
}
```

---

### 5. Watched Emails ("Keep an Eye On")
- **Add to Watchlist**:
  - **Method**: `POST`
  - **Endpoint**: `api.php?action=add_watched_email`
  - **Body**: `{"pattern": "client@mftechnologies.org", "type": "sender", "notes": "VIP client"}`
- **List Watched Emails**:
  - **Method**: `GET`
  - **Endpoint**: `api.php?action=get_watched_emails`

---

### 6. API Security Metrics
- **Method**: `GET`
- **Endpoint**: `api.php?action=get_security_metrics`
- **Response**:
```json
{
  "success": true,
  "data": {
    "total_events": 12,
    "critical_threats": 0,
    "blocked_ips_count": 0,
    "recent_events": []
  }
}
```

---

### 7. Run Automated Task
- **Method**: `POST`
- **Endpoint**: `api.php?action=run_automated_task`
- **Body**: `{"task_key": "api_health"}`
- **Supported Tasks**:
  1. `email_triage` - Scan live Gmail for action items
  2. `api_health` - Ping live endpoints (mftechnologies, icapsq)
  3. `system_resources` - Audit live CPU, RAM, and Disk
  4. `calendar_sync` - Pull agenda from Google Calendar feed
  5. `ssl_check` - Audit TLS handshake and expiration days
  6. `port_audit` - Scan listening TCP ports
  7. `log_audit` - Audit log file sizes (>5MB)
  8. `process_watchdog` - Check critical processes
  9. `daily_report` - Generate daily briefing markdown
  10. `email_alert` - Test outbound SMTP notification
