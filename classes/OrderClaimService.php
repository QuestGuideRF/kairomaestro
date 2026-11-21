<?php
class OrderClaimService {
    private const ORGANIZER_GREETING = 'Здравствуйте! Я организатор вашего события. '
        . 'Дальше здесь будем согласовывать детали, материалы, команду и план подготовки.';
    private PDO $db;
    private Order $orderModel;

    public function __construct(PDO $db, Order $orderModel) {
        $this->db = $db;
        $this->orderModel = $orderModel;
    }

    public function claim(int $orderId, int $organizerId): array {
        $order = $this->orderModel->getById($orderId);
        $validationError = $this->validationError($order, $organizerId);
        if ($validationError !== null) {
            $result = ['success' => false, 'error' => $validationError];
        } elseif ((int)($order['organizer_id'] ?? 0) === $organizerId) {
            $result = $this->existingClaimResult((array)$order);
        } else {
            $result = $this->performClaim(
                $orderId,
                $organizerId,
                (array)$order
            );
        }

        return $result;
    }

    private function existingClaimResult(array $order): array {
        $chat = (new Chat())->getOrderClientChat((int)$order['id']);
        return [
            'success' => true,
            'unchanged' => true,
            'event_id' => (int)($order['event_id'] ?? 0),
            'chat_id' => (int)($chat['id'] ?? 0),
        ];
    }

    private function validationError($order, int $organizerId): ?string {
        $error = null;
        if ((int)($_SESSION['user_id'] ?? 0) !== $organizerId) {
            $error = 'Заказ недоступен';
        } elseif (!$order) {
            $error = 'Заказ не найден';
        } elseif (!empty($order['organizer_id'])
            && (int)$order['organizer_id'] !== $organizerId) {
            $error = 'Заказ уже взял другой организатор';
        } elseif (!in_array(currentRole(), ['organizer', 'admin'], true)) {
            $error = 'Только организатор может откликнуться на заказ';
        }

        return $error;
    }

    private function performClaim(
        int $orderId,
        int $organizerId,
        array $order
    ): array {
        $previousOrganizerId = $order['organizer_id'] ?: null;
        try {
            $this->db->beginTransaction();
            $this->persistOrganizer($orderId, $organizerId, $order);
            $order['organizer_id'] = $organizerId;
            $eventId = (new OrderTeamService($this->db))->ensureEventForOrder(
                $order,
                $organizerId
            );
            $chatId = $this->provisionChats(
                $orderId,
                $organizerId,
                $order
            );
            $this->scheduleTelegramAccess($orderId, $organizerId, $order);
            $this->createClientNotification($orderId, $order);
            $this->db->commit();
            $this->recordClaim(
                $orderId,
                $organizerId,
                $eventId,
                $previousOrganizerId
            );
            $this->notifyStakeholders($orderId, $order);
            $result = [
                'success' => true,
                'event_id' => $eventId,
                'chat_id' => $chatId,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = [
                'success' => false,
                'error' => publicExceptionMessage(
                    $error,
                    'Не удалось закрепить заказ'
                ),
            ];
        }

        return $result;
    }

    private function persistOrganizer(
        int $orderId,
        int $organizerId,
        array $order
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE orders SET organizer_id=? '
            . 'WHERE id=? AND (organizer_id IS NULL OR organizer_id=?)'
        );
        $stmt->execute([$organizerId, $orderId, $organizerId]);
        if ($stmt->rowCount() === 0 && empty($order['organizer_id'])) {
            throw new ConflictException(
                'Не удалось закрепить заказ за организатором'
            );
        }
    }

    private function provisionChats(
        int $orderId,
        int $organizerId,
        array $order
    ): int {
        $chat = new Chat();
        $clientChat = $chat->createOrderClientChat(
            $orderId,
            (int)$order['client_id'],
            $organizerId
        );
        $this->requireSuccess(
            $clientChat,
            'Не удалось создать клиентский чат'
        );
        $this->ensureOrganizerGreeting(
            $chat,
            (int)$clientChat['chat_id'],
            $organizerId
        );
        $teamChat = $chat->createOrderTeamChat($orderId, [$organizerId]);
        $this->requireSuccess($teamChat, 'Не удалось создать командный чат');
        if (!empty($order['manager_id'])) {
            $chat->completeManagerHandoff(
                $orderId,
                (int)$order['manager_id']
            );
        }

        return (int)$clientChat['chat_id'];
    }

