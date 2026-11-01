<?php
namespace BenNeta\Core;

use BenNeta\Middleware\CorsMiddleware;
use BenNeta\Middleware\ErrorHandlingMiddleware;
use BenNeta\Middleware\RamGuardianMiddleware;
use BenNeta\Middleware\CookieSessionMiddleware;
use BenNeta\Middleware\SecurityMiddleware;
use BenNeta\Middleware\UsageTrackingMiddleware;
use BenNeta\Controllers\EmailController;
use BenNeta\Controllers\SecurityController;
use BenNeta\Controllers\TaskController;
use BenNeta\Controllers\ResearchController;
use BenNeta\Controllers\UsageController;
use BenNeta\Controllers\DownloadController;
use BenNeta\Controllers\AssistantController;
use BenNeta\Controllers\ConversationController;
use BenNeta\Controllers\AuthController;
use BenNeta\Services\SystemService;

class App {
    private Router $router;

    public function __construct() {
        $this->router = new Router();
        $this->registerMiddlewares();
        $this->registerRoutes();
    }

    private function registerMiddlewares(): void {
        // Onion order: outer to inner
        $this->router->addMiddleware(new CorsMiddleware());
        $this->router->addMiddleware(new ErrorHandlingMiddleware());
        $this->router->addMiddleware(new RamGuardianMiddleware());
        $this->router->addMiddleware(new CookieSessionMiddleware());
        $this->router->addMiddleware(new SecurityMiddleware());
        $this->router->addMiddleware(new UsageTrackingMiddleware());
    }

    private function registerRoutes(): void {
        // System & Health Status
        $this->router->register('status', function(Request $req) {
            $audit = SystemService::getRamAudit();
            return Response::success([
                'app' => 'BenNeta',
                'version' => '1.0.0',
                'service' => 'Intelligence & Automation Microservices',
                'ram_status' => $audit,
                'max_ram_limit_mb' => 28.0,
                'server_time' => date('c')
            ]);
        });

        // Email & Gmail Controller Routes
        $this->router->register('get_gmail_today', [EmailController::class, 'getTodayEmails']);
        $this->router->register('get_pending_reviews', [EmailController::class, 'getPendingReviews']);
        $this->router->register('approve_task', [EmailController::class, 'approveEmail']);
        $this->router->register('reject_task', [EmailController::class, 'rejectEmail']);
        $this->router->register('send_reply', [EmailController::class, 'sendReply']);
        $this->router->register('send_email', [EmailController::class, 'sendReply']);

        // AI Assistant & Brave Model Commander Routes
        $this->router->register('assistant_command', [AssistantController::class, 'executeCommand']);
        $this->router->register('open_excel', [AssistantController::class, 'openExcel']);
        $this->router->register('download_excel', [AssistantController::class, 'downloadExcel']);
        $this->router->register('download_pdf', [AssistantController::class, 'downloadPdf']);
        $this->router->register('upload_file', [AssistantController::class, 'uploadFile']);
        $this->router->register('get_uploaded_file', [AssistantController::class, 'getUploadedFile']);

        // Security Controller Routes
        $this->router->register('get_security_metrics', [SecurityController::class, 'getMetrics']);
        $this->router->register('ban_ip', [SecurityController::class, 'banIp']);
        $this->router->register('unban_ip', [SecurityController::class, 'unbanIp']);
        $this->router->register('toggle_dnd', [SecurityController::class, 'toggleDnd']);

        // Tasks Controller Routes
        $this->router->register('get_automated_tasks', [TaskController::class, 'getAutomatedTasks']);
        $this->router->register('run_automated_task', [TaskController::class, 'runTask']);
        $this->router->register('get_daily_tasks', [TaskController::class, 'getDailyTasks']);
        $this->router->register('complete_daily_task', [TaskController::class, 'completeTask']);
        $this->router->register('add_daily_task', [TaskController::class, 'addCustomTask']);
        $this->router->register('delete_daily_task', [TaskController::class, 'deleteTask']);
        $this->router->register('clear_completed_tasks', [TaskController::class, 'clearCompletedTasks']);

        // Conversation History Routes
        $this->router->register('list_conversations', [ConversationController::class, 'listConversations']);
        $this->router->register('get_conversation', [ConversationController::class, 'getConversation']);
        $this->router->register('create_conversation', [ConversationController::class, 'createConversation']);
        $this->router->register('append_message', [ConversationController::class, 'appendMessage']);
        $this->router->register('delete_conversation', [ConversationController::class, 'deleteConversation']);
        $this->router->register('rename_conversation', [ConversationController::class, 'renameConversation']);
        $this->router->register('clear_all_conversations', [ConversationController::class, 'clearAllConversations']);

        // Research & Brave Search Routes
        $this->router->register('research_search', [ResearchController::class, 'search']);
        $this->router->register('draft_reply', [ResearchController::class, 'draftReply']);

        // Usage Analytics & Cookies Web Routes
        $this->router->register('get_usage_data', [UsageController::class, 'getUsageData']);
        $this->router->register('track_usage', [UsageController::class, 'trackClientEvent']);
        $this->router->register('export_data', [UsageController::class, 'exportData']);
        $this->router->register('clear_data', [UsageController::class, 'clearData']);
        $this->router->register('save_preferences', [UsageController::class, 'savePreferences']);

        // Authentication & Resend OTP Routes
        $this->router->register('send_otp', [AuthController::class, 'sendOtp']);
        $this->router->register('verify_otp', [AuthController::class, 'verifyOtp']);
        $this->router->register('complete_signup', [AuthController::class, 'completeSignup']);
        $this->router->register('get_current_profile', [AuthController::class, 'getCurrentProfile']);

        // Download & OS Detection Routes
        $this->router->register('detect_os', [DownloadController::class, 'detectOs']);
        $this->router->register('get_release_info', [DownloadController::class, 'getReleaseInfo']);
        $this->router->register('download_deb', [DownloadController::class, 'downloadDeb']);
        $this->router->register('download_windows', [DownloadController::class, 'downloadWindows']);
    }

    public function run(?Request $request = null): void {
        $req = $request ?: Request::capture();
        $response = $this->router->dispatch($req);
        $response->send();
    }
}
