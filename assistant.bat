@echo off
REM Assistant CLI Entrypoint for Windows (PC)
REM Polyglot Architecture: Python, PHP, Rust. Zero emojis.

setlocal enabledelayedexpansion
set "DIR=%~dp0"
cd /d "%DIR%"

if not exist "%DIR%data" mkdir "%DIR%data"

if not exist "%DIR%rust\target\release\assistant-daemon.exe" (
    echo [*] Compiling lightweight Rust background daemon...
    cd /d "%DIR%rust"
    cargo build --release -q
    cd /d "%DIR%"
    echo [+] Rust daemon compiled successfully.
)

if not exist "%DIR%data\assistant.db" (
    python "%DIR%python\db.py" >nul 2>&1
)

set "CMD=%~1"
if "%CMD%"=="" set "CMD=help"

if /i "%CMD%"=="ui" goto do_ui
if /i "%CMD%"=="web" goto do_ui
if /i "%CMD%"=="dashboard" goto do_ui
if /i "%CMD%"=="configure" goto do_config
if /i "%CMD%"=="config" goto do_config
if /i "%CMD%"=="setup" goto do_config
if /i "%CMD%"=="scan" goto do_scan
if /i "%CMD%"=="fetch" goto do_scan
if /i "%CMD%"=="review" goto do_review
if /i "%CMD%"=="approve" goto do_review
if /i "%CMD%"=="rules" goto do_rules
if /i "%CMD%"=="rule" goto do_rules
if /i "%CMD%"=="tasks" goto do_tasks
if /i "%CMD%"=="list" goto do_tasks
if /i "%CMD%"=="ls" goto do_tasks
if /i "%CMD%"=="done" goto do_done
if /i "%CMD%"=="complete" goto do_done
if /i "%CMD%"=="add" goto do_add
if /i "%CMD%"=="pending" goto do_pending
if /i "%CMD%"=="dnd" goto do_dnd
if /i "%CMD%"=="daemon" goto do_daemon
if /i "%CMD%"=="sync" goto do_sync
if /i "%CMD%"=="notify" goto do_notify
goto do_help

:do_ui
set "PORT=%~2"
if "%PORT%"=="" set "PORT=8765"
echo [+] Starting Assistant Web Dashboard on http://localhost:%PORT% ...
echo [+] Press Ctrl+C to stop.
php -S 0.0.0.0:%PORT% -t "%DIR%web"
goto end

:do_config
python "%DIR%python\setup_config.py"
goto end

:do_scan
python "%DIR%python\email_fetcher.py"
goto end

:do_review
python "%DIR%python\review_prompter.py"
goto end

:do_rules
php "%DIR%php\rules_manager.php" %2 %3 %4
goto end

:do_tasks
php "%DIR%php\task_manager.php" list %2 %3
goto end

:do_done
php "%DIR%php\task_manager.php" complete %2
goto end

:do_add
php "%DIR%php\task_manager.php" add %2 %3 %4
goto end

:do_pending
php "%DIR%php\task_manager.php" pending
goto end

:do_dnd
php "%DIR%php\task_manager.php" dnd %2
goto end

:do_daemon
"%DIR%rust\target\release\assistant-daemon.exe" run
goto end

:do_sync
php "%DIR%php\task_manager.php" sync
goto end

:do_notify
"%DIR%rust\target\release\assistant-daemon.exe" notify %2 %3
goto end

:do_help
echo ============================================================
echo    ASSISTANT: EMAIL ^& DAILY TASK MANAGER (PC: LINUX ^& WIN)  
echo ============================================================
echo Usage: assistant.bat ^<command^>
echo.
echo Web Dashboard:
echo   ui [port]              Start interactive Web Dashboard (default: 8765)
echo.
echo Email ^& Notifications:
echo   configure              Setup live IMAP mailbox credentials
echo   scan                   Scan live mailbox for emails ^& missed alerts
echo   review                 Ask to allow/dismiss candidate tasks into Daily Tasks
echo   rules [list^|add^|rm]    Configure what assistant does on certain notifications
echo   dnd [on^|off^|status]    Check or toggle Do Not Disturb (notifications off)
echo.
echo Daily Tasks Management:
echo   tasks [todo^|completed] List daily tasks table
echo   done ^<id^>              Mark task as completed
echo   add ^"<title^>" [prio]   Manually add a task
echo   sync                   Sync tasks with todo.txt
echo.
echo Background Engine:
echo   daemon                 Run the low-resource Rust monitoring daemon
echo   notify ^<title^> ^<msg^>   Send a native desktop notification
echo ============================================================
goto end

:end
endlocal
