<?php
trait AdminUserRepository {
    public function updateUser(int $userId, string $role, bool $active, int $actorId): array {
        $validationError = $this->userUpdateValidationError(
            $userId,
            $role,
            $active,
            $actorId
        );
        if ($validationError !== null) {
            return ['success' => false, 'error' => $validationError];
        }

        $previousUser = $this->findUserAccessState($userId);
        if ($previousUser === null) {
            return ['success' => false, 'error' => 'Пользователь не найден'];
        }

        return $this->performUserUpdate(
            $userId,
            $role,
            $active,
            $actorId,
            $previousUser
        );
    }

    private function userUpdateValidationError(
        int $userId,
        string $role,
        bool $active,
        int $actorId
    ): ?string {
        $error = null;
        if (!in_array($role, self::USER_ROLES, true)) {
            $error = 'Неизвестная роль';
        } elseif ($userId === $actorId && (!$active || $role !== 'admin')) {
            $error = 'Нельзя лишить текущий аккаунт доступа администратора';
        }

        return $error;
    }

    private function findUserAccessState(int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT role,is_active,telegram_user_id FROM users WHERE id=? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    private function performUserUpdate(
        int $userId,
        string $role,
        bool $active,
        int $actorId,
        array $previousUser
    ): array {
        try {
            $this->db->beginTransaction();
            $memberships = $this->activeTelegramMemberships($userId, $active);
            $this->persistUserAccess($userId, $role, $active, $previousUser);
            $this->revokeUserSessions($userId, $active);
            $this->scheduleTelegramAccessRemoval($userId, $active, $memberships);
            $this->recordUserUpdate(
                $userId,
                $role,
                $active,
                $actorId,
                $previousUser,
                $memberships
            );
            $this->db->commit();
            $result = ['success' => true];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = [
                'success' => false,
                'error' => publicExceptionMessage(
                    $error,
                    'Не удалось изменить пользователя'
                ),
            ];
        }

        return $result;
    }

    private function activeTelegramMemberships(int $userId, bool $active): array {
        $memberships = [];
        $canLoad = !$active
            && dbTableExists('telegram_group_memberships')
            && dbTableExists('telegram_chat_bindings');
        if ($canLoad) {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT b.order_id,b.chat_kind
                 FROM telegram_group_memberships m
                 JOIN telegram_chat_bindings b ON b.id=m.binding_id
                 WHERE m.user_id=?
                   AND b.status='active'
                   AND m.status IN ('invited','join_requested','joined')"
            );
            $stmt->execute([$userId]);
            $memberships = $stmt->fetchAll();
        }

        return $memberships;
    }

    private function persistUserAccess(
        int $userId,
        string $role,
        bool $active,
        array $previousUser
    ): void {
        $sets = ['role=?', 'is_active=?'];
        $params = [$role, $active ? 1 : 0];
        if (dbColumnExists('users', 'telegram_status')) {
            $sets[] = 'telegram_status=?';
            $params[] = $this->telegramStatusForUser($active, $previousUser);
        }
        $params[] = $userId;
        $this->db->prepare(
            'UPDATE users SET ' . implode(',', $sets) . ' WHERE id=?'
        )->execute($params);
    }

    private function telegramStatusForUser(bool $active, array $previousUser): string {
        $status = 'unlinked';
        if (!$active) {
            $status = 'disabled';
        } elseif (!empty($previousUser['telegram_user_id'])) {
            $status = 'active';
        }

        return $status;
    }

    private function revokeUserSessions(int $userId, bool $active): void {
        if (!$active && dbTableExists('user_sessions')) {
            $this->db->prepare(
                'UPDATE user_sessions SET revoked_at=NOW() '
                . 'WHERE user_id=? AND revoked_at IS NULL'
            )->execute([$userId]);
        }
    }

    private function scheduleTelegramAccessRemoval(
        int $userId,
        bool $active,
        array $memberships
    ): void {
        if (!$active) {
            $bridge = new TelegramBridge();
            foreach ($memberships as $membership) {
                $queued = $bridge->scheduleMembershipRemoval(
                    (int)$membership['order_id'],
                    $userId,
                    (string)$membership['chat_kind']
                );
                if (empty($queued['success'])) {
                    throw new NotificationException(
                        'Не удалось поставить отзыв Telegram-доступа в очередь'
                    );
                }
            }
        }
    }

    private function recordUserUpdate(
        int $userId,
        string $role,
        bool $active,
        int $actorId,
        array $previousUser,
        array $memberships
    ): void {
        AuditLog::record('admin.user_updated', 'user', $userId, $previousUser, [
            'role' => $role,
            'is_active' => $active,
            'telegram_memberships_revoked' => !$active ? count($memberships) : 0,
        ], ['user_id' => $actorId, 'role' => 'admin']);
    }
}
