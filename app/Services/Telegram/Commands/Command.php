<?php
namespace App\Services\Telegram\Commands;

use App\Services\MikBill\Admin\API;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use WeStacks\TeleBot\Handlers\CommandHandler;
use WeStacks\TeleBot\Objects\Update;
use WeStacks\TeleBot\TeleBot;

abstract class Command extends CommandHandler
{
    private $isAuth = false;
    private $user_id = -1;

    public function __construct(TeleBot $bot, Update $update)
    {
        parent::__construct($bot, $update);
        $this->checkAuth();

        app()->setLocale($this->getLocale());
        $this->trackCurrentUpdateMessage();
        $this->purgeExpiredChatHistory();
    }

    public function sendMessage($data = [])
    {
        $response = parent::sendMessage($data);

        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : (int)$this->resolveChatId();

        if ($chatId > 0) {
            $messageId = null;

            if (is_object($response) && isset($response->message_id)) {
                $messageId = (int)$response->message_id;
            } elseif (is_array($response) && isset($response['message_id'])) {
                $messageId = (int)$response['message_id'];
            }

            if (!empty($messageId)) {
                $this->trackChatMessage($chatId, $messageId);
            }
        }

        return $response;
    }

    public function isAuth()
    {
        return $this->isAuth;
    }

    public function checkAuth()
    {
        $allowed_id = config('telebot.bots.bot.allowed_id');

        if (isset($this->update->message->from->id)) {
            $this->user_id = $this->update->message->from->id;
        } elseif (isset($this->update->callback_query->from->id)) {
            $this->user_id = $this->update->callback_query->from->id;
        }

        if(empty($allowed_id)) {
            return $this->isAuth = true;
        }

        return $this->isAuth = in_array($this->user_id, $allowed_id);
    }

    public function setLastAction($action)
    {
        Cache::put($this->user_id . '_last_action', $action);
    }

    public function getLastAction()
    {
        return Cache::get($this->user_id . '_last_action');
    }

    public function setLocale($locale) {
        app()->setLocale($locale);
        Cache::put($this->user_id . '_locale', $locale);
    }

    public function getLocale() {
        $locale = Cache::get($this->user_id . '_locale');
        if( empty($locale) ) {
            $locale = app()->getLocale();
        }

        return $locale;
    }

    public function clearUserState(): void
    {
        Cache::forget($this->user_id . '_last_action');
        Cache::forget($this->user_id . '_search_state');
        Cache::forget($this->user_id . '_start_phone_lock');
        Cache::forget($this->user_id . '_last_start_phone');
    }

    protected function clearChatHistoryFromMessage(int $fromMessageId, int $limit = 0): void
    {
        $chatId = $this->resolveChatId();

        if (empty($chatId) || $fromMessageId < 1) {
            return;
        }

        $minMessageId = 1;

        if ($limit > 0) {
            $minMessageId = max(1, $fromMessageId - $limit + 1);
        }

        for ($messageId = $fromMessageId; $messageId >= $minMessageId; $messageId--) {
            try {
                $this->bot->deleteMessage([
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                ]);
            } catch (\Throwable $e) {
                // Continue deleting remaining messages even if some IDs cannot be deleted.
            }
        }

        Cache::forget($this->getTrackedChatMessagesKey((int)$chatId));
    }

    protected function trackCurrentUpdateMessage(): void
    {
        if (isset($this->update->message->chat->id, $this->update->message->message_id)) {
            $this->trackChatMessage(
                (int)$this->update->message->chat->id,
                (int)$this->update->message->message_id
            );
        }

        if (isset($this->update->callback_query->message->chat->id, $this->update->callback_query->message->message_id)) {
            $this->trackChatMessage(
                (int)$this->update->callback_query->message->chat->id,
                (int)$this->update->callback_query->message->message_id
            );
        }
    }

