<?php
class TelegramBindingContext {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function resolve(array $payload): array {
        $telegramChatId = (int)($payload['telegram_chat_id'] ?? 0);
        $telegramUserId = (int)($payload['telegram_user_id'] ?? 0);
        if ($telegramChatId === 0 || $telegramUserId <= 0) {
            throw new ValidationException('Telegram identity is missing');
        }
        $stmt = $this->db->prepare(
            "SELECT b.*,u.id AS user_id,u.role,m.id AS membership_id,
                    m.invite_id,m.status AS membership_status
             FROM telegram_chat_bindings b
             JOIN telegram_group_memberships m ON m.binding_id=b.id
             JOIN users u
               ON u.id=m.user_id
              AND u.telegram_user_id=m.telegram_user_id
             JOIN chat_participants cp
               ON cp.chat_id=b.site_chat_id
              AND cp.user_id=u.id
             WHERE b.telegram_chat_id=?
               AND b.status='active'
               AND m.telegram_user_id=?
               AND m.status IN ('invited','join_requested','joined')
               AND u.is_active=1
             LIMIT 1"
        );
        $stmt->execute([$telegramChatId, $telegramUserId]);
        $context = $stmt->fetch()
            ?: $this->adminContext($telegramChatId, $telegramUserId);
        if (!$context) {
            throw new AccessDeniedException('Нет доступа к группе заказа');
        }
        $this->assertRoleMatchesChat($context);
        $this->confirmMembership($context);
        $context['membership_status'] = 'joined';

        return $context;
    }

    private function confirmMembership(array $context): void {
        if (empty($context['membership_id'])) {
            return;
        }
        if (($context['membership_status'] ?? '') === 'joined') {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE telegram_group_memberships
             SET status='joined',joined_at=COALESCE(joined_at,NOW()),
                 left_at=NULL,removed_at=NULL
             WHERE id=? AND status IN ('invited','join_requested')"
        );
        $stmt->execute([(int)$context['membership_id']]);
        if ($stmt->rowCount() === 0
            && !$this->membershipIsJoined((int)$context['membership_id'])) {
            throw new AccessDeniedException('Нет доступа к группе заказа');
        }
        if (!empty($context['invite_id'])) {
            $stmt = $this->db->prepare(
                "UPDATE telegram_invites
                 SET status='used',used_at=COALESCE(used_at,NOW())
                 WHERE id=? AND status='pending'"
            );
            $stmt->execute([(int)$context['invite_id']]);
        }
    }

    private function membershipIsJoined(int $membershipId): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM telegram_group_memberships
             WHERE id=? AND status='joined' LIMIT 1"
        );
        $stmt->execute([$membershipId]);

        return (bool)$stmt->fetchColumn();
    }

    private function adminContext(
        int $telegramChatId,
        int $telegramUserId
    ): ?array {
        $stmt = $this->db->prepare(
            "SELECT b.*,u.id AS user_id,u.role,
                    NULL AS membership_id,NULL AS invite_id,
                    'joined' AS membership_status
             FROM telegram_chat_bindings b
             JOIN users u
               ON u.telegram_user_id=?
              AND u.role='admin'
              AND u.is_active=1
             WHERE b.telegram_chat_id=?
               AND b.status='active'
             LIMIT 1"
        );
        $stmt->execute([$telegramUserId, $telegramChatId]);
        $context = $stmt->fetch();

        return $context ?: null;
    }

    private function assertRoleMatchesChat(array $context): void {
        $allowed = match ((string)($context['chat_kind'] ?? '')) {
            'order_client' => ['client', 'organizer', 'admin'],
            'order_team' => [
                'organizer',
                'florist',
                'courier',
                'installer',
                'helper',
                'worker',
                'admin',
            ],
            default => [],
        };
        if (!in_array((string)($context['role'] ?? ''), $allowed, true)) {
            throw new AccessDeniedException(
                'Роль не соответствует группе заказа'
            );
        }
    }
}
