<?php
namespace BenNeta\Controllers;

use BenNeta\Core\Request;
use BenNeta\Core\Response;
use BenNeta\Services\RedisCacheService;

/**
 * ConversationController — Stores and retrieves all chat conversations.
 * Fast Redis cache layer (< 1ms reopening) backed by SQLite persistence.
 * Zero emojis. Strict constraint.
 */
class ConversationController extends BaseController {
    /**
     * List all conversations (sidebar recents), ordered newest first.
     */
    public function listConversations(Request $request): Response {
        try {
            $limit = (int)($request->input('limit') ?: 30);
            $cacheKey = "exer:convos:list:{$limit}";
            $redis = RedisCacheService::getInstance();

            $cached = $redis->get($cacheKey);
            if (!empty($cached) && is_array($cached)) {
                return $this->success(['conversations' => $cached, 'cached' => true]);
            }

            $rows = $this->db->query(
                "SELECT id, title, preview, message_count, created_at, updated_at 
                 FROM conversations 
                 ORDER BY updated_at DESC 
                 LIMIT ?",
                [$limit]
            );

            $redis->set($cacheKey, $rows, 1800);
            return $this->success(['conversations' => $rows, 'cached' => false]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get full conversation with all messages by conversation ID.
     */
    public function getConversation(Request $request): Response {
        $id = (int)$request->input('id');
        if (!$id) {
            return $this->error("Conversation ID required", 400);
        }

        try {
            $cacheKey = "exer:convo:{$id}";
            $redis = RedisCacheService::getInstance();

            $cached = $redis->get($cacheKey);
            if (!empty($cached) && is_array($cached) && isset($cached['conversation'])) {
                return $this->success(array_merge($cached, ['cached' => true]));
            }

            $convo = $this->db->query(
                "SELECT * FROM conversations WHERE id = ?",
                [$id]
            );
            if (empty($convo)) {
                return $this->error("Conversation not found", 404);
            }

            $messages = $this->db->query(
                "SELECT id, role, content, metadata_json, created_at 
                 FROM conversation_messages 
                 WHERE conversation_id = ? 
                 ORDER BY id ASC",
                [$id]
            );

            $payload = [
                'conversation' => $convo[0],
                'messages' => $messages
            ];

            $redis->set($cacheKey, $payload, 1800);
            return $this->success(array_merge($payload, ['cached' => false]));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Create a new conversation and optionally save the first message pair.
     */
    public function createConversation(Request $request): Response {
        $title = trim((string)$request->input('title', 'New Conversation'));
        $userMessage = trim((string)$request->input('user_message', ''));
        $aiResponse = trim((string)$request->input('ai_response', ''));
        $metadataJson = $request->input('metadata_json', '');

        try {
            // Auto-generate title from first user message if not provided
            if ($title === 'New Conversation' && !empty($userMessage)) {
                $title = mb_substr($userMessage, 0, 60);
                if (mb_strlen($userMessage) > 60) {
                    $title .= '...';
                }
            }

            $preview = !empty($aiResponse) ? mb_substr(strip_tags($aiResponse), 0, 120) : '';
            $msgCount = 0;

            $this->db->execute(
                "INSERT INTO conversations (title, preview, message_count, created_at, updated_at) 
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [$title, $preview, $msgCount]
            );

            $convoId = $this->db->lastInsertId();

            // Save initial messages if provided
            if (!empty($userMessage)) {
                $this->db->execute(
                    "INSERT INTO conversation_messages (conversation_id, role, content, metadata_json, created_at) 
                     VALUES (?, 'user', ?, ?, CURRENT_TIMESTAMP)",
                    [$convoId, $userMessage, '']
                );
                $msgCount++;
            }
            if (!empty($aiResponse)) {
                $this->db->execute(
                    "INSERT INTO conversation_messages (conversation_id, role, content, metadata_json, created_at) 
                     VALUES (?, 'assistant', ?, ?, CURRENT_TIMESTAMP)",
                    [$convoId, $aiResponse, is_string($metadataJson) ? $metadataJson : json_encode($metadataJson)]
                );
                $msgCount++;
            }

            // Update message count
            if ($msgCount > 0) {
                $this->db->execute(
                    "UPDATE conversations SET message_count = ? WHERE id = ?",
                    [$msgCount, $convoId]
                );
            }

            return $this->success([
                'conversation_id' => $convoId,
                'title' => $title,
                'message' => 'Conversation created'
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Append a message pair (user + assistant) to existing conversation.
     */
    public function appendMessage(Request $request): Response {
        $convoId = (int)$request->input('conversation_id');
        $userMessage = trim((string)$request->input('user_message', ''));
        $aiResponse = trim((string)$request->input('ai_response', ''));
        $metadataJson = $request->input('metadata_json', '');

        if (!$convoId) {
            return $this->error("Conversation ID required", 400);
        }

        try {
            $added = 0;

            if (!empty($userMessage)) {
                $this->db->execute(
                    "INSERT INTO conversation_messages (conversation_id, role, content, metadata_json, created_at) 
                     VALUES (?, 'user', ?, '', CURRENT_TIMESTAMP)",
                    [$convoId, $userMessage]
                );
                $added++;
            }

            if (!empty($aiResponse)) {
                $metaStr = is_string($metadataJson) ? $metadataJson : json_encode($metadataJson);
                $this->db->execute(
                    "INSERT INTO conversation_messages (conversation_id, role, content, metadata_json, created_at) 
                     VALUES (?, 'assistant', ?, ?, CURRENT_TIMESTAMP)",
                    [$convoId, $aiResponse, $metaStr]
                );
                $added++;
            }

            // Update conversation metadata
            $preview = !empty($aiResponse) ? mb_substr(strip_tags($aiResponse), 0, 120) : '';
            $this->db->execute(
                "UPDATE conversations 
                 SET message_count = message_count + ?, 
                     preview = CASE WHEN ? != '' THEN ? ELSE preview END,
                     updated_at = CURRENT_TIMESTAMP 
                 WHERE id = ?",
                [$added, $preview, $preview, $convoId]
            );

            return $this->success([
                'appended' => $added,
                'conversation_id' => $convoId
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Delete a conversation and all its messages.
     */
    public function deleteConversation(Request $request): Response {
        $id = (int)$request->input('id');
        if (!$id) {
            return $this->error("Conversation ID required", 400);
        }

        try {
            $this->db->execute("DELETE FROM conversation_messages WHERE conversation_id = ?", [$id]);
            $this->db->execute("DELETE FROM conversations WHERE id = ?", [$id]);

            // Invalidate Redis cache
            $redis = RedisCacheService::getInstance();
            $redis->del("exer:convo:{$id}");
            $redis->deletePrefix("exer:convos:list");

            return $this->success(['deleted' => $id]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Clear all conversations and all messages.
     */
    public function clearAllConversations(Request $request): Response {
        try {
            $this->db->execute("DELETE FROM conversation_messages");
            $this->db->execute("DELETE FROM conversations");

            // Flush Redis conversation cache
            $redis = RedisCacheService::getInstance();
            $redis->deletePrefix("exer:convo");
            $redis->deletePrefix("exer:convos");

            return $this->success(['message' => 'All conversations cleared successfully']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Rename a conversation title.
     */
    public function renameConversation(Request $request): Response {
        $id = (int)$request->input('id');
        $title = trim((string)$request->input('title', ''));

        if (!$id || empty($title)) {
            return $this->error("Conversation ID and title required", 400);
        }

        try {
            $this->db->execute(
                "UPDATE conversations SET title = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$title, $id]
            );

            // Invalidate Redis cache
            $redis = RedisCacheService::getInstance();
            $redis->del("exer:convo:{$id}");
            $redis->deletePrefix("exer:convos:list");

            return $this->success(['id' => $id, 'title' => $title]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
