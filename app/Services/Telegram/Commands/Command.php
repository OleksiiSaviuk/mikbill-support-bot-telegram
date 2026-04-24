<?php
namespace App\Services\Telegram\Commands;

use App\Services\MikBill\Admin\API;
use App\Services\Wildcore\API as WildcoreAPI;
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
        $normalizedLocale = $this->normalizeLocale($locale);

        if (empty($normalizedLocale)) {
            return;
        }

        app()->setLocale($normalizedLocale);
        Cache::forever($this->user_id . '_locale', $normalizedLocale);
    }

    public function getLocale() {
        $locale = Cache::get($this->user_id . '_locale');

        if (!empty($locale)) {
            return $locale;
        }

        $telegramLocale = $this->extractTelegramLocale();

        if (!empty($telegramLocale)) {
            return $telegramLocale;
        }

        return app()->getLocale();
    }

    private function extractTelegramLocale(): ?string
    {
        $languageCode = null;

        if (isset($this->update->message->from->language_code)) {
            $languageCode = $this->update->message->from->language_code;
        } elseif (isset($this->update->callback_query->from->language_code)) {
            $languageCode = $this->update->callback_query->from->language_code;
        }

        return $this->normalizeLocale($languageCode);
    }

    private function normalizeLocale($locale): ?string
    {
        if (empty($locale)) {
            return null;
        }

        $locale = strtolower((string)$locale);

        if (strpos($locale, 'uk') === 0) {
            return 'uk';
        }

        if (strpos($locale, 'ru') === 0) {
            return 'ru';
        }

        if (strpos($locale, 'en') === 0) {
            return 'en';
        }

        return null;
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

    protected function clearTrackedChatHistory(int $chatId, array $excludeMessageIds = []): int
    {
        if ($chatId <= 0) {
            return 0;
        }

        $key = $this->getTrackedChatMessagesKey($chatId);
        $history = Cache::get($key, []);

        if (!is_array($history) || empty($history)) {
            return 0;
        }

        $excluded = [];
        foreach ($excludeMessageIds as $messageId) {
            $id = (int)$messageId;
            if ($id > 0) {
                $excluded[$id] = true;
            }
        }

        krsort($history, SORT_NUMERIC);
        $deletedCount = 0;

        foreach (array_keys($history) as $messageId) {
            $messageId = (int)$messageId;

            if ($messageId <= 0 || isset($excluded[$messageId])) {
                continue;
            }

            try {
                $this->bot->deleteMessage([
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                ]);
            } catch (\Throwable $e) {
                // Continue to remove tracked history even if Telegram rejects some IDs.
            }

            unset($history[$messageId]);
            $deletedCount++;
        }

        if (empty($history)) {
            Cache::forget($key);
            return $deletedCount;
        }

        $retentionDays = max(7, (int)config('telebot.bots.bot.history_retention_days', 7));
        Cache::put($key, $history, now()->addDays($retentionDays + 2));

        return $deletedCount;
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
            $onu = $this->getOnuByClientMac($user['local_mac'] ?? null);

            $text = "<b>" . trans("user_info") . "</b>  \n";
            $text .= "<b>" . trans("login") . ":</b> " . $user['user'] . "\n";
            $text .= "<b>" . trans("password") . ":</b> <tg-spoiler>" . htmlspecialchars((string)($user['password'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</tg-spoiler>\n";
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
            $text .= "<b>Local MAC:</b> " . ($user['local_mac'] ?? '-') . "\n";
            $text .= "<b>" . trans("internet") . ":</b> " . ($user['blocked'] ? '🚫' : '✅') . "\n";
            $text .= "<b>On-line:</b> " . ($user['online'] ? '✅' : '🚫') . "\n";
            $text .= "<b>" . trans("status") . ":</b> " . $status . "\n";
            $text .= "<b>" . trans("last_auth") . ":</b> " . $user['last_connection'] . "\n";
            $text .= "<b>" . trans("address") . ":</b> " . $user['address'] . "\n";
            $text .= $this->buildOnuMessageBlock($onu);

            $inlineKeyboard = [
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
            ];

            $onuRefreshRow = $this->buildOnuRefreshButtonRow($onu);

            if (!empty($onuRefreshRow)) {
                $inlineKeyboard[] = $onuRefreshRow;
            }

            $inlineKeyboard[] = [
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
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => $inlineKeyboard
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
                "text"          => trans("menu_clear_history"),
                "callback_data" => "menuClearHistory"
            ]
        ];

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

    protected function getOnuByClientMac($clientMac): ?array
    {
        try {
            $wildcoreApi = new WildcoreAPI();
            return $wildcoreApi->get_onu_by_client_mac($clientMac);
        } catch (\Throwable $e) {
            Log::warning('Wildcore ONU lookup failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function getOnuByInterfaceAndClientMac(string $interfaceId, string $clientMac, string $source = 'cache'): ?array
    {
        try {
            $wildcoreApi = new WildcoreAPI();

            if (!WildcoreAPI::wildcore_enabled()) {
                return null;
            }

            $search = $wildcoreApi->search_onu_by_client_mac($clientMac);

            if (empty($search) || (string)($search['interface_id'] ?? '') !== (string)$interfaceId) {
                return null;
            }

            $diag = $wildcoreApi->get_onu_diagnostic($interfaceId, $source);

            if (!is_array($diag)) {
                return null;
            }

            return $wildcoreApi->parse_onu_info($search, $diag, $clientMac);
        } catch (\Throwable $e) {
            Log::warning('Wildcore ONU refresh failed', [
                'message' => $e->getMessage(),
                'interface_id' => $interfaceId,
            ]);
        }

        return null;
    }

    protected function buildOnuMessageBlock(?array $onu): string
    {
        if (empty($onu)) {
            return '';
        }

        $lines = [];

        $lines[] = '';
        $lines[] = '📡 <b>' . $this->escapeHtml(trans('onu_block_title')) . '</b>';
        $this->addOnuLine($lines, trans('onu_label_status'), $onu['status'] ?? null);
        $this->addOnuLine(
            $lines,
            trans('onu_label_olt'),
            trim((string)($onu['olt_name'] ?? '')) . ((empty($onu['olt_ip']) || empty($onu['olt_name'])) ? '' : ' / ') . trim((string)($onu['olt_ip'] ?? ''))
        );
        $this->addOnuLine($lines, trans('onu_label_port'), $onu['interface_name'] ?? null);
        $this->addOnuLine($lines, trans('onu_label_onu'), $onu['onu_ident'] ?? null);
        $this->addOnuLine($lines, trans('onu_label_description'), $onu['description'] ?? null);

        $opticLines = [];
        $this->addOnuLine($opticLines, trans('onu_label_rx_onu'), $this->formatOnuMetric($onu['rx'] ?? null, ' dBm'));
        $this->addOnuLine($opticLines, trans('onu_label_rx_olt'), $this->formatOnuMetric($onu['olt_rx'] ?? null, ' dBm'));
        $this->addOnuLine($opticLines, trans('onu_label_tx_onu'), $this->formatOnuMetric($onu['tx'] ?? null, ' dBm'));

        if (!empty($opticLines)) {
            $lines[] = '';
            $lines[] = '🔦 <b>' . $this->escapeHtml(trans('onu_optics_title')) . '</b>';
            $lines = array_merge($lines, $opticLines);
        }

        $eventLines = [];
        $this->addOnuLine($eventLines, trans('onu_label_last_reg'), $onu['last_reg'] ?? null);
        $this->addOnuLine($eventLines, trans('onu_label_last_dereg'), $onu['last_dereg'] ?? null);
        $this->addOnuLine($eventLines, trans('onu_label_reason'), $onu['last_down_reason'] ?? null);

        if (!empty($eventLines)) {
            $lines[] = '';
            $lines[] = '🕓 <b>' . $this->escapeHtml(trans('onu_events_title')) . '</b>';
            $lines = array_merge($lines, $eventLines);
        }

        $lanLines = [];
        $this->addOnuLine($lanLines, trans('onu_label_uni'), $onu['uni_ports'] ?? null);
        $this->addOnuLine($lanLines, trans('onu_label_mac_fdb'), $this->formatOnuFdbFlag($onu['client_mac_found_in_fdb'] ?? null));
        $this->addOnuLine($lanLines, trans('onu_label_vlan'), $onu['vlan'] ?? null);

        if (!empty($lanLines)) {
            $lines[] = '';
            $lines[] = '🔌 <b>' . $this->escapeHtml(trans('onu_lan_title')) . '</b>';
            $lines = array_merge($lines, $lanLines);
        }

        $this->addOnuLine($lines, trans('onu_label_summary'), $onu['summary'] ?? null);

        return implode("\n", $lines) . "\n";
    }

    protected function buildOnuRefreshButtonRow(?array $onu): array
    {
        $interfaceId = (string)($onu['interface_id'] ?? '');
        $clientMac = (string)($onu['client_mac'] ?? '');

        if ($interfaceId === '' || $clientMac === '') {
            return [];
        }

        return [
            [
                'text' => trans('onu_refresh_button'),
                'callback_data' => 'refresh_onu:' . $interfaceId . ':' . $clientMac,
            ]
        ];
    }

    protected function replaceOnuBlock(string $messageText, string $onuBlock): string
    {
        $markerHtml = "\n📡 <b>" . $this->escapeHtml(trans('onu_block_title')) . "</b>";
        $position = strpos($messageText, $markerHtml);

        if ($position === false) {
            $markerPlain = "\n📡 " . trans('onu_block_title');
            $position = strpos($messageText, $markerPlain);
        }

        if ($position === false) {
            $position = strpos($messageText, "\n📡 <b>ONU / ONT</b>");
        }

        if ($position === false) {
            $position = strpos($messageText, "\n📡 ONU / ONT");
        }

        if ($position === false) {
            return rtrim($messageText) . "\n" . ltrim($onuBlock, "\n");
        }

        return rtrim(substr($messageText, 0, $position)) . "\n" . ltrim($onuBlock, "\n");
    }

    protected function formatOnuMetric($value, string $suffix = ''): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return number_format((float)$value, 2, '.', '') . $suffix;
        }

        return trim((string)$value) !== '' ? trim((string)$value) . $suffix : null;
    }

    protected function formatOnuFdbFlag($flag): ?string
    {
        if ($flag === true) {
            return trans('yes');
        }

        if ($flag === false) {
            return trans('no');
        }

        return null;
    }

    protected function addOnuLine(array &$lines, string $label, $value): void
    {
        if ($value === null) {
            return;
        }

        $text = trim((string)$value);

        if ($text === '') {
            return;
        }

        $lines[] = '<b>' . $label . ':</b> ' . $this->escapeHtml($text);
    }

    protected function escapeHtml($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
