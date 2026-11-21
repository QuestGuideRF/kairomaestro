<?php
class LeadStatusService {
    private const ERROR_LEAD_UNAVAILABLE = 'Заявка недоступна';

    private PDO $db;
    private Lead $leadModel;

    public function __construct(PDO $db, Lead $leadModel) {
        $this->db = $db;
        $this->leadModel = $leadModel;
    }

    public function update(
        int $leadId,
        string $status,
        ?int $userId = null,
        ?string $comment = null
    ): array {
        if (!$userId) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }
        $comment = trim((string)$comment);
        $context = $this->claimedLead($leadId, $userId);
        if ($context['error'] !== null) {
            $result = $context['error'];
        } else {
            $lead = (array)$context['lead'];
            $validationError = $this->validationError(
                $lead,
                $status,
                $userId,
                $comment
            );
            if ($validationError !== null) {
                $result = ['success' => false, 'error' => $validationError];
            } elseif ($this->leadModel->workflowStatus($lead) === $status) {
                $result = ['success' => true, 'unchanged' => true];
            } else {
                $result = $this->persist(
                    $leadId,
                    $lead,
                    $status,
                    $userId,
                    $comment
                );
            }
        }

        return $result;
    }

    private function claimedLead(int $leadId, int $userId): array {
        $lead = $this->leadModel->getById($leadId);
        $error = null;
        if ($lead && currentRole() === 'manager' && empty($lead['manager_id'])) {
            $claim = $this->leadModel->claim($leadId, $userId);
            if (empty($claim['success'])) {
                $error = $claim;
            } else {
                $lead = $this->leadModel->getById($leadId);
            }
        }

        return ['lead' => $lead, 'error' => $error];
    }

    private function validationError(
        ?array $lead,
        string $status,
        int $userId,
        string $comment
    ): ?string {
        $error = null;
        if (!array_key_exists($status, $this->leadModel->statuses())) {
            $error = 'Недопустимый статус заявки';
        } elseif (!$lead) {
            $error = 'Заявка не найдена';
        } elseif (!$this->canManage($lead, $userId)) {
            $error = self::ERROR_LEAD_UNAVAILABLE;
        } elseif (currentRole() === 'manager'
            && in_array($status, ['new', 'converted'], true)) {
            $error = 'Этот статус устанавливается системой';
        } elseif (!$this->leadModel->canTransition(
            $lead['workflow_status'],
            $status
        ) && currentRole() !== 'admin') {
            $error = 'Недопустимый переход статуса';
        } elseif ($this->textLength($comment) > 2000) {
            $error = 'Комментарий слишком длинный';
        }

        return $error;
    }

    private function canManage(array $lead, int $userId): bool {
        $ownsSession = $userId === (int)($_SESSION['user_id'] ?? 0);
        $isAssignedManager = currentRole() === 'manager'
            && !empty($lead['manager_id'])
            && (int)$lead['manager_id'] === $userId;

        return $ownsSession
            && (currentRole() === 'admin' || $isAssignedManager);
    }

    private function persist(
        int $leadId,
        array $lead,
        string $status,
        int $userId,
        string $comment
    ): array {
        $oldStatus = $this->leadModel->workflowStatus($lead);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE leads SET status=? WHERE id=? AND status=?'
            );
            $stmt->execute([$status, $leadId, $lead['status']]);
            if ($stmt->rowCount() !== 1) {
                throw new ConflictException(
                    'Статус уже изменён другим пользователем'
                );
            }
            $this->recordHistory(
                $leadId,
                $oldStatus,
                $status,
                $userId,
                $comment
            );
            $this->db->commit();
            AuditLog::record(
                'lead.status_changed',
                'lead',
                $leadId,
                ['status' => $oldStatus],
                ['status' => $status, 'comment' => $comment],
                ['lead_id' => $leadId, 'user_id' => $userId]
            );
            $result = ['success' => true];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $result = [
                'success' => false,
                'error' => publicExceptionMessage(
                    $error,
                    'Не удалось изменить статус заявки'
                ),
            ];
        }

        return $result;
    }

    private function recordHistory(
        int $leadId,
        string $oldStatus,
        string $status,
        int $userId,
        string $comment
    ): void {
        if (dbTableExists('lead_status_history')) {
            $this->db->prepare(
                'INSERT INTO lead_status_history '
                . '(lead_id,old_status,new_status,changed_by,comment) '
                . 'VALUES (?,?,?,?,?)'
            )->execute([
                $leadId,
                $oldStatus,
                $status,
                $userId ?: null,
                $comment !== '' ? $comment : null,
            ]);
        }
    }

    private function textLength(string $value): int {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}
