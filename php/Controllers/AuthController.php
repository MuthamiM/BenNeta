<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use Exception;

class AuthController extends BaseController {
    private string $resendApiKey;

    private function loadResendKey(): string {
        // 1. Environment variable (preferred)
        $envKey = getenv('RESEND_API_KEY');
        if ($envKey) return $envKey;

        // 2. Config file
        $configPaths = [
            '/opt/exer/config/config.json',
            '/opt/ezer/config/config.json',
            __DIR__ . '/../../config/config.json',
        ];
        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                $cfg = json_decode(file_get_contents($path), true);
                if (!empty($cfg['resend_api_key'])) return $cfg['resend_api_key'];
            }
        }
        return '';
    }

    public function __construct() {
        parent::__construct();
        $this->resendApiKey = $this->loadResendKey();
        $this->ensureAuthTables();
    }

    private function ensureAuthTables(): void {
        try {
            $pdo = $this->db->getPdo();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT UNIQUE NOT NULL,
                    username TEXT NOT NULL,
                    role TEXT DEFAULT 'Super Administrator',
                    google_app_password TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS otp_codes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT NOT NULL,
                    otp_code TEXT NOT NULL,
                    verified INTEGER DEFAULT 0,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } catch (Exception $e) {
            // DB already initialized or file locked
        }
    }

    /**
     * Send 6-digit OTP code to email via Resend API
     */
    public function sendOtp(Request $req): Response {
        $data = $req->getJson();
        $email = trim($data['email'] ?? '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Please provide a valid email address.');
        }

        // Generate 6-digit cryptographic numeric OTP
        $otp = (string)random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes expiry

        // Store OTP in database
        try {
            $this->db->execute(
                "INSERT INTO otp_codes (email, otp_code, verified, expires_at) VALUES (?, ?, 0, ?)",
                [$email, $otp, $expiresAt]
            );
        } catch (Exception $e) {
            return $this->error('Failed to generate verification code: ' . $e->getMessage());
        }

        // Send Email via Resend API
        $emailHtml = '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; background: #0a0b0e; color: #f8fafc; padding: 40px 20px; border-radius: 12px; max-width: 520px; margin: 0 auto; border: 1px solid rgba(255,255,255,0.1);">
            <div style="text-align: center; margin-bottom: 24px;">
                <div style="display: inline-block; padding: 8px 16px; border-radius: 8px; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); color: #60a5fa; font-weight: 700; font-size: 14px; letter-spacing: 0.05em;">
                    EXER INTELLIGENCE
                </div>
            </div>
            <h2 style="font-size: 22px; font-weight: 700; margin-bottom: 12px; text-align: center; color: #ffffff;">
                Your Verification Code
            </h2>
            <p style="font-size: 14px; color: #94a3b8; text-align: center; line-height: 1.5; margin-bottom: 24px;">
                Enter this 6-digit security code to verify your email and activate your EXER workstation session.
            </p>
            <div style="text-align: center; margin: 30px 0;">
                <div style="display: inline-block; font-size: 36px; font-weight: 800; letter-spacing: 0.25em; padding: 14px 28px; background: #131722; border: 1px solid rgba(59, 130, 246, 0.4); border-radius: 12px; color: #60a5fa; font-family: monospace;">
                    ' . $otp . '
                </div>
            </div>
            <p style="font-size: 12px; color: #64748b; text-align: center; line-height: 1.5;">
                This code expires in 10 minutes. If you did not request this verification, ignore this message.
            </p>
            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.08); margin: 24px 0;">
            <div style="text-align: center; font-size: 11px; color: #475569;">
                EXER Autonomous PC Intelligence &bull; Strict &lt;28MB RAM Footprint
            </div>
        </div>';

        $payload = [
            'from' => 'EXER Security <onboarding@resend.dev>',
            'to' => [$email],
            'subject' => "Your EXER Verification Code: {$otp}",
            'html' => $emailHtml,
            'text' => "Your EXER verification code is: {$otp}. It expires in 10 minutes."
        ];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->resendApiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resendResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resendData = json_decode($resendResponse, true) ?: [];

        // Check if Resend accepted the email
        if ($httpCode >= 200 && $httpCode < 300) {
            return $this->success([
                'message' => 'OTP sent successfully to ' . $email,
                'email' => $email,
                'resend_id' => $resendData['id'] ?? null
            ]);
        }

        // Handle Resend Sandbox restriction (only sends to account owner on testing domain)
        $errorMessage = $resendData['message'] ?? 'Unable to dispatch email via Resend.';
        if (strpos($errorMessage, 'You can only send testing emails') !== false) {
            // For convenience in testing when unverified address is entered, return the code so testing isn't blocked
            return $this->success([
                'message' => 'OTP generated. Note: Resend test domain restricted delivery. Use the code displayed below for verification.',
                'email' => $email,
                'sandbox_code' => $otp,
                'resend_notice' => $errorMessage
            ]);
        }

        return $this->error('Resend delivery failed: ' . $errorMessage, 400, ['raw' => $resendData]);
    }

    /**
     * Verify 6-digit OTP code
     */
    public function verifyOtp(Request $req): Response {
        $data = $req->getJson();
        $email = trim($data['email'] ?? '');
        $code = trim($data['code'] ?? '');

        if (!$email || !$code) {
            return $this->error('Email and 6-digit verification code are required.');
        }

        // Fetch valid OTP from DB
        $rows = $this->db->query(
            "SELECT * FROM otp_codes WHERE email = ? AND otp_code = ? AND verified = 0 AND expires_at > datetime('now') ORDER BY id DESC LIMIT 1",
            [$email, $code]
        );

        if (empty($rows)) {
            return $this->error('Invalid or expired verification code. Please request a new one.');
        }

        $otpRecord = $rows[0];

        // Mark OTP as verified
        $this->db->execute(
            "UPDATE otp_codes SET verified = 1 WHERE id = ?",
            [$otpRecord['id']]
        );

        // Check if user already exists
        $userRows = $this->db->query("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
        $user = !empty($userRows) ? $userRows[0] : null;

        return $this->success([
            'verified' => true,
            'email' => $email,
            'existing_user' => (bool)$user,
            'username' => $user['username'] ?? explode('@', $email)[0]
        ]);
    }

    /**
     * Complete Sign Up / Setup Username
     */
    public function completeSignup(Request $req): Response {
        $data = $req->getJson();
        $email = trim($data['email'] ?? '');
        $username = trim($data['username'] ?? '');
        $googleAppPassword = trim($data['google_app_password'] ?? '');

        if (!$email || !$username) {
            return $this->error('Email and username are required.');
        }

        // Check if verified recently
        $verified = $this->db->query(
            "SELECT id FROM otp_codes WHERE email = ? AND verified = 1 AND created_at > datetime('now', '-30 minutes') ORDER BY id DESC LIMIT 1",
            [$email]
        );

        if (empty($verified)) {
            return $this->error('Email has not been verified via OTP.');
        }

        // Upsert user into database
        $this->db->execute("
            INSERT INTO users (email, username, google_app_password, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(email) DO UPDATE SET 
                username = excluded.username,
                google_app_password = COALESCE(NULLIF(excluded.google_app_password, ''), users.google_app_password),
                updated_at = CURRENT_TIMESTAMP
        ", [$email, $username, $googleAppPassword]);

        // Update active system state
        $this->db->setSystemState('active_user_name', $username);
        $this->db->setSystemState('active_user_email', $email);

        return $this->success([
            'message' => 'Profile setup complete. Welcome to EXER!',
            'user' => [
                'email' => $email,
                'username' => $username,
                'role' => 'Super Administrator'
            ]
        ]);
    }

    /**
     * Get Current Active Profile
     */
    public function getCurrentProfile(Request $req): Response {
        $email = $this->db->getSystemState('active_user_email', 'musamwange2@gmail.com');
        $username = $this->db->getSystemState('active_user_name', 'Musa');

        $userRows = $this->db->query("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
        $userData = !empty($userRows) ? $userRows[0] : null;

        return $this->success([
            'user' => [
                'email' => $email,
                'username' => $userData['username'] ?? $username,
                'role' => $userData['role'] ?? 'Super Administrator',
                'has_google_password' => !empty($userData['google_app_password'])
            ]
        ]);
    }
}
