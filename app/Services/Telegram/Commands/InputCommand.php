<?php

namespace App\Services\Telegram\Commands;

use WeStacks\TeleBot\Interfaces\UpdateHandler;
use WeStacks\TeleBot\Objects\Update;
use WeStacks\TeleBot\TeleBot;

class InputCommand extends Command
{
    public static function trigger(Update $update, TeleBot $bot)
    {
        return isset($update->message->text) && strpos($update->message->text, '/') !== 0;
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
        $this->performUserSearch($type, $this->update->message->text);
    }
}
