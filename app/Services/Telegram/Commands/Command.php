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
            $onu = $this->getOnuByClientMac($user['local_mac'] ?? null);
            $text = $this->buildUserCardMessage($user, $onu, $systemOptions);

            $inlineKeyboard = $this->buildUserCardInlineKeyboard($user, $onu);

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
                    'text'          => trans('pagination_prev'),
                'callback_data' => 'menuSearchPage_' . ($page - 1),
            ];
        }

        if ($page < $totalPages) {
            $navigationButtons[] = [
                    'text'          => trans('pagination_next'),
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
            'text'         => trans('search_results_page', ['total' => $total, 'page' => $page, 'pages' => $totalPages]),
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

            return $wildcoreApi->get_connection_by_interface_and_client_mac($interfaceId, $clientMac, $source);
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

        $connectionType = (string)($onu['connection_type'] ?? 'onu');

        if ($connectionType === 'switch_port') {
            return $this->buildSwitchPortMessageBlock($onu);
        }

        if ($connectionType === 'unknown') {
            return $this->buildUnknownConnectionMessageBlock($onu);
        }

        $lines = [];
        $location = $this->buildLocationLine($onu['olt_name'] ?? null, $onu['interface_name'] ?? null);
        $lanStatus = $this->resolveLanStatus($onu['uni_ports'] ?? null, $onu['status'] ?? null);
        $macState = $this->resolveMacPresenceText($onu['client_mac_found_in_fdb'] ?? null);
        $lastDown = $this->formatOnuEventTime($onu['last_dereg'] ?? null);
        $lastUp = $this->formatOnuEventTime($onu['last_reg'] ?? null);
        $lastDownReason = $onu['last_down_reason'] ?? null;
        $onuOnlineDuration = $this->resolveOnuOnlineDuration($onu);

        $status = $onu['status'] ?? null;
        $statusIcon = ($status !== null && stripos($status, 'online') !== false) ? '✅' : '❌';

        $lines[] = '';
            $lines[] = ' <b>' . $this->escapeHtml(trans('onu_block_title')) . '</b>';
        if ($status !== null) {
            $lines[] = trans('onu_label_status') . ': ' . $statusIcon . ' ' . $this->escapeHtml($status);
        }
        $this->addPlainLine($lines, 'RX', $this->formatOnuMetric($onu['rx'] ?? null, ' dBm')); // RX is a technical abbreviation — no translation needed

        if ($lastDownReason !== null || $lastDown !== null || $lastUp !== null || $onuOnlineDuration !== null) {
            $lines[] = '';
            $this->addPlainLine($lines, trans('onu_label_last_down_reason'), $lastDownReason);
            if ($lastUp !== null) {
                $lines[] = $this->escapeHtml($lastUp) . ' ' . trans('onu_event_up');
            }
            if ($lastDown !== null) {
                $lines[] = $this->escapeHtml($lastDown) . ' ' . trans('onu_event_down');
            }
            $this->addPlainLine($lines, trans('onu_label_online_duration'), $onuOnlineDuration);
        }

        $lines[] = '';
        $lines[] = trans('common_label_lan') . ': ' . $this->escapeHtml($lanStatus ?? '-');
        $lines[] = trans('common_label_mac') . ': ' . $this->escapeHtml($macState ?? '-');

        if ($location !== null || !empty($onu['description'])) {
            $lines[] = '';
            if ($location !== null) {
                $lines[] =  $this->escapeHtml($location);
            }
            $this->addPlainLine($lines, trans('onu_label_description'), $onu['description'] ?? null);
        }

        $lines[] = '';
        $this->addPlainLine($lines, trans('onu_label_summary'), $onu['summary'] ?? null);

        return implode("\n", $lines) . "\n";
    }

    protected function buildOnuRefreshButtonRow(?array $onu): array
    {
        if (empty($onu) || !WildcoreAPI::wildcore_enabled()) {
            return [];
        }

        $interfaceId = (string)($onu['interface_id'] ?? '');
        $clientMac = (string)($onu['client_mac'] ?? '');

        if ($interfaceId === '' || $clientMac === '') {
            return [];
        }

        return [
            [
                'text' => trans('wildcore_refresh_button'),
                'callback_data' => 'refresh_wc:' . $interfaceId . ':' . $clientMac,
            ]
        ];
    }

    protected function buildUserCardInlineKeyboard(array $user, ?array $onu): array
    {
        $cabinetHost = (string)config('services.mikbill.cabinet_host');

        $inlineKeyboard = [
            [
                [
                    'text' => trans('menu_history_sessions'),
                    'callback_data' => 'menuHistorySessions_' . $user['useruid'],
                ],
                [
                    'text' => trans('menu_history_payments'),
                    'callback_data' => 'menuHistoryPayments_' . $user['useruid'],
                ],
            ],
            [
                [
                    'text' => trans('menu_history_tickets'),
                    'callback_data' => 'menuHistoryTickets_' . $user['useruid'],
                ],
                [
                    'text' => trans('menu_history_auths'),
                    'callback_data' => 'menuHistoryAuths_' . $user['useruid'],
                ],
            ],
            [
                [
                    'text' => trans('menu_history_logs'),
                    'callback_data' => 'menuHistoryLogs_' . $user['useruid'],
                ],
                [
                    'text' => trans('menu_user_kick'),
                    'callback_data' => 'menuUserKick_' . $user['useruid'],
                ],
            ],
            [
                [
                    'text' => trans('menu_services'),
                    'callback_data' => 'menuServices_' . $user['useruid'],
                ],
                [
                    'text' => trans('cabinet_auth'),
                    'url' => $cabinetHost . '/?l=' . $user['user'] . '&p=' . $user['password'],
                ],
            ],
        ];

        $onuRefreshRow = $this->buildOnuRefreshButtonRow($onu);

        if (!empty($onuRefreshRow)) {
            $inlineKeyboard[] = $onuRefreshRow;
        }

        $inlineKeyboard[] = [
            [
                'text' => trans('menu_search'),
                'callback_data' => 'menuSearch',
            ],
            [
                'text' => trans('menu_main'),
                'callback_data' => 'menuMain',
            ],
        ];

        return $inlineKeyboard;
    }

    protected function buildUserCardMessage(array $user, ?array $onu, array $systemOptions = []): string
    {
        $status = $this->resolveUserStatus($user['state'] ?? null);
        $currency = isset($systemOptions['data'][0]['UE']) ? (string)$systemOptions['data'][0]['UE'] : 'грн.';
        $mobilePhone = $this->formatPhoneInternational($user['mob_tel'] ?? null);
        $smsPhone = $this->formatPhoneInternational($user['sms_tel'] ?? null);
        $onlineDuration = $this->resolveUserOnlineDuration($user);

        $text = '<b>' . trans('user_info') . '</b>  ' . "\n";
        $text .= '<b>' . trans('login') . ':</b> ' . ($user['user'] ?? '') . "\n";
        $text .= '<b>' . trans('password') . ':</b> <tg-spoiler>' . htmlspecialchars((string)($user['password'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</tg-spoiler>' . "\n";
        $text .= '<b>' . trans('uid') . ':</b>' . ($user['useruid'] ?? '') . " \n";
        $text .= '<b>' . trans('contract') . ':</b>' . ($user['numdogovor'] ?? '') . " \n";
        $text .= '<b>' . trans('fio') . ':</b> ' . ($user['fio'] ?? '') . "\n";
        $text .= '<b>' . trans('tariff') . ':</b> ' . ($user['tarif'] ?? '') . "\n";
        $text .= '<b>' . trans('phone_mob') . '</b> ' . $mobilePhone . "\n";
        $text .= '<b>' . trans('phone_sms') . ':</b> ' . $smsPhone . "\n";
        $text .= '<b>' . trans('deposit') . ':</b> ' . $this->formatMoney($user['deposit'] ?? 0) . ' ' . $currency . " \n";
        $text .= '<b>' . trans('credit') . ':</b> ' . $this->formatMoney($user['credit'] ?? 0) . ' ' . $currency . " \n";
        $text .= '<b>' . trans('user_label_framed_ip') . ':</b> ' . ($user['framed_ip'] ?? '') . "\n";
        $text .= '<b>' . trans('user_label_local_mac') . ':</b> ' . (($user['local_mac'] ?? '') !== '' ? $user['local_mac'] : '-') . "\n";
        $text .= '<b>' . trans('internet') . ':</b> ' . (!empty($user['blocked']) ? '❌' : '✅') . "\n";
        $text .= '<b>' . trans('user_label_online') . ':</b> ' . (!empty($user['online']) ? '✅' : '❌') . "\n";
        $text .= '<b>' . trans('status') . ':</b> ' . $status . "\n";
        $text .= '<b>' . trans('last_auth') . ':</b> ' . ($user['last_connection'] ?? '') . "\n";
        $text .= '<b>' . trans('user_label_online_duration') . ':</b> ' . $this->escapeHtml($onlineDuration ?? '-') . "\n";
        $text .= '<b>' . trans('address') . ':</b> ' . ($user['address'] ?? '') . "\n";
        $text .= $this->buildOnuMessageBlock($onu);

        return $text;
    }

    protected function extractUserUidFromMessageText(string $messageText): ?int
    {
        if (preg_match('/(?:^|\n)UID:\s*(\d+)/u', $messageText, $matches) === 1) {
            return (int)$matches[1];
        }

        return null;
    }

    protected function buildSwitchPortMessageBlock(array $data): string
    {
        $lines = [];
        $location = $this->buildLocationLine($data['device_name'] ?? null, $data['interface_name'] ?? $data['name'] ?? null);
        $lanStatus = $this->resolveLanStatus($data['status'] ?? null, $data['status'] ?? null);
        $statusEmoji = $this->resolveStatusEmoji($lanStatus);
        $macState = !empty($data['client_mac']) ? trans('yes') : null;
        $summary = !empty($data['status'])
            ? ($statusEmoji . trans('access_port_label_status') . ': ' . (string)$data['status'])
            : trans('mac_found_block_title');

        $lines[] = '';
        $lines[] = ' <b>' . $this->escapeHtml(trans('access_port_block_title')) . '</b>';
        $this->addPlainLine($lines, trans('access_port_label_status'), $data['status'] ?? null);
        $this->addPlainLine($lines, trans('access_port_label_type'), $data['interface_type'] ?? null);

        $lines[] = '';
        $lines[] =  trans('common_label_lan') . ': ' . $this->escapeHtml($lanStatus ?? '-');
        $lines[] = trans('common_label_mac') . ': ' . $this->escapeHtml($macState ?? '-');

        if ($location !== null || !empty($data['description'])) {
            $lines[] = '';
            if ($location !== null) {
                $lines[] =  $this->escapeHtml($location);
            }
            $this->addPlainLine($lines, trans('access_port_label_description'), $data['description'] ?? null);
        }

        $lines[] = '';
        $this->addPlainLine($lines, trans('onu_label_summary'), $summary);

        return implode("\n", $lines) . "\n";
    }

    protected function buildUnknownConnectionMessageBlock(array $data): string
    {
        $lines = [];
        $location = $this->buildLocationLine($data['device_name'] ?? null, $data['interface_name'] ?? $data['name'] ?? null);
        $lanStatus = $this->resolveLanStatus($data['status'] ?? null, $data['status'] ?? null);
        $statusEmoji = $this->resolveStatusEmoji($lanStatus);
        $summary = !empty($data['status'])
            ? ($statusEmoji . trans('access_port_label_status') . ': ' . (string)$data['status'])
            : (!empty($data['interface_type'])
                ? (trans('mac_found_label_type') . ': ' . (string)$data['interface_type'])
                : trans('mac_found_block_title'));

        $lines[] = '';
        $lines[] = '🔎 <b>' . $this->escapeHtml(trans('mac_found_block_title')) . '</b>';
        $this->addPlainLine($lines, trans('mac_found_label_type'), $data['interface_type'] ?? null);

        $lines[] = '';
        $lines[] =  trans('common_label_lan') . ': -';
        $lines[] = trans('common_label_mac') . ': ' . $this->escapeHtml(trans('yes'));

        if ($location !== null || !empty($data['description'])) {
            $lines[] = '';
            if ($location !== null) {
                $lines[] =  $this->escapeHtml($location);
            }
            $this->addPlainLine($lines, trans('mac_found_label_description'), $data['description'] ?? null);
        }

        $lines[] = '';
        $this->addPlainLine($lines, trans('onu_label_summary'), $summary);

        return implode("\n", $lines) . "\n";
    }

    protected function addPlainLine(array &$lines, string $label, $value): void
    {
        if ($value === null) {
            return;
        }

        $text = trim((string)$value);

        if ($text === '') {
            return;
        }

        $lines[] = $label . ': ' . $this->escapeHtml($text);
    }

    protected function buildLocationLine($deviceName, $interfaceName): ?string
    {
        $device = trim((string)$deviceName);
        $iface = trim((string)$interfaceName);

        if ($device !== '' && $iface !== '') {
            return $device . ' / ' . $iface;
        }

        if ($device !== '') {
            return $device;
        }

        if ($iface !== '') {
            return $iface;
        }

        return null;
    }

    protected function resolveLanStatus($primary, $fallback = null): ?string
    {
        $value = strtolower(trim((string)($primary ?? '')));
        if ($value === '' && $fallback !== null) {
            $value = strtolower(trim((string)$fallback));
        }

        if ($value === '') {
            return null;
        }

        if (strpos($value, 'up') !== false || strpos($value, 'online') !== false || strpos($value, 'active') !== false) {
            return 'up';
        }

        if (strpos($value, 'down') !== false || strpos($value, 'offline') !== false || strpos($value, 'inactive') !== false) {
            return 'down';
        }

        return $value;
    }

    protected function resolveStatusEmoji(?string $status): string
    {
        $value = strtolower(trim((string)($status ?? '')));

        if ($value === 'up' || $value === 'online' || $value === 'active') {
            return '✅ ';
        }

        if ($value === 'down' || $value === 'offline' || $value === 'inactive') {
            return '❌ ';
        }

        return '';
    }

    protected function resolveMacPresenceText($flag): ?string
    {
        if ($flag === true) {
            return trans('yes');
        }

        if ($flag === false) {
            return trans('no');
        }

        return null;
    }

    protected function formatOnuEventTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            $timezone = $this->resolveDisplayTimezone();
            $dateTime = new \DateTimeImmutable($raw, $timezone);

            return $dateTime->setTimezone($timezone)->format('d.m.y H:i');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    protected function replaceOnuBlock(string $messageText, string $onuBlock): string
    {
        $position = false;
        $markers = [];
        $locales = ['uk', 'ru', 'en'];

        foreach ($locales as $locale) {
            $onuTitle = (string)trans('onu_block_title', [], $locale);
            $accessPortTitle = (string)trans('access_port_block_title', [], $locale);
            $macFoundTitle = (string)trans('mac_found_block_title', [], $locale);

            $markers[] = "\n <b>" . $this->escapeHtml($onuTitle) . "</b>";
            $markers[] = "\n " . $onuTitle;
            $markers[] = "\n <b>" . $this->escapeHtml($accessPortTitle) . "</b>";
            $markers[] = "\n " . $accessPortTitle;
            $markers[] = "\n🔎 <b>" . $this->escapeHtml($macFoundTitle) . "</b>";
            $markers[] = "\n🔎 " . $macFoundTitle;
        }

        $markers = array_values(array_unique($markers));

        foreach ($markers as $marker) {
            $found = strpos($messageText, $marker);
            if ($found !== false && ($position === false || $found < $position)) {
                $position = $found;
            }
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

    protected function resolveDisplayTimezone(): \DateTimeZone
    {
        $timezoneName = (string)config('telebot.bots.bot.timezone', config('app.timezone', 'UTC'));

        try {
            return new \DateTimeZone($timezoneName);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    protected function formatDurationSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if (empty($parts) || $seconds > 0) {
            $parts[] = $seconds . 's';
        }

        return implode(' ', $parts);
    }

    protected function formatDurationValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return $this->formatDurationSeconds((int)$value);
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d+:[0-5]\d(?::[0-5]\d)?$/', $raw) === 1) {
            $parts = explode(':', $raw);
            if (count($parts) === 2) {
                [$hours, $minutes] = $parts;
                return $this->formatDurationSeconds(((int)$hours * 3600) + ((int)$minutes * 60));
            }
            if (count($parts) === 3) {
                [$hours, $minutes, $seconds] = $parts;
                return $this->formatDurationSeconds(((int)$hours * 3600) + ((int)$minutes * 60) + (int)$seconds);
            }
        }

        if (preg_match('/^\d+\s*[dhms]/i', $raw) === 1) {
            return $raw;
        }

        try {
            $timezone = $this->resolveDisplayTimezone();
            $dateTime = new \DateTimeImmutable($raw, $timezone);
            $now = new \DateTimeImmutable('now', $timezone);
            $diff = $now->getTimestamp() - $dateTime->getTimestamp();

            if ($diff >= 0) {
                return $this->formatDurationSeconds($diff);
            }
        } catch (\Throwable $e) {
            // Ignore and return the raw value below.
        }

        return $raw;
    }

    protected function resolveUserOnlineDuration(array $user): ?string
    {
        $durationFields = [
            'online_duration',
            'online_time',
            'time_on',
            'session_time',
            'timeonline',
            'uptime',
        ];

        foreach ($durationFields as $field) {
            $duration = $this->formatDurationValue($user[$field] ?? null);
            if ($duration !== null) {
                return $duration;
            }
        }

        if (empty($user['online'])) {
            return null;
        }

        return $this->formatDurationValue($user['last_connection'] ?? null);
    }

    protected function resolveOnuOnlineDuration(array $onu): ?string
    {
        $duration = $this->formatDurationValue($onu['online_duration'] ?? null);
        if ($duration !== null) {
            return $duration;
        }

        $status = strtolower(trim((string)($onu['status'] ?? '')));
        $isOnline = strpos($status, 'up') !== false
            || strpos($status, 'online') !== false
            || strpos($status, 'active') !== false;

        if (!$isOnline) {
            return null;
        }

        return $this->formatDurationValue($onu['last_reg'] ?? null);
    }

    protected function formatPhoneInternational($value): string
    {
        $raw = trim((string)($value ?? ''));

        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if (!is_string($digits) || $digits === '') {
            return $raw;
        }

        $defaultCountryCode = preg_replace('/\D+/', '', (string)config('telebot.bots.bot.default_phone_country_code', ''));

        if (strpos($raw, '+') === 0) {
            return '+' . $digits;
        }

        if (strpos($digits, '00') === 0 && strlen($digits) > 2) {
            return '+' . substr($digits, 2);
        }

        // Number already looks like E.164 without '+': just prepend plus.
        if (strlen($digits) >= 11 && strlen($digits) <= 15 && strpos($digits, '0') !== 0) {
            return '+' . $digits;
        }

        // Use configured default country code for local/national formats.
        if ($defaultCountryCode !== '') {
            $national = preg_replace('/^0+/', '', $digits);

            if (!is_string($national) || $national === '') {
                return '+' . $defaultCountryCode;
            }

            return '+' . $defaultCountryCode . $national;
        }

        // Ambiguous local number without country code.
        return $raw;
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
