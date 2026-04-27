<?php


namespace App\Services\Telegram\Commands;

use App\Helpers\Helpers;
use App\Services\MikBill\Admin\API;
use App\Services\MikBill\TicketService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use WeStacks\TeleBot\Objects\Update;
use WeStacks\TeleBot\TeleBot;

class CallBackCommand extends Command
{

    /**
     * Обрабатываем callback_query
     *
     * @param Update $update
     * @param TeleBot $bot
     * @return bool
     */
    public static function trigger(Update $update, TeleBot $bot)
    {
        return isset($update->callback_query);
    }

    public function handle()
    {
        $callbackData = (string)($this->update->callback_query->data ?? '');

        if (strpos($callbackData, 'tickets:') === 0) {
            $this->handleTicketCallback($callbackData);
            return;
        }

        if (strpos($callbackData, 'refresh_wc:') === 0 || strpos($callbackData, 'refresh_onu:') === 0) {
            $this->refreshOnu($callbackData);
            return;
        }

        $params = explode("_", $callbackData);

        if (isset($params[0]) and method_exists(self::class, $params[0])) {
            $method = $params[0];
            $this->$method($params); // dispatch the method
        } else {

            $this->sendMessage([
                'text'       => trans("menu_not_work"),
                'parse_mode' => 'HTML'
            ]);
        }
    }

