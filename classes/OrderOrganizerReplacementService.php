<?php
class OrderOrganizerReplacementService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function replace(
        int $orderId,
        int $organizerId,
        int $actorId
    ): array {
        $validationError = $this->validationError(
            $orderId,
            $organizerId,
            $actorId
        );
        if ($validationError !== null) {
            $result = ['success' => false, 'error' => $validationError];
        } else {
            $result = $this->perform($orderId, $organizerId, $actorId);
        }

        return $result;
    }

    private function validationError(
        int $orderId,
        int $organizerId,
        int $actorId
    ): ?string {
        $error = null;
        if (!isAdminRole()
            || $actorId !== (int)($_SESSION['user_id'] ?? 0)) {
            $error = 'Недостаточно прав для замены организатора';
        } elseif ($orderId <= 0 || $organizerId <= 0) {
            $error = 'Выберите заказ и организатора';
        }

        return $error;
    }

    private function perform(
        int $orderId,
        int $organizerId,
        int $actorId
    ): array {
        try {
            $this->db->beginTransaction();
            $order = $this->lockedOrder($orderId);
            $organizer = $this->activeOrganizer($organizerId);
            $previousOrganizerId = (int)($order['organizer_id'] ?? 0);
            if ($previousOrganizerId === $organizerId) {
                $result = ['success' => true, 'unchanged' => true];
            } else {
                $eventId = $this->persistOrganizer(
                    $orderId,
                    $organizerId,
                    $previousOrganizerId,
                    $order
                );
                $chats = $this->provisionChats(
                    $orderId,
                    $organizerId,
                    $eventId,
                    $order
                );
                $this->syncTelegram(
                    $orderId,
                    $organizerId,
                    $previousOrganizerId
                );
                $this->announceReplacement(
                    [
                        'order_id' => $orderId,
                        'organizer_id' => $organizerId,
                        'event_id' => $eventId,
                        'previous_organizer_id' => $previousOrganizerId,
                        'actor_id' => $actorId,
                    ],
                    $order,
                    $organizer,
                    $chats
                );
                $result = ['success' => true];
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = [
                'success' => false,
                'error' => publicExceptionMessage(
                    $error,
                    'Не удалось заменить организатора'
                ),
            ];
        }

        return $result;
    }

    private function lockedOrder(int $orderId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM orders WHERE id=? FOR UPDATE'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new EntityNotFoundException('Заказ не найден');
        }
        if (in_array(
            (string)$order['status'],
            ['completed', 'cancelled'],
            true
        )) {
            throw new ConflictException(
                'Нельзя менять организатора завершённого или отменённого '
                . 'заказа'
            );
        }

        return $order;
    }

    private function activeOrganizer(int $organizerId): array {
        $stmt = $this->db->prepare(
            "SELECT id,first_name,last_name FROM users
             WHERE id=? AND role='organizer' AND is_active=1
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$organizerId]);
        $organizer = $stmt->fetch();
        if (!$organizer) {
            throw new EntityNotFoundException(
                'Активный организатор не найден'
            );
        }

        return $organizer;
    }

    private function persistOrganizer(
        int $orderId,
        int $organizerId,
        int $previousOrganizerId,
        array &$order
    ): int {
        $this->db->prepare(
            'UPDATE orders SET organizer_id=? WHERE id=?'
        )->execute([$organizerId, $orderId]);
        if (!empty($order['event_id'])) {
            $this->db->prepare(
                'UPDATE events SET organizer_id=? WHERE id=?'
            )->execute([$organizerId, (int)$order['event_id']]);
        }
        if ($previousOrganizerId > 0) {
            $this->removePreviousProjectAccess(
                $orderId,
                (int)($order['event_id'] ?? 0),
                $previousOrganizerId
            );
        }
        $order['organizer_id'] = $organizerId;

        return (new OrderTeamService($this->db))->ensureEventForOrder(
            $order,
            $organizerId
        );
    }

    private function removePreviousProjectAccess(
        int $orderId,
        int $eventId,
        int $organizerId
    ): void {
        $this->db->prepare(
            "DELETE cp
             FROM chat_participants cp
             JOIN chats c ON c.id=cp.chat_id
             WHERE cp.user_id=?
               AND (
                 (c.order_id=? AND c.type IN ('order_client','order_team'))
                 OR (c.event_id=? AND c.type='event_group')
               )"
        )->execute([$organizerId, $orderId, $eventId]);
        $hasCalendar = dbTableExists('calendar_entries')
            && dbTableExists('calendar_entry_participants');
        if ($hasCalendar) {
            $this->db->prepare(
                'DELETE cep FROM calendar_entry_participants cep '
                . 'JOIN calendar_entries ce '
                . 'ON ce.id=cep.calendar_entry_id '
                . 'WHERE ce.order_id=? AND cep.user_id=?'
            )->execute([$orderId, $organizerId]);
        }
    }

    private function provisionChats(
        int $orderId,
        int $organizerId,
        int $eventId,
        array $order
    ): array {
        $chat = new Chat();
        $clientChat = $chat->createOrderClientChat(
            $orderId,
            (int)$order['client_id'],
            $organizerId
        );
        $this->requireSuccess(
            $clientChat,
            'Не удалось обновить клиентский чат'
        );
        $teamChat = $chat->createOrderTeamChat($orderId, [$organizerId]);
        $this->requireSuccess(
            $teamChat,
            'Не удалось обновить командный чат'
        );
        $eventChat = $chat->getEventChat($eventId);
        if ($eventChat) {
            $chat->addParticipant((int)$eventChat['id'], $organizerId);
        }

        return ['chat' => $chat, 'client' => $clientChat];
    }

    private function syncTelegram(
        int $orderId,
        int $organizerId,
        int $previousOrganizerId
    ): void {
        $bridge = new TelegramBridge();
        if ($previousOrganizerId > 0) {
            foreach (['order_client', 'order_team'] as $chatKind) {
                $this->requireSuccess(
                    $bridge->scheduleMembershipRemoval(
                        $orderId,
                        $previousOrganizerId,
                        $chatKind
                    ),
                    'Не удалось запланировать отзыв доступа прежнего '
                        . 'организатора'
                );
            }
        }
        foreach ($bridge->scheduleOrderProvision($orderId) as $provision) {
            $this->requireSuccess(
                $provision,
                'Не удалось проверить Telegram-группу заказа'
            );
        }
        foreach (['order_client', 'order_team'] as $chatKind) {
            $this->requireSuccess(
                $bridge->scheduleInvite(
                    $orderId,
                    $organizerId,
                    $chatKind
                ),
                'Не удалось запланировать приглашение нового организатора'
            );
        }
    }

    private function announceReplacement(
        array $context,
        array $order,
        array $organizer,
        array $chats
    ): void {
        $organizerName = trim(
            (string)$organizer['first_name']
            . ' '
            . (string)$organizer['last_name']
        );
        $message = $chats['chat']->sendSystemMessage(
            (int)$chats['client']['chat_id'],
            'Назначен новый организатор события: ' . $organizerName . '.'
        );
        $this->requireSuccess(
            $message,
            'Не удалось сообщить клиенту о смене организатора'
        );
        $notification = (new Notification())->create(
            $context['organizer_id'],
            'order_assigned',
            'Вам назначен заказ',
            'Вы назначены организатором заказа «' . $order['title'] . '».',
            '/organizer/order-workspace.php?id=' . $context['order_id']
        );
        $this->requireSuccess(
            $notification,
            'Не удалось создать уведомление организатора'
        );
        AuditLog::record(
            'admin.order_organizer_replaced',
            'order',
            $context['order_id'],
            [
                'organizer_id' => $context['previous_organizer_id']
                    ?: null,
            ],
            [
                'organizer_id' => $context['organizer_id'],
                'event_id' => $context['event_id'],
            ],
            [
                'order_id' => $context['order_id'],
                'user_id' => $context['actor_id'],
                'role' => 'admin',
            ]
        );
    }

    private function requireSuccess(array $result, string $fallback): void {
        if (empty($result['success'])) {
            throw new PersistenceException(
                (string)($result['error'] ?? $fallback)
            );
        }
    }
}
