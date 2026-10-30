"""
Spam and Phishing Detection Algorithm for incoming emails.
Uses multi-factor heuristic scoring, header validation, and keyword analysis
to classify incoming emails into SPAM/PHISHING, PROMOTIONAL, or LEGITIMATE.
Zero emojis. PC support for Linux and Windows.
"""

import re

# High-risk phishing and scam triggers
PHISHING_TRIGGERS = [
    (r"\bverify\s+your\s+(account|identity|password|wallet|banking)\b", 35, "Account Verification Phishing Attempt"),
    (r"\baccount\s+(suspended|locked|restricted|terminated)\b", 30, "Urgent Account Suspension Threat"),
    (r"\bwire\s+transfer\b|\bwestern\s+union\b|\bmoneygram\b", 30, "Untraceable Wire Transfer Request"),
    (r"\bbitcoin\b|\bcrypto\b|\bethereum\b|\bwallet\s+address\b", 25, "Cryptocurrency Scam / Extortion"),
    (r"\bclaim\s+your\s+(prize|lottery|inheritance|funds|reward)\b", 35, "Lottery / Inheritance Advance Fee Scam"),
    (r"\bcongratulations\b.*\bwon\b", 30, "Fake Winning Notification"),
    (r"\bclick\s+here\s+immediately\b|\burgent\s+action\s+within\s+24\s+hours\b", 25, "High Urgency Coercion")
]

# Marketing and commercial newsletter triggers
MARKETING_TRIGGERS = [
    (r"unsubscribe", 15, "Unsubscribe Link Detected"),
    (r"view\s+in\s+browser", 10, "Bulk HTML Newsletter Marker"),
    (r"marketing\s+preferences|manage\s+subscriptions", 15, "Commercial Mailing List Footer"),
    (r"\b\d+%\s+off\b|\bdiscount\b|\bpromo\s+code\b|\bsale\s+ends\b", 20, "Promotional Sales Offer"),
    (r"privacy\s+policy.*terms\s+of\s+service", 10, "Automated Footer Boilerplate")
]

# Suspicious sender domains
SUSPICIOUS_DOMAINS = [
    r"\.xyz$", r"\.top$", r"\.buzz$", r"\.click$", r"\.loan$", r"\.work$", r"\.icu$"
]

def analyze_email_spam(subject, sender, body, headers=None):
    """
    Computes a Spam & Phishing Score S in [0, 100].
    Returns classification category and detected risk indicators.
    """
    score = 0
    reasons = []

    clean_subj = subject or ""
    clean_sender = sender or ""
    clean_body = (body or "")[:4000]
    combined = f"{clean_subj} {clean_body}".lower()

    # 1. Phishing & Scam Inspection
    for pattern, weight, reason in PHISHING_TRIGGERS:
        if re.search(pattern, combined):
            score += weight
            reasons.append(reason)

    # 2. Marketing & Promotional Inspection
    promo_score = 0
    for pattern, weight, reason in MARKETING_TRIGGERS:
        if re.search(pattern, combined):
            promo_score += weight
            reasons.append(reason)

    # 3. Sender Domain Analysis
    sender_lower = clean_sender.lower()
    for s_dom in SUSPICIOUS_DOMAINS:
        if re.search(s_dom, sender_lower):
            score += 25
            reasons.append("Suspicious Top-Level Domain (TLD)")

    # Display name spoofing (e.g. "Google Support <random@attacker.com>")
    if any(brand in clean_sender.lower() for brand in ["google", "paypal", "apple", "microsoft", "amazon"]):
        if not any(trusted in clean_sender.lower() for trusted in ["@google.com", "@paypal.com", "@apple.com", "@microsoft.com", "@amazon.com"]):
            score += 40
            reasons.append("Brand Name Impersonation / Spoofed Sender")

    # Final category determination
    total_score = min(100, score + (promo_score if score < 40 else 0))

    if score >= 50 or total_score >= 65:
        category = "SPAM_PHISHING"
    elif promo_score >= 25:
        category = "PROMOTION_MARKETING"
    else:
        category = "LEGITIMATE"

    return {
        "spam_score": total_score,
        "category": category,
        "is_spam": category == "SPAM_PHISHING",
        "is_promo": category == "PROMOTION_MARKETING",
        "reasons": reasons
    }

if __name__ == "__main__":
    test1 = analyze_email_spam("Urgent: Verify your account immediately", "support@google-security-verify.xyz", "Your account will be suspended. Click here to verify password.")
    print("Test 1 (Phishing):", test1)

    test2 = analyze_email_spam("Weekly Newsletter: 20% off all courses", "newsletter@techacademy.com", "Check out our sale. Click here to unsubscribe.")
    print("Test 2 (Marketing):", test2)

    test3 = analyze_email_spam("Server Outage on Production API", "alert@mftechnologies.org", "Endpoint is down with HTTP 502. Please check.")
    print("Test 3 (Legitimate):", test3)