    protected function purgeExpiredChatHistory(): void
    {
        $retentionDays = (int)config('telebot.bots.bot.history_retention_days', 7);

        if ($retentionDays <= 0) {
            return;
        }

        $chatId = (int)$this->resolveChatId();

        if ($chatId <= 0) {
            return;
        }

        $purgeIntervalSeconds = max(10, (int)config('telebot.bots.bot.history_purge_interval_seconds', 300));
        $lastPurgeKey = $this->getLastPurgeAtKey($chatId);
        $lastPurgeAt = (int)Cache::get($lastPurgeKey, 0);

        if ($lastPurgeAt > 0 && (time() - $lastPurgeAt) < $purgeIntervalSeconds) {
            return;
        }

        Cache::put($lastPurgeKey, time(), now()->addDay());

        $history = Cache::get($this->getTrackedChatMessagesKey($chatId), []);

        if (!is_array($history) || empty($history)) {
            return;
        }

        $cutoff = time() - ($retentionDays * 86400);
        $deleteBatchSize = max(1, (int)config('telebot.bots.bot.history_delete_batch_size', 25));
        asort($history);
        $changed = false;
        $deletedCount = 0;

        foreach ($history as $messageId => $createdAt) {
            if ((int)$createdAt >= $cutoff) {
                continue;
            }

            if ($deletedCount >= $deleteBatchSize) {
                break;
            }

            try {
                $this->bot->deleteMessage([
                    'chat_id'    => $chatId,
                    'message_id' => (int)$messageId,
                ]);
            } catch (\Throwable $e) {
                // Ignore deletion errors and still drop stale ID from tracked cache.
            }

            unset($history[$messageId]);
            $changed = true;
            $deletedCount++;
        }

        if (!$changed) {
            return;
        }

        if (empty($history)) {
            Cache::forget($this->getTrackedChatMessagesKey($chatId));
            return;
        }

        Cache::put(
            $this->getTrackedChatMessagesKey($chatId),
            $history,
            now()->addDays($retentionDays + 2)
        );
    }

    protected function trackChatMessage(int $chatId, int $messageId): void
    {
        if ($chatId <= 0 || $messageId <= 0) {
            return;
        }

        $key = $this->getTrackedChatMessagesKey($chatId);
        $history = Cache::get($key, []);

        if (!is_array($history)) {
            $history = [];
        }

        $history[$messageId] = time();

        $trackLimit = max(100, (int)config('telebot.bots.bot.history_track_limit', 5000));

        if (count($history) > $trackLimit) {
            asort($history);
            $history = array_slice($history, -$trackLimit, null, true);
        }

        $retentionDays = max(7, (int)config('telebot.bots.bot.history_retention_days', 7));
        Cache::put($key, $history, now()->addDays($retentionDays + 2));
    }

    protected function getTrackedChatMessagesKey(int $chatId): string
    {
        return $chatId . '_tracked_chat_messages';
    }

    protected function getLastPurgeAtKey(int $chatId): string
    {
        return $chatId . '_chat_history_last_purge_at';
    }

    protected function resolveChatId()
    {
        if (isset($this->update->callback_query->message->chat->id)) {
            return $this->update->callback_query->message->chat->id;
        }

        if (isset($this->update->message->chat->id)) {
            return $this->update->message->chat->id;
        }

        return $this->user_id > 0 ? $this->user_id : null;
    }

    protected function formatMoney($value): string
    {
        return number_format((float)$value, 2, '.', '');
    }

