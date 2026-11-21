<?php
class EventAssignmentAccess {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function apply(array $assignment, int $workerId, string $decision): void {
        if ($decision === 'confirmed') {
            $this->grant($assignment, $workerId);
        } else {
            $this->revoke($assignment, $workerId);
        }
    }

    private function grant(array $assignment, int $workerId): void {
        $chat = new Chat();
        $eventChat = $chat->getEventChat((int)$assignment['event_id']);
        if ($eventChat) {
            $chat->addParticipant((int)$eventChat['id'], $workerId);
        }
        $orderId = (int)($assignment['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->grantOrderAccess($chat, $assignment, $workerId, $orderId);
        }
    }

    private function grantOrderAccess(
        Chat $chat,
        array $assignment,
        int $workerId,
        int $orderId
    ): void {
        $teamChat = $chat->createOrderTeamChat(
            $orderId,
            array_filter([(int)$assignment['organizer_id'], $workerId])
        );
        $this->requireSuccess($teamChat, 'Не удалось создать командный чат');
        $invite = (new TelegramBridge())->scheduleInvite(
            $orderId,
            $workerId,
            'order_team'
        );
        $this->requireSuccess(
            $invite,
            'Не удалось запланировать приглашение в команду'
        );
        $this->announceTeamMember($chat, $workerId, $orderId);
        $this->addCalendarParticipant($assignment, $workerId, $orderId);
    }

    private function announceTeamMember(
        Chat $chat,
        int $workerId,
        int $orderId
    ): void {
        $member = $this->db->prepare(
            'SELECT u.first_name,u.last_name,ot.public_role '
            . 'FROM users u LEFT JOIN order_team ot '
            . 'ON ot.worker_id=u.id AND ot.order_id=? '
            . 'WHERE u.id=? LIMIT 1'
        );
        $member->execute([$orderId, $workerId]);
        $memberRow = $member->fetch() ?: [];
        $clientChat = $chat->getOrderClientChat($orderId);
        if ($clientChat) {
            $name = trim(
                (string)($memberRow['first_name'] ?? '')
                . ' '
                . (string)($memberRow['last_name'] ?? '')
            );
            $role = trim((string)($memberRow['public_role'] ?? ''));
            $role = $role !== '' ? $role : 'Сотрудник';
            $message = $chat->sendSystemMessage(
                (int)$clientChat['id'],
                'К команде присоединился ' . $role . ': ' . $name . '.'
            );
            $this->requireSuccess(
                $message,
                'Не удалось сообщить клиенту о составе команды'
            );
        }
    }

    private function addCalendarParticipant(
        array $assignment,
        int $workerId,
        int $orderId
    ): void {
        $hasCalendar = dbTableExists('calendar_entries')
            && dbTableExists('calendar_entry_participants');
        if ($hasCalendar) {
            $this->db->prepare(
                "INSERT IGNORE INTO calendar_entry_participants
                    (calendar_entry_id,user_id,role_label,assigned_by)
                 SELECT ce.id,?,COALESCE(NULLIF(ot.public_role,''),'Сотрудник'),?
                 FROM calendar_entries ce
                 LEFT JOIN order_team ot
                   ON ot.order_id=ce.order_id AND ot.worker_id=?
                 WHERE ce.order_id=?"
            )->execute([
                $workerId,
                (int)$assignment['organizer_id'],
                $workerId,
                $orderId,
            ]);
        }
    }

    private function revoke(array $assignment, int $workerId): void {
        $this->removeCalendarParticipant($assignment, $workerId);
        $orderId = (int)($assignment['order_id'] ?? 0);
        $this->db->prepare(
            "DELETE cp FROM chat_participants cp
             JOIN chats c ON c.id=cp.chat_id
             WHERE cp.user_id=?
               AND (
                 (c.event_id=? AND c.type='event_group')
                 OR (c.order_id=? AND c.type='order_team')
               )"
        )->execute([$workerId, (int)$assignment['event_id'], $orderId]);
        if ($orderId > 0) {
            $removal = (new TelegramBridge())->scheduleMembershipRemoval(
                $orderId,
                $workerId,
                'order_team'
            );
            $this->requireSuccess(
                $removal,
                'Не удалось запланировать отзыв Telegram-доступа'
            );
        }
    }

    private function removeCalendarParticipant(
        array $assignment,
        int $workerId
    ): void {
        $hasCalendar = dbTableExists('calendar_entries')
            && dbTableExists('calendar_entry_participants');
        if ($hasCalendar) {
            $this->db->prepare(
                'DELETE cep FROM calendar_entry_participants cep '
                . 'JOIN calendar_entries ce ON ce.id=cep.calendar_entry_id '
                . 'WHERE ce.event_id=? AND cep.user_id=?'
            )->execute([(int)$assignment['event_id'], $workerId]);
        }
    }

    private function requireSuccess(array $result, string $fallback): void {
        if (empty($result['success'])) {
            throw new PersistenceException(
                (string)($result['error'] ?? $fallback)
            );
        }
    }
}