    private function refreshOnu(string $callbackData): void
    {
        $parts = explode(':', $callbackData, 3);

        if (count($parts) < 3) {
            $this->answerCallback(trans('wildcore_refresh_error'));
            return;
        }

        $interfaceId = trim((string)$parts[1]);
        $clientMac = trim((string)$parts[2]);

        if ($interfaceId === '' || $clientMac === '') {
            $this->answerCallback(trans('wildcore_refresh_error'));
            return;
        }

        $throttleKey = 'onu_refresh_' . $interfaceId;

        if (Cache::has($throttleKey)) {
            $this->answerCallback(trans('wildcore_refresh_throttled'));
            return;
        }

        Cache::put($throttleKey, 1, now()->addSeconds(30));

        $onu = $this->getOnuByInterfaceAndClientMac($interfaceId, $clientMac, 'device');

        if (empty($onu)) {
            $this->answerCallback(trans('wildcore_refresh_error'));
            return;
        }

        $onuBlock = $this->buildOnuMessageBlock($onu);

        if ($onuBlock === '') {
            $this->answerCallback(trans('wildcore_refresh_error'));
            return;
        }

        $message = $this->update->callback_query->message ?? null;
        $chatId = isset($message->chat->id) ? (int)$message->chat->id : 0;
        $messageId = isset($message->message_id) ? (int)$message->message_id : 0;
        $currentText = isset($message->text) ? (string)$message->text : '';

        if ($chatId <= 0 || $messageId <= 0 || $currentText === '') {
            $this->answerCallback(trans('wildcore_refresh_error'));
            return;
        }

        $updatedText = null;
        $replyMarkup = null;
        $userUid = $this->extractUserUidFromMessageText($currentText);

        if ($userUid !== null) {
            $api = new API();
            $user = $api->getUserMB($userUid);

            if (!empty($user)) {
                $systemOptions = $api->getSystemOptions();
                $updatedText = $this->buildUserCardMessage($user, $onu, $systemOptions);
                $replyMarkup = [
                    'inline_keyboard' => $this->buildUserCardInlineKeyboard($user, $onu),
                ];
            }
        }

        if ($updatedText === null) {
            $updatedText = $this->replaceOnuBlock($currentText, $onuBlock);
        }

        if ($replyMarkup === null && isset($message->reply_markup)) {
            $replyMarkup = json_decode(json_encode($message->reply_markup), true);
        }

        try {
            $editPayload = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $updatedText,
                'parse_mode' => 'HTML',
            ];

            if (is_array($replyMarkup) && !empty($replyMarkup)) {
                $editPayload['reply_markup'] = $replyMarkup;
            }

            $this->bot->editMessageText($editPayload);

            $this->answerCallback(trans('wildcore_refresh_success'));
        } catch (\Throwable $e) {
            Log::warning('Failed to edit Telegram message during ONU refresh', [
                'message' => $e->getMessage(),
                'interface_id' => $interfaceId,
            ]);

            $this->answerCallback(trans('wildcore_refresh_error'));
        }
    }

    private function answerCallback(string $text): void
    {
        try {
            $this->bot->answerCallbackQuery([
                'callback_query_id' => $this->update->callback_query->id,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to answer callback query', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function menuSearch($param)
    {
        $this->setLastAction('menuSearch');

        $text = "<b>" . trans("choice_search_field") . "</b>";

        $this->sendMessage([
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text'          => trans("menu_search_by_uid"),
                            'callback_data' => "menuSearchByUID"
                        ],
                        [
                            "text"          => trans("menu_search_by_login"),
                            "callback_data" => "menuSearchByLogin"
                        ]
                    ],
                    [
                        [
                            'text'          => trans("menu_search_by_contract"),
                            'callback_data' => "menuSearchByDogovor"
                        ],
                        [
                            'text'          => trans("menu_search_by_phone"),
                            'callback_data' => "menuSearchByPhone"
                        ]
                    ]
                ]
            ]
        ]);
    }

    private function menuSearchByUID($param)
    {
        $this->setLastAction('menuSearchByUID');

        $text = "<b>" . trans("enter_uid") . "</b>";
        $this->sendMessage([
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    private function menuSearchByDogovor($param)
    {
        $this->setLastAction('menuSearchByDogovor');

        $text = "<b>" . trans("enter_contract") . "</b>";
        $this->sendMessage([
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    private function menuSearchByLogin($param)
    {
        $this->setLastAction('menuSearchByLogin');

        $text = "<b>" . trans("enter_login") . "</b>";
        $this->sendMessage([
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    private function menuSearchByPhone($param)
    {
        $this->setLastAction('menuSearchByPhone');

        $text = "<b>" . trans("enter_phone") . "</b>";
        $this->sendMessage([
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    private function menuSearchPage($param)
    {
        $page = isset($param[1]) ? (int)$param[1] : 1;
        $this->sendSearchResultPage($page);
    }

    private function menuHistorySessions($param)
    {
        $this->setLastAction('menuHistorySessions');

        if (isset($param[1])) {
            $api = new API();
            $result = $api->getUserShortHistory([
                "uid" => $param[1]
            ]);

            $history = isset($result['data'][0]['stattraf']) ? $result['data'][0]['stattraf'] : [];

            $text = trans('history_sessions_title') . "\n\n";
            $text .= "<pre> " . Helpers::str_pad_unicode(trans('history_table_start_time'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_stop_time'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_time_on'), 15) . "</pre>\n";
            $text .= "<pre> " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 15, '-') . "</pre>\n";
            foreach ($history as $row) {
                $text .= "<pre> " . Helpers::str_pad_unicode($row['start_time'], 20) . " | " . Helpers::str_pad_unicode($row['stop_time'], 20) . " | " . Helpers::str_pad_unicode($row['time_on'], 15) . "</pre>\n";
            }

            $this->sendMessage([
                'text'         => $text,
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
        }

    }

    private function menuServices($param)
    {
        $this->setLastAction('menuServices');

        if (isset($param[1])) {
            $api = new API();
            $user = $api->getUserMB($param[1]);

            $services = $user['services'];

            $text = "<b>" . trans("services_user") . "</b> \n\n";

            if (!empty($services['active'])) {
                $text .= trans("services_active") . " \n";
                foreach ($services['active'] as $row) {
                    $text .= $row['serviceid'] . " " . $row['servicename'] . " \n";
                }
                $text .= "\n";
            }

            if (!empty($services['basic'])) {
                $text .= trans("services_basic") . " \n";
                foreach ($services['basic'] as $row) {
                    $text .= $row['serviceid'] . " " . $row['servicename'] . " \n";
                }
                $text .= "\n";
            }

            if (!empty($services['personal'])) {
                $text .= trans("services_individual") . " \n";
                foreach ($services['personal'] as $row) {
                    $text .= $row['serviceid'] . " " . $row['servicename'] . " \n";
                }
                $text .= "\n";
            }

            if (empty($services['active']) and empty($services['basic']) and empty($services['personal'])) {
                $text .= trans("services_not_found");
            }

            $this->sendMessage([
                'text'         => $text,
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
        }
    }

    private function menuHistoryPayments($param)
    {
        $this->setLastAction('menuHistoryPayments');

        if (isset($param[1])) {
            $api = new API();
            $result = $api->getUserShortHistory([
                "uid" => $param[1]
            ]);

            $history = isset($result['data'][0]['statpay']) ? $result['data'][0]['statpay'] : [];

            $text = trans("history_payment") . " \n\n";
            $text .= "<pre> " . Helpers::str_pad_unicode(trans('history_table_date'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_amount'), 10) . " | " . Helpers::str_pad_unicode(trans('history_table_type'), 40) . " </pre>\n";
            $text .= "<pre> " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 10, '-') . " + " . Helpers::str_pad_unicode('-', 40, '-') . " </pre>\n";
            foreach ($history as $row) {
                $text .= "<pre> " . Helpers::str_pad_unicode($row['date'], 20) . " | " . Helpers::str_pad_unicode($this->formatMoney($row['summa']), 10) . " | " . Helpers::str_pad_unicode($row['bughtypeid'], 40) . " </pre>\n";
            }

            $this->sendMessage([
                'text'         => $text,
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
        }

    }

    private function menuHistoryTickets($param)
    {
        $this->setLastAction('menuHistoryTickets');

        if (isset($param[1])) {
            $api = new API();
            $result = $api->getUserShortHistory([
                "uid" => $param[1]
            ]);

            $history = isset($result['data'][0]['tickets']) ? $result['data'][0]['tickets'] : [];

            $text = trans("history_tickets") . " \n\n";
            $text .= "<pre> " . Helpers::str_pad_unicode(trans('history_table_date_create'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_category'), 25) . " | " . Helpers::str_pad_unicode(trans('history_table_status'), 40) . " </pre>\n";
            $text .= "<pre> " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 25, '-') . " + " . Helpers::str_pad_unicode('-', 40, '-') . " </pre>\n";
            foreach ($history as $row) {
                $text .= "<pre> " . Helpers::str_pad_unicode($row['creationdate'], 20) . " | " . Helpers::str_pad_unicode($row['categoryname'], 25) . " | " . Helpers::str_pad_unicode($row['statustypename'], 40) . " </pre>\n";
            }

            $this->sendMessage([
                'text'         => $text,
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
        }

    }

    private function menuHistoryAuths($param)
    {
        $this->setLastAction('menuHistoryAuths');

        if (isset($param[1])) {

            $api = new API();
            $result = $api->getUserShortHistory([
                "uid" => $param[1]
            ]);

            $history = isset($result['data'][0]['postauth']) ? $result['data'][0]['postauth'] : [];

            $text = trans("history_auths") . " \n\n";
            $text .= "<pre> " . Helpers::str_pad_unicode(trans('history_table_date_auth'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_calling_station_id'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_message'), 40) . " </pre>\n";
            $text .= "<pre> " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 40, '-') . " </pre>\n";
            foreach ($history as $row) {
                $text .= "<pre> " . Helpers::str_pad_unicode($row['authdate'], 20) . " | " . Helpers::str_pad_unicode($row['callingstationid'], 20) . " | " . Helpers::str_pad_unicode($row['replymessage'], 40) . " </pre>\n";
            }

            $this->sendMessage([
                'text'         => $text,
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
        }

    }

    private function menuHistoryLogs($param)
    {
        $this->setLastAction('menuHistoryLogs');

        if (isset($param[1])) {

            $api = new API();
            $result = $api->getUserShortHistory([
                "uid" => $param[1]
            ]);

            $history = isset($result['data'][0]['logs']) ? $result['data'][0]['logs'] : [];

            $text = trans("history_logs") . " \n\n";
            $text .= "<pre> " . Helpers::str_pad_unicode(trans('history_table_date'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_value'), 40) . " | " . Helpers::str_pad_unicode(trans('history_table_old'), 20) . " | " . Helpers::str_pad_unicode(trans('history_table_new'), 20) . " </pre>\n";
            $text .= "<pre> " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 40, '-') . " + " . Helpers::str_pad_unicode('-', 20, '-') . " + " . Helpers::str_pad_unicode('-', 20, '-') . " </pre>\n";
            foreach ($history as $row) {
                $text .= "<pre> " . Helpers::str_pad_unicode($row['date'], 20) . " | " . Helpers::str_pad_unicode($row['valuename'], 40) . " | " . Helpers::str_pad_unicode($row['oldvalue'], 20) . " | " . Helpers::str_pad_unicode($row['newvalue'], 20) . " </pre>\n";
            }

            $this->sendMessage([
                'text'         => $text,
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
        }

    }


    private function menuUserKick($param)
    {
        $this->setLastAction('menuUserKick');

        if (isset($param[1])) {
            $api = new API();
            $result = $api->userKickOnline([
                "uid" => $param[1]
            ]);

            $text = trans("command_sended") . " \n\n";

            $this->sendMessage([
                'text'         => $text,
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
        }

    }

    private function handleTicketCallback(string $callbackData): void
    {
        if (!$this->canAccessTickets()) {
            $this->answerCallback(trans('tickets_access_denied'));
            return;
        }

        $parts = explode(':', $callbackData);
        $action = $parts[1] ?? '';

        switch ($action) {
            case 'list':
            case 'back':
                $this->sendTicketsList();
                return;

            case 'open':
            case 'refresh':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                if ($ticketId > 0) {
                    $this->sendTicketView($ticketId);
                    return;
                }
                break;

            case 'reply':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                if ($ticketId > 0) {
                    $this->startTicketReply($ticketId);
                    return;
                }
                break;

            case 'reply_confirm':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                if ($ticketId > 0) {
                    $this->confirmTicketReply($ticketId);
                    return;
                }
                break;

            case 'reply_cancel':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                $this->cancelTicketReply($ticketId);
                return;

            case 'status':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                $statusKey = (string)($parts[3] ?? '');

                if ($ticketId > 0 && $statusKey !== '') {
                    $this->requestTicketStatusChangeConfirm($ticketId, $statusKey);
                    return;
                }
                break;

            case 'status_next':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                $statusKey = (string)($parts[3] ?? '');

                if ($ticketId > 0 && $statusKey !== '') {
                    $this->requestTicketStatusChangeConfirm($ticketId, $statusKey);
                    return;
                }
                break;

            case 'status_apply':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                $statusKey = (string)($parts[3] ?? '');

                if ($ticketId > 0 && $statusKey !== '') {
                    $this->changeTicketStatus($ticketId, $statusKey);
                    return;
                }
                break;

            case 'status_cancel':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;

                if ($ticketId > 0) {
                    $this->sendTicketView($ticketId);
                    return;
                }
                break;

            case 'subscriber_info':
                $ticketId = isset($parts[2]) ? (int)$parts[2] : 0;
                if ($ticketId > 0) {
                    $this->showTicketSubscriberInfo($ticketId);
                    return;
                }
                break;
        }

        $this->answerCallback(trans('menu_not_work'));
    }

    private function sendTicketsList(): void
    {
        $this->setLastAction('tickets:list');
        $this->clearTicketReplyState();
        $this->clearPendingTicketReply();

        $service = new TicketService();
        $limit = $this->getTicketsListLimit();
        $tickets = $service->getLatestTickets($limit);

        $title = trans('tickets_latest_title', ['count' => $limit]);
        $text = "🎫 " . $this->escapeHtml($title) . "\n\n";

        if (empty($tickets)) {
            $text .= trans('tickets_not_found');

            $this->sendMessage([
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => trans('tickets_refresh'),
                                'callback_data' => 'tickets:list',
                            ],
                            [
                                'text' => trans('menu_main'),
                                'callback_data' => 'menuMain',
                            ],
                        ],
                    ],
                ],
            ]);

            return;
        }

        $ticketButtons = [];

        foreach ($tickets as $ticket) {
            $ticketId = (int)($ticket->ticketid ?? 0);
            if ($ticketId <= 0) {
                continue;
            }

            $status = $this->resolveTicketStatusLabel((int)($ticket->statustypeid ?? 0), (string)($ticket->statustypename ?? ''));
            $priority = $this->resolveTicketPriorityLabel((int)($ticket->prioritytypeid ?? 0), (string)($ticket->prioritytypename ?? ''));
            $category = $this->resolveTicketCategoryLabel((int)($ticket->categoryid ?? 0), (string)($ticket->categoryname ?? ''));
            $uid = (int)($ticket->useruid ?? 0);
            $messagesTotal = (int)($ticket->messages_total ?? 0);
            $newMessagesTotal = (int)($ticket->new_messages_total ?? 0);
            $date = $this->formatTicketDate($ticket->last_message_date ?? $ticket->creationdate ?? null, 'd.m.Y H:i');

            $text .= '#' . $ticketId . ' | ' . $status . ' | ' . $priority . "\n";
            $text .= $category . "\n";
            $text .= 'UID: ' . $uid . "\n";
            $text .= trans('tickets_messages_counters', [
                'total' => $messagesTotal,
                'new' => $newMessagesTotal,
            ]) . "\n";
            $text .= '🕒 ' . $this->escapeHtml($date) . "\n\n";

            $ticketButtons[] = [
                'text' => '#' . $ticketId,
                'callback_data' => 'tickets:open:' . $ticketId,
            ];
        }

        $keyboard = [];
        $chunkedButtons = array_chunk($ticketButtons, 3);

        foreach ($chunkedButtons as $row) {
            $keyboard[] = $row;
        }

        $keyboard[] = [
            [
                'text' => trans('tickets_refresh'),
                'callback_data' => 'tickets:list',
            ],
            [
                'text' => trans('menu_main'),
                'callback_data' => 'menuMain',
            ],
        ];

        $this->sendMessage([
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => $keyboard,
            ],
        ]);
    }

    private function sendTicketView(int $ticketId): void
    {
        $service = new TicketService();
        $ticket = $service->getTicket($ticketId);

        if (!$ticket) {
            $this->sendMessage([
                'text' => trans('tickets_not_found_single', ['ticket' => $ticketId]),
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => trans('tickets_back_to_list'),
                                'callback_data' => 'tickets:back',
                            ],
                        ],
                    ],
                ],
            ]);
            return;
        }

        $messages = $service->getMessages($ticketId);
        $counters = $service->getTicketCounters($ticketId);

        $header = '';
        $header .= '🎫 ' . trans('tickets_ticket_title', ['ticket' => $ticketId]) . "\n";
        $header .= trans('tickets_label_status') . ': ' . $this->resolveTicketStatusLabel((int)($ticket->statustypeid ?? 0), (string)($ticket->statustypename ?? '')) . "\n";
        $header .= trans('tickets_label_category') . ': ' . $this->resolveTicketCategoryLabel((int)($ticket->categoryid ?? 0), (string)($ticket->categoryname ?? '')) . "\n";
        $header .= trans('tickets_label_priority') . ': ' . $this->resolveTicketPriorityLabel((int)($ticket->prioritytypeid ?? 0), (string)($ticket->prioritytypename ?? '')) . "\n";
        $header .= 'UID: ' . (int)($ticket->useruid ?? 0) . "\n";
        $header .= trans('tickets_label_created') . ': ' . $this->formatTicketDate($ticket->creationdate ?? null, 'd.m.Y H:i') . "\n";
        $header .= trans('tickets_messages_counters', [
            'total' => (int)($counters->messages_total ?? 0),
            'new' => (int)($counters->new_messages_total ?? 0),
        ]) . "\n\n";
        $header .= "💬 " . trans('tickets_history_title') . ":\n";

        $chunks = [];
        $current = $header;
        $chunkLimit = 3600;

        foreach ($messages as $messageRow) {
            $messageText = trim((string)($messageRow->message ?? ''));
            $messageText = $messageText === '' ? trans('tickets_empty_message') : $messageText;
            $messageText = $this->escapeHtml($messageText);

            $author = ((int)($messageRow->stuffid ?? 0) > 0)
                ? trans('tickets_author_operator')
                : trans('tickets_author_client');

            $block = '[' . $this->formatTicketDate($messageRow->date ?? null, 'd.m H:i') . '] ' . $author . ":\n";
            $block .= $messageText . "\n\n";

            if (mb_strlen($current . $block) > $chunkLimit) {
                $chunks[] = $current;
                $current = $block;
            } else {
                $current .= $block;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $keyboard = $this->buildTicketViewKeyboard($ticketId, (int)($ticket->statustypeid ?? 0));

        foreach ($chunks as $index => $chunk) {
            $payload = [
                'text' => trim($chunk),
                'parse_mode' => 'HTML',
            ];

            if ($index === count($chunks) - 1) {
                $payload['reply_markup'] = [
                    'inline_keyboard' => $keyboard,
                ];
            }

            $this->sendMessage($payload);
        }
    }

    private function startTicketReply(int $ticketId): void
    {
        $service = new TicketService();

        if (!$service->ticketExists($ticketId)) {
            $this->sendMessage([
                'text' => trans('tickets_not_found_single', ['ticket' => $ticketId]),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $this->setLastAction('waiting_ticket_reply');
        $this->clearPendingTicketReply();
        $this->setTicketReplyState($ticketId);

        $this->sendMessage([
            'text' => trans('tickets_reply_enter_message', ['ticket' => $ticketId]),
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => trans('tickets_reply_cancel'),
                            'callback_data' => 'tickets:reply_cancel:' . $ticketId,
                        ],
                        [
                            'text' => trans('tickets_back_to_ticket'),
                            'callback_data' => 'tickets:open:' . $ticketId,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function confirmTicketReply(int $ticketId): void
    {
        $pendingReply = $this->getPendingTicketReply();

        if (empty($pendingReply) || (int)($pendingReply['ticketid'] ?? 0) !== $ticketId) {
            $this->sendMessage([
                'text' => trans('tickets_reply_not_ready'),
                'parse_mode' => 'HTML',
            ]);
            $this->sendTicketView($ticketId);
            return;
        }

        $message = trim((string)($pendingReply['message'] ?? ''));

        if ($message === '') {
            $this->sendMessage([
                'text' => trans('tickets_reply_empty_error'),
                'parse_mode' => 'HTML',
            ]);
            $this->startTicketReply($ticketId);
            return;
        }

        if (mb_strlen($message) > $this->getTicketsMaxMessageLength()) {
            $this->sendMessage([
                'text' => trans('tickets_reply_too_long', ['limit' => $this->getTicketsMaxMessageLength()]),
                'parse_mode' => 'HTML',
            ]);
            $this->startTicketReply($ticketId);
            return;
        }

        $service = new TicketService();
        if (!$service->ticketExists($ticketId)) {
            $this->clearTicketReplyState();
            $this->clearPendingTicketReply();
            $this->sendMessage([
                'text' => trans('tickets_not_found_single', ['ticket' => $ticketId]),
                'parse_mode' => 'HTML',
            ]);
            $this->sendTicketsList();
            return;
        }

        $operatorId = $this->getCurrentTicketOperatorId();

        if ($operatorId === null) {
            $this->sendMessage([
                'text' => trans('tickets_access_denied'),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $sent = $service->addReply($ticketId, $operatorId, $message);

        if (!$sent) {
            $this->sendMessage([
                'text' => trans('tickets_reply_send_error'),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $this->clearTicketReplyState();
        $this->clearPendingTicketReply();
        $this->setLastAction('tickets:open');

        $this->sendMessage([
            'text' => trans('tickets_reply_sent', ['ticket' => $ticketId]),
            'parse_mode' => 'HTML',
        ]);

        $this->sendTicketView($ticketId);
    }

    private function cancelTicketReply(int $ticketId = 0): void
    {
        $this->clearTicketReplyState();
        $this->clearPendingTicketReply();

        $this->sendMessage([
            'text' => trans('tickets_reply_cancelled'),
            'parse_mode' => 'HTML',
        ]);

        if ($ticketId > 0) {
            $this->sendTicketView($ticketId);
            return;
        }

        $this->sendTicketsList();
    }

    private function changeTicketStatus(int $ticketId, string $statusKey): void
    {
        $statusMap = [
            'opened' => 1,
            'closed' => 2,
            'in_work' => 3,
            'performed' => 4,
        ];

        if (!isset($statusMap[$statusKey])) {
            $this->answerCallback(trans('tickets_status_update_error'));
            return;
        }

        $operatorId = $this->getCurrentTicketOperatorId();
        if ($operatorId === null) {
            $this->answerCallback(trans('tickets_access_denied'));
            return;
        }

        $service = new TicketService();
        $ticket = $service->getTicket($ticketId);

        if (!$ticket) {
            $this->sendMessage([
                'text' => trans('tickets_not_found_single', ['ticket' => $ticketId]),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $currentStatusId = (int)($ticket->statustypeid ?? 0);
        $expectedNextStatusKey = $this->getNextTicketStatusKey($currentStatusId);

        if ($expectedNextStatusKey === null || $expectedNextStatusKey !== $statusKey) {
            $this->sendMessage([
                'text' => trans('tickets_status_update_error'),
                'parse_mode' => 'HTML',
            ]);
            $this->sendTicketView($ticketId);
            return;
        }

        $ok = $service->changeStatus($ticketId, $statusMap[$statusKey], $operatorId);

        if (!$ok) {
            $this->sendMessage([
                'text' => trans('tickets_status_update_error'),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $this->sendMessage([
            'text' => trans('tickets_status_updated', ['status' => $this->resolveTicketStatusLabel($statusMap[$statusKey], $statusKey)]),
            'parse_mode' => 'HTML',
        ]);

        $this->sendTicketView($ticketId);
    }

    private function requestTicketStatusChangeConfirm(int $ticketId, string $statusKey): void
    {
        $statusMap = [
            'opened' => 1,
            'closed' => 2,
            'in_work' => 3,
            'performed' => 4,
        ];

        if (!isset($statusMap[$statusKey])) {
            $this->answerCallback(trans('tickets_status_update_error'));
            return;
        }

        $service = new TicketService();
        $ticket = $service->getTicket($ticketId);

        if (!$ticket) {
            $this->sendMessage([
                'text' => trans('tickets_not_found_single', ['ticket' => $ticketId]),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $currentStatusId = (int)($ticket->statustypeid ?? 0);
        $expectedNextStatusKey = $this->getNextTicketStatusKey($currentStatusId);

        if ($expectedNextStatusKey === null || $expectedNextStatusKey !== $statusKey) {
            $this->sendMessage([
                'text' => trans('tickets_status_update_error'),
                'parse_mode' => 'HTML',
            ]);
            $this->sendTicketView($ticketId);
            return;
        }

        $statusLabel = $this->resolveTicketStatusLabel($statusMap[$statusKey], $statusKey);

        $this->sendMessage([
            'text' => trans('tickets_status_confirm_title', [
                'ticket' => $ticketId,
                'status' => $statusLabel,
            ]),
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => trans('tickets_status_confirm_yes'),
                            'callback_data' => 'tickets:status_apply:' . $ticketId . ':' . $statusKey,
                        ],
                        [
                            'text' => trans('tickets_status_confirm_no'),
                            'callback_data' => 'tickets:status_cancel:' . $ticketId,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function showTicketSubscriberInfo(int $ticketId): void
    {
        $service = new TicketService();
        $ticket = $service->getTicket($ticketId);

        if (!$ticket) {
            $this->sendMessage([
                'text' => trans('tickets_not_found_single', ['ticket' => $ticketId]),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $uid = (int)($ticket->useruid ?? 0);

        if ($uid <= 0) {
            $this->sendMessage([
                'text' => trans('tickets_subscriber_uid_missing'),
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => trans('tickets_back_to_ticket'),
                                'callback_data' => 'tickets:open:' . $ticketId,
                            ],
                        ],
                    ],
                ],
            ]);
            return;
        }

        if (!$this->sendSubscriberInfoByUid($uid)) {
            $this->sendMessage([
                'text' => trans('user_not_found'),
                'parse_mode' => 'HTML',
            ]);
        }
    }

    private function buildTicketViewKeyboard(int $ticketId, int $currentStatusId): array
    {
        $nextStatusKey = $this->getNextTicketStatusKey($currentStatusId);

        $statusButtonRow = [];

        if ($nextStatusKey !== null) {
            $statusButtonLabelMap = [
                'opened' => trans('tickets_status_opened_button'),
                'in_work' => trans('tickets_status_in_work_button'),
                'performed' => trans('tickets_status_performed_button'),
                'closed' => trans('tickets_status_closed_button'),
            ];

            $statusButtonRow[] = [
                'text' => $statusButtonLabelMap[$nextStatusKey] ?? trans('tickets_status_in_work_button'),
                'callback_data' => 'tickets:status_next:' . $ticketId . ':' . $nextStatusKey,
            ];
        }

        $keyboard = [
            [
                [
                    'text' => trans('tickets_reply_button'),
                    'callback_data' => 'tickets:reply:' . $ticketId,
                ],
            ],
        ];

        if (!empty($statusButtonRow)) {
            $keyboard[] = $statusButtonRow;
        }

        $keyboard[] = [
            [
                'text' => trans('tickets_subscriber_info_button'),
                'callback_data' => 'tickets:subscriber_info:' . $ticketId,
            ],
        ];

        $keyboard[] = [
            [
                'text' => trans('tickets_refresh'),
                'callback_data' => 'tickets:refresh:' . $ticketId,
            ],
            [
                'text' => trans('tickets_back_to_list'),
                'callback_data' => 'tickets:back',
            ],
        ];

        return $keyboard;
    }

    private function getNextTicketStatusKey(int $currentStatusId): ?string
    {
        $flow = [
            1 => 'in_work',
            3 => 'performed',
            4 => 'closed',
        ];

        return $flow[$currentStatusId] ?? null;
    }

    private function resolveTicketStatusLabel(int $statusId, string $statusName = ''): string
    {
        $map = [
            1 => trans('tickets_status_opened'),
            2 => trans('tickets_status_closed'),
            3 => trans('tickets_status_in_work'),
            4 => trans('tickets_status_performed'),
        ];

        if (isset($map[$statusId])) {
            return $map[$statusId];
        }

        $normalized = strtolower(trim($statusName));
        $nameMap = [
            'opened' => trans('tickets_status_opened'),
            'closed' => trans('tickets_status_closed'),
            'in_work' => trans('tickets_status_in_work'),
            'performed' => trans('tickets_status_performed'),
        ];

        if (isset($nameMap[$normalized])) {
            return $nameMap[$normalized];
        }

        return $this->humanizeTicketValue($statusName);
    }

    private function resolveTicketPriorityLabel(int $priorityId, string $priorityName = ''): string
    {
        $map = [
            1 => trans('tickets_priority_high'),
            2 => trans('tickets_priority_normal'),
            3 => trans('tickets_priority_low'),
        ];

        if (isset($map[$priorityId])) {
            return $map[$priorityId];
        }

        $normalized = strtolower(trim($priorityName));
        $nameMap = [
            'high' => trans('tickets_priority_high'),
            'normal' => trans('tickets_priority_normal'),
            'low' => trans('tickets_priority_low'),
        ];

        if (isset($nameMap[$normalized])) {
            return $nameMap[$normalized];
        }

        return $this->humanizeTicketValue($priorityName);
    }

    private function resolveTicketCategoryLabel(int $categoryId, string $categoryName = ''): string
    {
        $map = [
            1 => trans('tickets_category_other'),
            2 => trans('tickets_category_connection'),
            3 => trans('tickets_category_maintenance'),
            4 => trans('tickets_category_created_in_the_cabinet'),
            5 => trans('tickets_category_cable_is_not_connected'),
            6 => trans('tickets_category_ip_address_conflict'),
            7 => trans('tickets_category_internet_does_not_work'),
            8 => trans('tickets_category_pages_not_open'),
            9 => trans('tickets_category_cable_replacement'),
            10 => trans('tickets_category_does_not_work_the_whole_house'),
            11 => trans('tickets_category_does_not_work_the_whole_sector'),
            12 => trans('tickets_category_configuring_the_router'),
        ];

        if (isset($map[$categoryId])) {
            return $map[$categoryId];
        }

        $normalized = strtolower(trim($categoryName));
        $nameMap = [
            'other' => trans('tickets_category_other'),
            'connection' => trans('tickets_category_connection'),
            'maintenance' => trans('tickets_category_maintenance'),
            'created_in_the_cabinet' => trans('tickets_category_created_in_the_cabinet'),
            'cable_is_not_connected' => trans('tickets_category_cable_is_not_connected'),
            'ip_address_conflict' => trans('tickets_category_ip_address_conflict'),
            'internet_does_not_work' => trans('tickets_category_internet_does_not_work'),
            'pages_not_open' => trans('tickets_category_pages_not_open'),
            'cable_replacement' => trans('tickets_category_cable_replacement'),
            'does_not_work_the_whole_house' => trans('tickets_category_does_not_work_the_whole_house'),
            'does_not_work_the_whole_sector' => trans('tickets_category_does_not_work_the_whole_sector'),
            'configuring_the_router' => trans('tickets_category_configuring_the_router'),
        ];

        if (isset($nameMap[$normalized])) {
            return $nameMap[$normalized];
        }

        return $this->humanizeTicketValue($categoryName);
    }

    private function humanizeTicketValue(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '-';
        }

        return ucfirst(str_replace('_', ' ', $trimmed));
    }

    private function formatTicketDate($value, string $format): string
    {
        $raw = trim((string)($value ?? ''));

        if ($raw === '') {
            return '-';
        }

        try {
            $timezone = $this->resolveDisplayTimezone();
            $date = new \DateTimeImmutable($raw, $timezone);

            return $date->setTimezone($timezone)->format($format);
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    private function menuMain()
    {
        $this->setLastAction('menuMain');

        $this->sendMessage([
            'text'         => "<b>" . trans("main_menu") . "</b>",
            'parse_mode'   => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => $this->buildMainMenuInlineKeyboard(),
            ]
        ]);
    }

    private function menuClearHistory($param)
    {
        $currentLocale = $this->getLocale();

        try {
            $this->bot->answerCallbackQuery([
                'callback_query_id' => $this->update->callback_query->id,
            ]);
        } catch (\Throwable $e) {
            // Ignore callback answer errors after cleanup.
        }

        $this->clearUserState();

        if (!empty($currentLocale)) {
            $this->setLocale($currentLocale);
        }

        $chatId = (int)$this->resolveChatId();

        if ($chatId > 0) {
            $this->clearTrackedChatHistory($chatId);
        }

        $fromMessageId = (int)($this->update->callback_query->message->message_id ?? 0);
        $deleteLimit = (int)config('telebot.bots.bot.clear_history_limit', 0);

        if ($deleteLimit > 0) {
            $this->clearChatHistoryFromMessage($fromMessageId, $deleteLimit);
        }

        // Keep bot active after cleanup by showing the start/main menu.
        $this->menuMain();
    }

    private function menuLocale()
    {
        $this->setLastAction('menuLocale');

        $this->sendMessage([
            'text'         => "<b>" . trans("menu_locale") . "</b>",
            'parse_mode'   => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            "text"          => trans("menu_locale_ua"),
                            "callback_data" => "menuSeTLocaleUa"
                        ],
                        [
                            "text"          => trans("menu_locale_ru"),
                            "callback_data" => "menuSeTLocaleRu"
                        ],
                        [
                            "text"          => trans("menu_locale_en"),
                            "callback_data" => "menuSeTLocaleEn"
                        ]
                    ]
                ]
            ]
        ]);
    }

    private function menuSeTLocaleUa()
    {
        $this->setLocale("uk");
        $this->menuMain();
    }

    private function menuSeTLocaleRu()
    {
        $this->setLocale("ru");
        $this->menuMain();
    }

    private function menuSeTLocaleEn()
    {
        $this->setLocale("en");
        $this->menuMain();
    }

    private function menuHelp()
    {
        $this->setLastAction('menuHelp');

        $text = trans("menu_not_work");

        $this->sendMessage([
            'text'       => $text,
            'parse_mode' => 'HTML'
        ]);
    }
}
