<?php

namespace App\Services\Telegram\Commands;

use App\Services\MikBill\Admin\API;
use Illuminate\Support\Facades\Cache;
use WeStacks\TeleBot\Interfaces\UpdateHandler;
use WeStacks\TeleBot\Objects\Update;
use WeStacks\TeleBot\TeleBot;

class InputCommand extends Command
{
    public static function trigger(Update $update, TeleBot $bot)
    {
        return isset($update->message->text);
    }

    public function handle()
    {
        $update = $this->update;
        $bot = $this->bot;

        if ($this->getLastAction() == 'menuSearch') {
            $this->menuSearchUser('all');
        }

        if ($this->getLastAction() == 'menuSearchByLogin') {
            $this->menuSearchUser('login');
        }

        if ($this->getLastAction() == 'menuSearchByUID') {
            $this->menuSearchUser('uid');
        }

        if ($this->getLastAction() == 'menuSearchByDogovor') {
            $this->menuSearchUser('numdogovor');
        }

        if ($this->getLastAction() == 'menuSearchByPhone') {
            $this->menuSearchUser('phone');
        }

    }

    private function menuSearchUser($type = 'login')
    {
        $cabinet_host = config('services.mikbill.cabinet_host');

        // Ищем абона
        $api = new API();
        $users = $api->searchUsersMB($this->update->message->text, $type);

        if (!empty($users)) {
            $this->storeSearchState($type, $this->update->message->text, $users);
            $this->sendSearchResultPage(1);

        } else {
            $text = trans("user_not_found");

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

    private function sendSearchResultPage(int $page = 1)
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

            switch ($user['state']) {
                case 1:
                    $status = trans("state_1");
                    break;
                case 2:
                    $status = trans("state_2");
                    break;
                case 3:
                    $status = trans("state_3");
                    break;
                case 4:
                    $status = trans("state_4");
                    break;
                default:
                    $status = trans("state_1");
            }

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

    private function storeSearchState(string $type, string $query, array $users): void
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

    private function getSearchState(): array
    {
        $state = Cache::get($this->getSearchStateKey(), []);

        if (!is_array($state)) {
            return [];
        }

        return $state;
    }

    private function getSearchStateKey(): string
    {
        return ($this->update->message->from->id ?? 'guest') . '_search_state';
    }
}