    protected function performUserSearch(string $type, string $query): void
    {
        $api = new API();
        $users = $api->searchUsersMB($query, $type);

        if (empty($users)) {
            $this->sendMessage([
                'text'         => trans("user_not_found"),
                'parse_mode'   => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                "text"          => trans("menu_search"),
                                "callback_data" => "menuSearch"
                            ],
                            [
                                'text'          => trans("menu_main"),
                                'callback_data' => "menuMain"
                            ]
                        ]
                    ]
                ]
            ]);

            return;
        }

        $this->storeSearchState($type, $query, $users);
        $this->sendSearchResultPage(1);
    }

    protected function sendSearchResultPage(int $page = 1): void
    {
        $state = $this->getSearchState();

        if (empty($state['uids'])) {
            $this->sendMessage([
                'text'       => trans("user_not_found"),
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        $cabinet_host = config('services.mikbill.cabinet_host');
        $api = new API();
        $systemOptions = $api->getSystemOptions();

        $perPage = max(1, (int)config('telebot.bots.bot.search_per_page', 5));
        $total = count($state['uids']);
        $totalPages = (int)ceil($total / $perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $uids = array_slice($state['uids'], $offset, $perPage);

        foreach ($uids as $uid) {
            $user = $api->getUserMB($uid);
            $status = $this->resolveUserStatus($user['state'] ?? null);

            $text = "<b>" . trans("user_info") . "</b>  \n";
            $text .= "<b>" . trans("login") . ":</b> " . $user['user'] . "\n";
            $text .= "<b>" . trans("password") . ":</b> " . $user['password'] . "\n";
            $text .= "<b>" . trans("uid") . ":</b>" . $user['useruid'] . " \n";
            $text .= "<b>" . trans("contract") . ":</b>" . $user['numdogovor'] . " \n";
            $text .= "<b>" . trans("fio") . ":</b> " . $user['fio'] . "\n";
            $text .= "<b>" . trans("tariff") . ":</b> " . $user['tarif'] . "\n";
            $text .= "<b>" . trans("phone_mob") . "</b> " . $user['mob_tel'] . "\n";
            $text .= "<b>" . trans("phone_sms") . ":</b> " . $user['sms_tel'] . "\n";
            $text .= "<b>" . trans("deposit") . ":</b> " . $this->formatMoney($user['deposit']) . " " . (isset($systemOptions['data'][0]['UE']) ? $systemOptions['data'][0]['UE'] : 'грн.') . " \n";
            $text .= "<b>" . trans("credit") . ":</b> " . $this->formatMoney($user['credit']) . " " . (isset($systemOptions['data'][0]['UE']) ? $systemOptions['data'][0]['UE'] : 'грн.') . " \n";
            $text .= "<b>Framed IP:</b> " . $user['framed_ip'] . "\n";
            $text .= "<b>Local IP:</b> " . $user['local_ip'] . "\n";
            $text .= "<b>" . trans("internet") . ":</b> " . ($user['blocked'] ? '🚫' : '✅') . "\n";
            $text .= "<b>On-line:</b> " . ($user['online'] ? '✅' : '🚫') . "\n";
            $text .= "<b>" . trans("status") . ":</b> " . $status . "\n";
            $text .= "<b>" . trans("last_auth") . ":</b> " . $user['last_connection'] . "\n";
            $text .= "<b>" . trans("address") . ":</b> " . $user['address'] . "\n";

            $this->sendMessage([
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                "text"          => trans("menu_history_sessions"),
                                "callback_data" => "menuHistorySessions_" . $user['useruid']
                            ],
                            [
                                "text"          => trans("menu_history_payments"),
                                "callback_data" => "menuHistoryPayments_" . $user['useruid']
                            ],
                        ],
                        [
                            [
                                "text"          => trans("menu_history_tickets"),
                                "callback_data" => "menuHistoryTickets_" . $user['useruid']
                            ],
                            [
                                "text"          => trans("menu_history_auths"),
                                "callback_data" => "menuHistoryAuths_" . $user['useruid']
                            ]
                        ],
                        [
                            [
                                "text"          => trans("menu_history_logs"),
                                "callback_data" => "menuHistoryLogs_" . $user['useruid']
                            ],
                            [
                                "text"          => trans("menu_user_kick"),
                                "callback_data" => "menuUserKick_" . $user['useruid']
                            ]
                        ],
                        [
                            [
                                "text"          => trans("menu_services"),
                                "callback_data" => "menuServices_" . $user['useruid']
                            ],
                            [
                                "text" => trans("cabinet_auth"),
                                "url"  => $cabinet_host . "/?l=" . $user['user'] . "&p=" . $user['password']
                            ],
                        ],
                        [
                            [
                                "text"          => trans("menu_search"),
                                "callback_data" => "menuSearch"
                            ],
                            [
                                'text'          => trans("menu_main"),
                                'callback_data' => "menuMain"
                            ]
                        ]
                    ]
                ]
            ]);
        }

        $navigationButtons = [];

        if ($page > 1) {
            $navigationButtons[] = [
                'text'          => '⬅️ Prev',
                'callback_data' => 'menuSearchPage_' . ($page - 1),
            ];
        }

        if ($page < $totalPages) {
            $navigationButtons[] = [
                'text'          => 'Next ➡️',
                'callback_data' => 'menuSearchPage_' . ($page + 1),
            ];
        }

        $keyboard = [];

        if (!empty($navigationButtons)) {
            $keyboard[] = $navigationButtons;
        }

        $keyboard[] = [
            [
                "text"          => trans("menu_search"),
                "callback_data" => "menuSearch"
            ],
            [
                'text'          => trans("menu_main"),
                'callback_data' => "menuMain"
            ]
        ];

        $this->sendMessage([
            'text'         => "Found: {$total}. Page {$page}/{$totalPages}",
            'parse_mode'   => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => $keyboard
            ]
        ]);
    }

    protected function storeSearchState(string $type, string $query, array $users): void
    {
        $uids = [];

        foreach ($users as $row) {
            if (isset($row['useruid'])) {
                $uids[] = $row['useruid'];
            }
        }

        Cache::put($this->getSearchStateKey(), [
            'type'  => $type,
            'query' => $query,
            'uids'  => array_values(array_unique($uids)),
        ], now()->addMinutes(30));
    }

    protected function getSearchState(): array
    {
        $state = Cache::get($this->getSearchStateKey(), []);

        if (!is_array($state)) {
            return [];
        }

        return $state;
    }

    protected function getSearchStateKey(): string
    {
        return $this->user_id . '_search_state';
    }

    protected function resolveUserStatus($state): string
    {
        switch ($state) {
            case 1:
                return trans("state_1");
            case 2:
                return trans("state_2");
            case 3:
                return trans("state_3");
            case 4:
                return trans("state_4");
            default:
                return trans("state_1");
        }
    }
}
