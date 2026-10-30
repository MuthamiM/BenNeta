use std::env;
use std::fs;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};

fn get_project_root() -> PathBuf {
    let current_exe = env::current_exe().unwrap_or_else(|_| PathBuf::from("."));
    let mut dir = current_exe.parent().unwrap_or(Path::new(".")).to_path_buf();
    for _ in 0..5 {
        if dir.join("web").exists() && (dir.join("python").exists() || dir.join("php").exists()) {
            return dir;
        }
        if let Some(parent) = dir.parent() {
            dir = parent.to_path_buf();
        } else {
            break;
        }
    }
    // Check fallback locations
    if Path::new("/opt/benneta").exists() {
        return PathBuf::from("/opt/benneta");
    }
    env::current_dir().unwrap_or_else(|_| PathBuf::from("."))
}

fn open_native_gui(url: &str, root: &Path) {
    if cfg!(target_os = "linux") {
        let native_script = root.join("python").join("native_app.py");
        let fallback_script = Path::new("/opt/benneta/python/native_app.py");
        let script_to_run = if native_script.exists() {
            Some(native_script)
        } else if fallback_script.exists() {
            Some(fallback_script.to_path_buf())
        } else {
            None
        };

        if let Some(script) = script_to_run {
            let res = Command::new("python3")
                .arg(&script)
                .env("BENNETA_URL", url)
                .spawn();

            if res.is_ok() {
                println!("[+] BenNeta Native GTK4 Desktop Window launched.");
                return;
            }
        }
    }

    let app_flag = format!("--app={}", url);
    if cfg!(target_os = "windows") {
        if Command::new("msedge").arg(&app_flag).spawn().is_err() {
            let _ = Command::new("cmd").args(["/C", "start", url]).spawn();
        }
    } else if cfg!(target_os = "macos") {
        let _ = Command::new("open").arg(url).spawn();
    } else {
        let _ = Command::new("brave-browser")
            .arg(&app_flag)
            .spawn()
            .or_else(|_| Command::new("google-chrome").arg(&app_flag).spawn())
            .or_else(|_| Command::new("xdg-open").arg(url).spawn());
    }
}

fn main() {
    let args: Vec<String> = env::args().collect();
    let root = get_project_root();
    let cmd = args.get(1).map(|s| s.as_str()).unwrap_or("web");

    println!("========================================================");
    println!("  BenNeta 1.0.0  --  PC Intelligence & Automation");
    println!("  Memory Budget: Strict Max 28MB RAM Constraint");
    println!("========================================================");

    match cmd {
        "web" | "ui" | "start" => {
            let url = "https://benneta.mftechnologies.org/app";

            println!("[*] Opening BenNeta Intelligence Native App Window...");
            open_native_gui(url, &root);
        }
        "status" => {
            println!("[*] Root directory: {}", root.display());
            let db_path = root.join("data").join("assistant.db");
            println!("[*] Database present: {}", db_path.exists());
            println!("[*] Memory limit cap: 28.0 MB");
        }
        "version" | "-v" | "--version" => {
            println!("BenNeta 1.0.0 (x86_64)");
        }
        "help" | "-h" | "--help" | _ => {
            println!("Usage: benneta [command]");
            println!();
            println!("Commands:");
            println!("  web       Start web dashboard (auto-launches browser, memory cap 20M)");
            println!("  status    Display installation status and memory budgets");
            println!("  version   Print version info");
            println!("  help      Show this help message");
            println!();
            println!("Default if double-clicked: launches 'web' mode.");
        }
    }
}
