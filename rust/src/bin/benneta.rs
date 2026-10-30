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

fn open_browser(url: &str) {
    if cfg!(target_os = "windows") {
        let _ = Command::new("cmd")
            .args(["/C", "start", url])
            .spawn();
    } else if cfg!(target_os = "macos") {
        let _ = Command::new("open").arg(url).spawn();
    } else {
        let _ = Command::new("xdg-open").arg(url).spawn();
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
            let web_dir = root.join("web");
            let port = "8765";
            let url = format!("http://localhost:{}", port);

            println!("[*] Starting BenNeta Web Microservice on {}...", url);
            println!("[*] Enforcing low-memory profile: memory_limit=20M");

            open_browser(&url);

            // Launch PHP server with strict low-memory limit (20M limit to stay well within 28MB)
            let mut server = Command::new("php")
                .arg("-d")
                .arg("memory_limit=20M")
                .arg("-S")
                .arg(format!("0.0.0.0:{}", port))
                .arg("-t")
                .arg(&web_dir)
                .current_dir(&root)
                .spawn();

            match server {
                Ok(ref mut child) => {
                    println!("[+] BenNeta running. Press Ctrl+C to stop.");
                    let _ = child.wait();
                }
                Err(e) => {
                    eprintln!("[-] Error launching web server: {}", e);
                    eprintln!("[-] Ensure php-cli is installed and on PATH.");
                }
            }
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
