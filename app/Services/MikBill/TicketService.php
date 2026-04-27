<?php

namespace App\Services\MikBill;

use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

class TicketService
{
    private ?PDO $pdo = null;

    private function getPdo(): ?PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = trim((string)config('services.mikbill.tickets_db_host'));
        $port = (int)config('services.mikbill.tickets_db_port', 3306);
        $database = trim((string)config('services.mikbill.tickets_db_name'));
        $username = trim((string)config('services.mikbill.tickets_db_user'));
        $password = (string)config('services.mikbill.tickets_db_password');

        if ($host === '' || $database === '' || $username === '') {
            return null;
        }

        try {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('Failed to connect to MikBill tickets database', [
                'message' => $e->getMessage(),
                'host' => $host,
                'port' => $port,
                'database' => $database,
            ]);

            return null;
        }

        return $this->pdo;
    }

    public function getLatestTickets(int $limit): array
    {
        $pdo = $this->getPdo();

        if (!$pdo) {
            return [];
        }

        $validatedLimit = max(1, min(100, (int)$limit));

        $sql = "
            SELECT
              t.ticketid,
              t.creationdate,
              t.inworkdate,
              t.performingdate,
              t.closingdate,
              t.useruid,
              t.fio,
              t.phones,
              t.street,
              t.house,
              t.apartment,
              t.categoryid,
              c.categoryname,
              t.prioritytypeid,
              p.prioritytypename,
              t.statustypeid,
              s.statustypename,
              COUNT(m.messageid) AS messages_total,
              SUM(CASE WHEN m.stuffid = 0 THEN 1 ELSE 0 END) AS client_messages_total,
              SUM(CASE WHEN m.stuffid = 0 AND m.unread = 1 THEN 1 ELSE 0 END) AS new_messages_total,
              MAX(m.date) AS last_message_date
            FROM tickets_tickets t
            LEFT JOIN tickets_categories_list c ON c.categoryid = t.categoryid
            LEFT JOIN tickets_priorities_types p ON p.prioritytypeid = t.prioritytypeid
            LEFT JOIN tickets_status_types s ON s.statustypeid = t.statustypeid
            LEFT JOIN tickets_messages m ON m.ticketid = t.ticketid
            WHERE t.statustypeid <> 2
               OR NOT EXISTS (
                    SELECT 1
                    FROM tickets_tickets active_tickets
                    WHERE active_tickets.statustypeid <> 2
                )
            GROUP BY
              t.ticketid,
              t.creationdate,
              t.inworkdate,
              t.performingdate,
              t.closingdate,
              t.useruid,
              t.fio,
              t.phones,
              t.street,
              t.house,
              t.apartment,
              t.categoryid,
              c.categoryname,
              t.prioritytypeid,
              p.prioritytypename,
              t.statustypeid,
              s.statustypename
                        ORDER BY
                            CASE t.statustypeid
                                WHEN 1 THEN 0
                                WHEN 3 THEN 1
                                WHEN 4 THEN 2
                                WHEN 2 THEN 3
                                ELSE 4
                            END ASC,
                            new_messages_total DESC,
                            COALESCE(MAX(m.date), t.creationdate) DESC
            LIMIT {$validatedLimit}
        ";

        try {
            return $pdo->query($sql)->fetchAll() ?: [];
        } catch (Throwable $e) {
            Log::warning('Failed to fetch latest MikBill tickets', [
                'message' => $e->getMessage(),
                'limit' => $validatedLimit,
            ]);

            return [];
        }
    }

    public function getTicket(int $ticketId): ?object
    {
        $pdo = $this->getPdo();

        if (!$pdo) {
            return null;
        }

        $sql = "
            SELECT
              t.ticketid,
              t.creationdate,
              t.inworkdate,
              t.performingdate,
              t.closingdate,
              t.useruid,
              t.fio,
              t.phones,
              t.street,
              t.house,
              t.apartment,
              t.created_by_stuffid,
              t.created_by_useruid,
              t.categoryid,
              c.categoryname,
              t.prioritytypeid,
              p.prioritytypename,
              t.statustypeid,
              s.statustypename,
              t.show_ticket_to_user,
              t.dialogue
            FROM tickets_tickets t
            LEFT JOIN tickets_categories_list c ON c.categoryid = t.categoryid
            LEFT JOIN tickets_priorities_types p ON p.prioritytypeid = t.prioritytypeid
            LEFT JOIN tickets_status_types s ON s.statustypeid = t.statustypeid
            WHERE t.ticketid = :ticketid
            LIMIT 1
        ";

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute(['ticketid' => $ticketId]);
            $ticket = $statement->fetch();

            return $ticket ?: null;
        } catch (Throwable $e) {
            Log::warning('Failed to fetch MikBill ticket', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticketId,
            ]);

            return null;
        }
    }

    public function getMessages(int $ticketId): array
    {
        $pdo = $this->getPdo();

        if (!$pdo) {
            return [];
        }

        $sql = "
            SELECT
              messageid,
              date,
              ticketid,
              stuffid,
              useruid,
              message,
              unread
            FROM tickets_messages
            WHERE ticketid = :ticketid
            ORDER BY messageid ASC
        ";

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute(['ticketid' => $ticketId]);

            return $statement->fetchAll() ?: [];
        } catch (Throwable $e) {
            Log::warning('Failed to fetch MikBill ticket messages', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticketId,
            ]);

            return [];
        }
    }

    public function ticketExists(int $ticketId): bool
    {
        $pdo = $this->getPdo();

        if (!$pdo) {
            return false;
        }

        $sql = "
            SELECT 1
            FROM tickets_tickets
            WHERE ticketid = :ticketid
            LIMIT 1
        ";

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute(['ticketid' => $ticketId]);

            return (bool)$statement->fetchColumn();
        } catch (Throwable $e) {
            Log::warning('Failed to check MikBill ticket existence', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticketId,
            ]);

            return false;
        }
    }

    public function addReply(int $ticketId, int $operatorId, string $message): bool
    {
        $pdo = $this->getPdo();

        if (!$pdo) {
            return false;
        }

        $insertSql = "
            INSERT INTO tickets_messages
            (ticketid, stuffid, useruid, message, unread)
            VALUES
            (:ticketid, :operator_id, 0, :message, 1)
        ";

        $updateSql = "
            UPDATE tickets_tickets
            SET inworkdate = NOW()
            WHERE ticketid = :ticketid
        ";

        try {
            $pdo->beginTransaction();

            $insert = $pdo->prepare($insertSql);
            $insert->execute([
                'ticketid' => $ticketId,
                'operator_id' => $operatorId,
                'message' => $message,
            ]);

            $update = $pdo->prepare($updateSql);
            $update->execute(['ticketid' => $ticketId]);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Log::warning('Failed to add MikBill ticket reply', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticketId,
                'operator_id' => $operatorId,
            ]);

            return false;
        }
    }

    public function changeStatus(int $ticketId, int $statusId, int $operatorId): bool
    {
        $pdo = $this->getPdo();

        if (!$pdo) {
            return false;
        }

        $queries = [
            1 => "
                UPDATE tickets_tickets
                SET statustypeid = 1
                WHERE ticketid = :ticketid
            ",
            3 => "
                UPDATE tickets_tickets
                SET
                  statustypeid = 3,
                  inworkdate = NOW(),
                  inwork_by_stuffid = :operator_id
                WHERE ticketid = :ticketid
            ",
            4 => "
                UPDATE tickets_tickets
                SET
                  statustypeid = 4,
                  performingdate = NOW(),
                  performed_by_stuffid = :operator_id
                WHERE ticketid = :ticketid
            ",
            2 => "
                UPDATE tickets_tickets
                SET
                  statustypeid = 2,
                  closingdate = NOW(),
                  closed_by_stuffid = :operator_id
                WHERE ticketid = :ticketid
            ",
        ];

        if (!isset($queries[$statusId])) {
            return false;
        }

        try {
            $statement = $pdo->prepare($queries[$statusId]);

            $params = ['ticketid' => $ticketId];
            if ($statusId !== 1) {
                $params['operator_id'] = $operatorId;
            }

            return $statement->execute($params);
        } catch (Throwable $e) {
            Log::warning('Failed to change MikBill ticket status', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticketId,
                'status_id' => $statusId,
                'operator_id' => $operatorId,
            ]);

            return false;
        }
    }

    public function getTicketCounters(int $ticketId): object
    {
        $result = (object)[
            'messages_total' => 0,
            'client_messages_total' => 0,
            'new_messages_total' => 0,
        ];

        $pdo = $this->getPdo();

        if (!$pdo) {
            return $result;
        }

        $sql = "
            SELECT
              COUNT(*) AS messages_total,
              SUM(CASE WHEN stuffid = 0 THEN 1 ELSE 0 END) AS client_messages_total,
                            SUM(CASE WHEN stuffid = 0 AND unread = 1 THEN 1 ELSE 0 END) AS new_messages_total
            FROM tickets_messages
            WHERE ticketid = :ticketid
        ";

        try {
            $statement = $pdo->prepare($sql);
                        $statement->execute(['ticketid' => $ticketId]);
            $row = $statement->fetch();

            if (!$row) {
                return $result;
            }

            $result->messages_total = (int)($row->messages_total ?? 0);
            $result->client_messages_total = (int)($row->client_messages_total ?? 0);
            $result->new_messages_total = (int)($row->new_messages_total ?? 0);

            return $result;
        } catch (Throwable $e) {
            Log::warning('Failed to fetch MikBill ticket counters', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticketId,
            ]);

            return $result;
        }
    }

    public function markClientMessagesAsRead(int $ticketId): bool
    {
        $pdo = $this->getPdo();

        if (!$pdo) {
            return false;
        }

        $sql = "
            UPDATE tickets_messages
                        SET unread = 0
            WHERE ticketid = :ticketid
              AND stuffid = 0
                            AND unread = 1
        ";

        try {
            $statement = $pdo->prepare($sql);

            return $statement->execute(['ticketid' => $ticketId]);
        } catch (Throwable $e) {
            Log::warning('Failed to mark client ticket messages as read', [
                'message' => $e->getMessage(),
                'ticket_id' => $ticketId,
            ]);

            return false;
        }
    }
}
