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
        $replyState = $this->getTicketReplyState();

        if (is_array($replyState) && ($replyState['state'] ?? '') === 'waiting_ticket_reply') {
            $this->handleTicketReplyInput($replyState);
            return;
        }

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

    private function handleTicketReplyInput(array $state): void
    {
        $ticketId = (int)($state['ticketid'] ?? 0);
        $text = trim((string)($this->update->message->text ?? ''));

        if ($ticketId <= 0) {
            $this->clearTicketReplyState();
            $this->clearPendingTicketReply();
            $this->sendMessage([
                'text' => trans('tickets_reply_not_ready'),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        if ($text === '') {
            $this->sendMessage([
                'text' => trans('tickets_reply_empty_error'),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $maxLength = $this->getTicketsMaxMessageLength();
        if (mb_strlen($text) > $maxLength) {
            $this->sendMessage([
                'text' => trans('tickets_reply_too_long', ['limit' => $maxLength]),
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $this->setPendingTicketReply($ticketId, $text);
        $this->clearTicketReplyState();

        $previewText = trans('tickets_reply_preview_title', ['ticket' => $ticketId]);
        $previewText .= "\n\n" . $this->escapeHtml($text);

        $this->sendMessage([
            'text' => $previewText,
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => trans('tickets_reply_send_button'),
                            'callback_data' => 'tickets:reply_confirm:' . $ticketId,
                        ],
                        [
                            'text' => trans('tickets_reply_edit_button'),
                            'callback_data' => 'tickets:reply:' . $ticketId,
                        ],
                        [
                            'text' => trans('tickets_reply_cancel_button'),
                            'callback_data' => 'tickets:reply_cancel:' . $ticketId,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