    private function ensureOrganizerGreeting(
        Chat $chat,
        int $chatId,
        int $organizerId
    ): void {
        $deletedClause = dbColumnExists('messages', 'deleted_at')
            ? ' AND deleted_at IS NULL'
            : '';
        $stmt = $this->db->prepare(
            'SELECT id FROM messages WHERE chat_id=? AND message=?'
            . $deletedClause
            . ' LIMIT 1'
        );
        $stmt->execute([$chatId, self::ORGANIZER_GREETING]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $message = $chat->sendMessage(
            $chatId,
            $organizerId,
            self::ORGANIZER_GREETING
        );
        $this->requireSuccess(
            $message,
            'Не удалось создать приветственное сообщение'
        );
    }

    private function scheduleTelegramAccess(
        int $orderId,
        int $organizerId,
        array $order
    ): void {
        $bridge = new TelegramBridge();
        foreach ($bridge->scheduleOrderProvision($orderId) as $provision) {
            $this->requireSuccess(
                $provision,
                'Не удалось запланировать Telegram-группу'
            );
        }
        $invites = [
            [
                'user_id' => (int)$order['client_id'],
                'kind' => 'order_client',
                'error' => 'Не удалось запланировать приглашение клиента',
            ],
            [
                'user_id' => $organizerId,
                'kind' => 'order_client',
                'error' => 'Не удалось запланировать приглашение организатора',
            ],
            [
                'user_id' => $organizerId,
                'kind' => 'order_team',
                'error' => 'Не удалось запланировать приглашение организатора '
                    . 'в команду',
            ],
        ];
        foreach ($invites as $invite) {
            $this->requireSuccess(
                $bridge->scheduleInvite(
                    $orderId,
                    $invite['user_id'],
                    $invite['kind']
                ),
                $invite['error']
            );
        }
    }

    private function createClientNotification(int $orderId, array $order): void {
        $notification = (new Notification())->create(
            (int)$order['client_id'],
            'order_claimed',
            'Организатор подключился к заказу',
            'Организатор взял ваш заказ в работу. Чат по заказу уже доступен.',
            '/account/order.php?id=' . $orderId
        );
        $this->requireSuccess(
            $notification,
            'Не удалось создать уведомление клиента'
        );
    }

    private function recordClaim(
        int $orderId,
        int $organizerId,
        int $eventId,
        ?int $previousOrganizerId
    ): void {
        AuditLog::record(
            'order.claimed',
            'order',
            $orderId,
            ['organizer_id' => $previousOrganizerId],
            ['organizer_id' => $organizerId, 'event_id' => $eventId],
            ['order_id' => $orderId, 'user_id' => $organizerId]
        );
    }

    private function notifyStakeholders(
        int $orderId,
        array $order
    ): void {
        $organizerName = trim((string)($_SESSION['user_name'] ?? ''));
        $organizerName = $organizerName !== ''
            ? $organizerName
            : 'Организатор';
        $notifier = new TelegramNotifier();
        $notifier->notifyUser(
            (int)$order['client_id'],
            'organizer_assigned',
            $organizerName . ' подключился к вашему заказу «'
                . $order['title'] . '». Чат уже доступен в личном кабинете.',
            'organizer-assigned-' . $orderId
                . '-client-' . (int)$order['client_id'],
            30
        );
        if (!empty($order['manager_id'])) {
            $notifier->notifyUser(
                (int)$order['manager_id'],
                'organizer_assigned',
                $organizerName . ' взял в работу заказ «'
                    . $order['title'] . '».',
                'organizer-assigned-' . $orderId
                    . '-manager-' . (int)$order['manager_id'],
                40
            );
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
