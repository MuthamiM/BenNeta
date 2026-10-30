"""
Task Extractor Module.
Cross-platform PC NLP and regex analyzer for email subjects and bodies.
Detects action items, deadlines, and urgency levels with zero emojis.
"""

import re

HIGH_PRIORITY_PATTERNS = [
    r"\burgent\b", r"\basap\b", r"\bcritical\b", r"\bemergency\b",
    r"\bimmediate\b", r"\boutage\b", r"\bdown\b", r"\bfailed\b",
    r"\berror\b", r"\bdeadline\s+today\b", r"\baction\s+required\b",
    r"\bimportant\b", r"\bserver\s+down\b", r"\bbreach\b", r"\bpayment\s+due\b"
]

MEDIUM_PRIORITY_PATTERNS = [
    r"\bplease\s+(review|check|send|update|confirm|submit|verify|reply)\b",
    r"\baction\s+item\b", r"\btodo\b", r"\bto-do\b", r"\breminder\b",
    r"\bmeeting\b", r"\bschedule\b", r"\bcall\b", r"\bfollow[\s-]?up\b",
    r"\bneed\s+your\b", r"\bby\s+(tomorrow|friday|monday|eod)\b"
]

DEADLINE_PATTERNS = [
    r"\b(?:by|due|before|on)\s+(today|tomorrow|tonight|eod)\b",
    r"\b(?:by|due|before|on)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b",
    r"\b(?:at|by)\s+(\d{1,2}(?::\d{2})?\s*(?:am|pm|AM|PM))\b",
    r"\b(?:by|due)\s+(\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?)\b",
    r"\bwithin\s+(\d+\s+(?:hours?|days?))\b"
]

IGNORE_PATTERNS = [
    r"unsubscribe",
    r"view\s+in\s+browser",
    r"marketing\s+preferences",
    r"promotions",
    r"newsletter",
    r"no-reply@"
]

def clean_text(text):
    if not text:
        return ""
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text

def extract_deadline(text):
    lower = text.lower()
    for pattern in DEADLINE_PATTERNS:
        match = re.search(pattern, lower)
        if match:
            return match.group(0).strip()
    return None

def determine_priority(text):
    lower = text.lower()
    for p in HIGH_PRIORITY_PATTERNS:
        if re.search(p, lower):
            return "HIGH"
    for p in MEDIUM_PRIORITY_PATTERNS:
        if re.search(p, lower):
            return "MEDIUM"
    return "LOW"

def is_likely_newsletter(sender, subject, body):
    combined = f"{sender} {subject} {body}".lower()
    for p in HIGH_PRIORITY_PATTERNS:
        if re.search(p, combined):
            return False
    matches = sum(1 for p in IGNORE_PATTERNS if re.search(p, combined))
    return matches >= 2

def extract_task_from_email(subject, sender, body):
    clean_subj = clean_text(subject)
    clean_b = clean_text(body)
    combined = f"{clean_subj} {clean_b}"

    if is_likely_newsletter(sender, clean_subj, clean_b):
        return None

    priority = determine_priority(combined)
    deadline = extract_deadline(combined)

    is_alert = any(re.search(p, combined.lower()) for p in HIGH_PRIORITY_PATTERNS)
    is_action = any(re.search(p, combined.lower()) for p in MEDIUM_PRIORITY_PATTERNS)

    if not (is_alert or is_action):
        return None

    task_title = re.sub(r"^(re|fwd|fw):\s*", "", clean_subj, flags=re.IGNORECASE).strip()

    if is_alert and not re.search(r"\b(fix|check|investigate|resolve|alert)\b", task_title.lower()):
        task_title = f"Investigate Alert: {task_title}"
    elif not any(task_title.lower().startswith(v) for v in ["review", "check", "reply", "send", "fix", "update", "investigate", "prepare", "meeting", "call"]):
        task_title = f"Action: {task_title}"

    description = clean_b[:280] + ("..." if len(clean_b) > 280 else "")

    return {
        "title": task_title,
        "description": description,
        "sender": sender,
        "priority": priority,
        "due_hint": deadline or ("Immediate" if priority == "HIGH" else "Today / Soon"),
        "is_alert": is_alert
    }
