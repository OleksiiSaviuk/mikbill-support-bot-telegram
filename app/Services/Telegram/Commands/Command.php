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
