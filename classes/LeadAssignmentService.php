<?php
class LeadAssignmentService {
    private const ERROR_LEAD_UNAVAILABLE = 'Заявка недоступна';
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function claim($leadId, $actorId = null) {
        $actorId = (int)($actorId ?: ($_SESSION['user_id'] ?? 0));
        try {
            $this->assertActor($actorId);
            $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT id, client_name, manager_id, order_id, status FROM leads WHERE id=? FOR UPDATE');
            $stmt->execute([(int)$leadId]);
            $lead = $stmt->fetch();
            if (!$lead) {
                throw new EntityNotFoundException('Заявка не найдена');
            }
            if (!empty($lead['order_id'])) {
                throw new ConflictException('По заявке уже создан заказ');
            }
            if (!empty($lead['manager_id']) && (int)$lead['manager_id'] !== $actorId && currentRole() !== 'admin') {
                throw new ConflictException('Заявку уже взял другой менеджер');
            }
            $managerId = !empty($lead['manager_id']) ? (int)$lead['manager_id'] : $actorId;
            $oldWorkflowStatus = (new Lead())->workflowStatus($lead);
            $newStatus = $oldWorkflowStatus === 'new' ? 'in_work' : $oldWorkflowStatus;
            $stmt = $this->db->prepare('UPDATE leads SET manager_id=?, status=? WHERE id=? AND (manager_id IS NULL OR manager_id=?)');
            $stmt->execute([$managerId, $newStatus, (int)$leadId, $managerId]);
            if ($stmt->rowCount() !== 1 && empty($lead['manager_id'])) {
                throw new ConflictException('Заявку уже взял другой менеджер');
            }
            if ($oldWorkflowStatus !== $newStatus && dbTableExists('lead_status_history')) {
                $history = $this->db->prepare("INSERT INTO lead_status_history (lead_id,old_status,new_status,changed_by,comment) VALUES (?,?,?,?, 'Менеджер начал работу с заявкой')");
                $history->execute([(int)$leadId, $oldWorkflowStatus, $newStatus, $actorId]);
            }
            $this->db->commit();
            $this->syncLeadChat((int)$leadId, $managerId);
            AuditLog::record('lead.claimed', 'lead', (int)$leadId, [
                'manager_id' => $lead['manager_id'] ?: null,
                'status' => $oldWorkflowStatus,
            ], [
                'manager_id' => $managerId,
                'status' => $newStatus,
            ], [
                'lead_id' => (int)$leadId,
                'user_id' => $actorId,
            ]);
            return ['success' => true, 'manager_id' => $managerId, 'status' => $newStatus];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => publicExceptionMessage($error, self::ERROR_LEAD_UNAVAILABLE)];
        }
    }
    public function assign($leadId, $managerId, $actorId = null) {
        $actorId = (int)($actorId ?: ($_SESSION['user_id'] ?? 0));
        if (currentRole() === 'manager') {
            return $this->claim($leadId, $actorId);
        }
        try {
            if (currentRole() !== 'admin' || $actorId !== (int)($_SESSION['user_id'] ?? 0)) {
                throw new AccessDeniedException(self::ERROR_LEAD_UNAVAILABLE);
            }
            $managerId = (int)$managerId;
            if (!$this->managerIsAvailable($managerId)) {
                throw new ValidationException('Ответственный менеджер недоступен');
            }
            $stmt = $this->db->prepare('SELECT manager_id FROM leads WHERE id=?');
            $stmt->execute([(int)$leadId]);
            $oldManagerId = $stmt->fetchColumn();
            if ($oldManagerId === false) {
                throw new EntityNotFoundException('Заявка не найдена');
            }
            $this->db->prepare('UPDATE leads SET manager_id=? WHERE id=?')->execute([$managerId, (int)$leadId]);
            $this->syncLeadChat((int)$leadId, $managerId);
            AuditLog::record('lead.manager_assigned', 'lead', (int)$leadId, [
                'manager_id' => $oldManagerId ?: null,
            ], [
                'manager_id' => $managerId,
            ], [
                'lead_id' => (int)$leadId,
                'user_id' => $actorId,
            ]);
            return ['success' => true];
        } catch (Throwable $error) {
            return ['success' => false, 'error' => publicExceptionMessage($error, self::ERROR_LEAD_UNAVAILABLE)];
        }
    }
    private function assertActor($actorId) {
        if ($actorId <= 0 || $actorId !== (int)($_SESSION['user_id'] ?? 0)
            || !in_array(currentRole(), ['manager', 'admin'], true)) {
            throw new AccessDeniedException(self::ERROR_LEAD_UNAVAILABLE);
        }
        if (!$this->managerIsAvailable($actorId)) {
            throw new AccessDeniedException(self::ERROR_LEAD_UNAVAILABLE);
        }
    }
    private function managerIsAvailable($managerId) {
        $activeWhere = dbColumnExists('users', 'is_active') ? ' AND is_active=1' : '';
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id=? AND role IN ('manager','admin'){$activeWhere} LIMIT 1");
        $stmt->execute([(int)$managerId]);
        return (bool)$stmt->fetchColumn();
    }
    private function syncLeadChat($leadId, $managerId) {
        $chatService = new Chat();
        $chat = $chatService->getLeadChat($leadId);
        if ($chat) {
            $chatService->addParticipant((int)$chat['id'], (int)$managerId);
        }
    }
}
