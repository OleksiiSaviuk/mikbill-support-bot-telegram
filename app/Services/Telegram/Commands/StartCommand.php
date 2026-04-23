<?php


namespace App\Services\Telegram\Commands;


use WeStacks\TeleBot\Handlers\CommandHandler;

class StartCommand extends Command
{
    protected static $aliases = ['/start', '/s'];
    protected static $description = 'Send "/start" or "/s" to get "Hello, World!"';

    public function handle()
    {
        $chat_id = $this->update->message->from->id;

        if (isset($this->update->message->chat->last_name, $this->update->message->chat->first_name)) {
            $text = "<b>" . trans("hello") . ",  " . $this->update->message->chat->last_name . " " . $this->update->message->chat->first_name . " ! </b> 👋 \n\n";
        } else {
            $text = "<b>" . trans("hello") . "! </b> 👋 \n\n";
        }
        $text .= trans("your_id") . " " . $chat_id . "\n";

        $this->sendMessage([
            'text'       => $text,
            'parse_mode' => 'HTML'
        ]);

        if ($this->isAuth()) {

            $text = trans("auth_success");
            $this->sendMessage([
                'text'       => $text,
                'parse_mode' => 'HTML'
            ]);

            $phone = $this->getPhoneFromStartPayload();

            if (config('telebot.bots.bot.enable_start_phone_search', false) && !empty($phone)) {
                $userId = $this->update->message->from->id;
                $lockKey = $userId . '_start_phone_lock';
                
                if (!\Illuminate\Support\Facades\Cache::get($lockKey)) {
                    \Illuminate\Support\Facades\Cache::put($lockKey, true, now()->addSeconds(2));
                    $this->setLastAction('menuSearchByPhone');
                    $this->performUserSearch('phone', $phone);
                }

                return;
            }

            $this->setLastAction('menuMain');

            $this->sendMessage([
                'text'         => "<b>" . trans("main_menu") . "</b>",
                'parse_mode'   => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                "text"          => trans("menu_search"),
                                "callback_data" => "menuSearch"
                            ],
                            [
                                "text"          => trans("menu_locale"),
                                "callback_data" => "menuLocale"
                            ]
                        ]
                    ]
                ]
            ]);

        } else {

            $text = trans("auth_error");
            $this->sendMessage([
                'text'       => $text,
                'parse_mode' => 'HTML'
            ]);
        }
    }

    private function getPhoneFromStartPayload(): ?string
    {
        $text = trim($this->update->message->text ?? '');

        if ($text === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $text, 2);

        if (count($parts) < 2 || strpos($parts[1], 'phone_') !== 0) {
            return null;
        }

        $phone = preg_replace('/\D+/', '', substr($parts[1], 6));

        return $phone !== '' ? $phone : null;
    }
}
