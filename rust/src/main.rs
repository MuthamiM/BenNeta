use std::env;
use std::fs;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::thread;
use std::time::Duration;

const DEFAULT_POLL_INTERVAL_SECS: u64 = 60;

fn get_project_root() -> PathBuf {
    let current_exe = env::current_exe().unwrap_or_else(|_| PathBuf::from("."));
    // Try navigating up from target/release or target/debug
    let mut dir = current_exe.parent().unwrap_or(Path::new(".")).to_path_buf();
    for _ in 0..4 {
        if dir.join("config").exists() && dir.join("python").exists() {
            return dir;
        }
        if let Some(parent) = dir.parent() {
            dir = parent.to_path_buf();
        } else {
            break;
        }
    }
    // Fallback to current working directory
    env::current_dir().unwrap_or_else(|_| PathBuf::from("."))
}

fn send_desktop_notification(title: &str, message: &str) -> bool {
    let clean_title = format!("[ASSISTANT] {}", title);

    if cfg!(target_os = "windows") {
        let ps_cmd = format!(
            "[Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] | Out-Null;\
             $template = '<toast><visual><binding template=\"ToastText02\"><text id=\"1\">{}</text><text id=\"2\">{}</text></binding></visual></toast>';\
             $xml = New-Object Windows.Data.Xml.Dom.XmlDocument;\
             $xml.LoadXml($template);\
             $toast = [Windows.UI.Notifications.ToastNotification]::new($xml);\
             [Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier('Assistant').Show($toast);",
            clean_title.replace('\'', "''"),
            message.replace('\'', "''")
        );

        let status = Command::new("powershell")
            .arg("-NoProfile")
            .arg("-Command")
            .arg(ps_cmd)
            .status();

        matches!(status, Ok(s) if s.success())
    } else {
        // Linux notify-send
        let status = Command::new("notify-send")
            .arg(&clean_title)
            .arg(message)
            .status();

        matches!(status, Ok(s) if s.success())
    }
}

fn run_single_scan(project_root: &Path) -> bool {
    println!("[*] Checking live IMAP mailbox for new emails and missed notifications...");

    let python_script = project_root.join("python").join("email_fetcher.py");
    if !python_script.exists() {
        eprintln!("[-] Error: email_fetcher.py not found at {:?}", python_script);
        return false;
    }

    let python_cmd = if cfg!(target_os = "windows") { "python" } else { "python3" };

    let output = Command::new(python_cmd)
        .arg(&python_script)
        .current_dir(project_root)
        .stdout(Stdio::inherit())
        .stderr(Stdio::inherit())
        .output();

    match output {
        Ok(res) if res.status.success() => {
            println!("[+] Scan completed successfully.");
            true
        }
        Ok(res) => {
            eprintln!("[-] email_fetcher exited with code: {:?}", res.status.code());
            false
        }
        Err(e) => {
            eprintln!("[-] Failed to execute Python email fetcher: {}", e);
            false
        }
    }
}

fn read_poll_interval(project_root: &Path) -> u64 {
    let cfg_path = project_root.join("config").join("config.json");
    if let Ok(content) = fs::read_to_string(cfg_path) {
        if let Some(idx) = content.find("\"poll_interval_seconds\":") {
            let rest = &content[idx + 24..];
            let num_str: String = rest.chars()
                .skip_while(|c| c.is_whitespace())
                .take_while(|c| c.is_ascii_digit())
                .collect();
            if let Ok(val) = num_str.parse::<u64>() {
                if val >= 10 {
                    return val;
                }
            }
        }
    }
    DEFAULT_POLL_INTERVAL_SECS
}

fn main() {
    let args: Vec<String> = env::args().collect();
    let project_root = get_project_root();
    let action = args.get(1).map(|s| s.as_str()).unwrap_or("run");

    println!("============================================================");
    println!("     ASSISTANT DAEMON - PC (LINUX & WINDOWS)                ");
    println!("============================================================");
    println!("[+] Project root: {:?}", project_root);
    println!("[+] Operating System: {}", env::consts::OS);

    match action {
        "once" | "scan" => {
            run_single_scan(&project_root);
        }
        "notify" => {
            let title = args.get(2).map(|s| s.as_str()).unwrap_or("Test Notification");
            let msg = args.get(3).map(|s| s.as_str()).unwrap_or("Assistant notification test on PC.");
            println!("[*] Sending native desktop alert: {} - {}", title, msg);
            let ok = send_desktop_notification(title, msg);
            if ok {
                println!("[+] Desktop alert sent successfully.");
            } else {
                eprintln!("[-] Could not send desktop alert.");
            }
        }
        "run" | "daemon" => {
            let interval = read_poll_interval(&project_root);
            println!("[+] Starting background monitoring loop (Interval: {}s)...", interval);
            println!("[+] Press Ctrl+C to stop.");

            // Initial scan
            run_single_scan(&project_root);

            loop {
                thread::sleep(Duration::from_secs(interval));
                run_single_scan(&project_root);
            }
        }
        "help" | "-h" | "--help" => {
            println!("Usage:");
            println!("  assistant-daemon run        Start background monitoring daemon");
            println!("  assistant-daemon once       Run single email check");
            println!("  assistant-daemon notify <title> <msg>  Trigger desktop notification");
        }
        _ => {
            eprintln!("[-] Unknown command: {}", action);
            println!("Use 'assistant-daemon help' for available commands.");
        }
    }
}
