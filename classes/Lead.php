<?php
class Lead {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function statuses() {
        return [
            'new' => 'Новая',
            'in_work' => 'В работе',
            'converted' => 'Заказ создан',
            'irrelevant' => 'Неактуальна',
        ];
    }
    public function workflowStatus($leadOrStatus, $orderId = null) {
        if (is_array($leadOrStatus)) {
            $orderId = $leadOrStatus['order_id'] ?? null;
            $status = (string)($leadOrStatus['status'] ?? 'new');
        } else {
            $status = (string)$leadOrStatus;
        }
        if ($orderId || in_array($status, ['converted', 'order_created'], true)) {
            return 'converted';
        }
        if (in_array($status, ['irrelevant', 'rejected', 'refused', 'lost', 'archived', 'cancelled', 'spam', 'duplicate'], true)) {
            return 'irrelevant';
        }
        return $status === 'new' ? 'new' : 'in_work';
    }
    public function statusLabel($status, $orderId = null) {
        $workflowStatus = $this->workflowStatus($status, $orderId);
        return $this->statuses()[$workflowStatus] ?? $workflowStatus;
    }
    public function canTransition($from, $to) {
        $from = $this->workflowStatus($from);
        $to = $this->workflowStatus($to);
        $transitions = [
            'new' => ['new', 'in_work', 'irrelevant'],
            'in_work' => ['in_work', 'irrelevant'],
            'converted' => ['converted'],
            'irrelevant' => ['irrelevant', 'in_work'],
        ];
        return in_array($to, $transitions[$from] ?? [], true);
    }
    public function createFromQuiz($data, $files = []) {
        return (new LeadQuizService($this->db))->submit($data, $files);
    }
    public function getAll($filters = []) {
        $sql = "
            SELECT l.*, u.first_name, u.last_name,
                   m.first_name AS manager_first_name, m.last_name AS manager_last_name
            FROM leads l
            LEFT JOIN users u ON l.client_id = u.id
            LEFT JOIN users m ON l.manager_id = m.id
            WHERE 1=1
        ";
        $params = [];
        if (!empty($filters['status'])) {
            $status = (string)$filters['status'];
            $statusClauses = [
                'new' => "l.status='new' AND l.order_id IS NULL",
                'in_work' => "l.status NOT IN ('new','converted','order_created','irrelevant','rejected','refused','lost','archived','cancelled','spam','duplicate') AND l.order_id IS NULL",
                'converted' => "(l.order_id IS NOT NULL OR l.status IN ('converted','order_created'))",
                'irrelevant' => "l.status IN ('irrelevant','rejected','refused','lost','archived','cancelled','spam','duplicate') AND l.order_id IS NULL",
            ];
            if (isset($statusClauses[$status])) {
                $sql .= ' AND (' . $statusClauses[$status] . ')';
            }
        }
        if (!empty($filters['manager_id'])) {
            $sql .= !empty($filters['include_unassigned'])
                ? ' AND (l.manager_id = ? OR l.manager_id IS NULL)'
                : ' AND l.manager_id = ?';
            $params[] = (int)$filters['manager_id'];
        }
        if (!empty($filters['query'])) {
            $term = '%' . trim((string)$filters['query']) . '%';
            $sql .= ' AND (CAST(l.id AS CHAR) LIKE ? OR l.client_name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)';
            array_push($params, $term, $term, $term, $term);
        }
        if (!empty($filters['event_date_from'])) {
            $sql .= ' AND l.event_date >= ?';
            $params[] = $filters['event_date_from'];
        }
        if (!empty($filters['event_date_to'])) {
            $sql .= ' AND l.event_date <= ?';
            $params[] = $filters['event_date_to'];
        }
        $sql .= " ORDER BY FIELD(
            CASE
                WHEN l.order_id IS NOT NULL OR l.status IN ('converted','order_created') THEN 'converted'
                WHEN l.status IN ('irrelevant','rejected','refused','lost','archived','cancelled','spam','duplicate') THEN 'irrelevant'
                WHEN l.status='new' THEN 'new'
                ELSE 'in_work'
            END,
            'new','in_work','converted','irrelevant'
        ), l.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $leads = $stmt->fetchAll();
        foreach ($leads as &$lead) {
            $lead['workflow_status'] = $this->workflowStatus($lead);
        }
        unset($lead);
        return $leads;
    }
    public function getForClient($clientId) {
        $stmt = $this->db->prepare("
            SELECT l.*, m.first_name AS manager_first_name, m.last_name AS manager_last_name
            FROM leads l
            LEFT JOIN users m ON l.manager_id = m.id
            WHERE l.client_id = ?
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([(int)$clientId]);
        $leads = $stmt->fetchAll();
        foreach ($leads as &$lead) {
            $lead['workflow_status'] = $this->workflowStatus($lead);
        }
        unset($lead);
        return $leads;
    }
    public function getById($leadId) {
        $stmt = $this->db->prepare("
            SELECT l.*, u.first_name, u.last_name,
                   u.telegram_user_id AS client_telegram_user_id,
                   u.telegram_username AS client_telegram_username,
                   u.telegram_status AS client_telegram_status,
                   m.first_name AS manager_first_name, m.last_name AS manager_last_name,
                   m.avatar AS manager_avatar, m.role AS manager_role
            FROM leads l
            LEFT JOIN users u ON l.client_id = u.id
            LEFT JOIN users m ON l.manager_id = m.id
            WHERE l.id = ?
        ");
        $stmt->execute([(int)$leadId]);
        $lead = $stmt->fetch();
        if ($lead) {
            $lead['workflow_status'] = $this->workflowStatus($lead);
        }
        return $lead;
    }
    public function getAnswers($leadId) {
        $stmt = $this->db->prepare('SELECT * FROM quiz_answers WHERE lead_id = ? ORDER BY id');
        $stmt->execute([(int)$leadId]);
        return $stmt->fetchAll();
    }
    public function getFiles($leadId) {
        $stmt = $this->db->prepare('SELECT * FROM lead_files WHERE lead_id = ? ORDER BY created_at DESC');
        $stmt->execute([(int)$leadId]);
        return $stmt->fetchAll();
    }
    public function claim($leadId, $actorId = null) {
        return (new LeadAssignmentService($this->db))->claim($leadId, $actorId);
    }
    public function updateStatus($leadId, $status, $userId = null, $comment = null) {
        return (new LeadStatusService($this->db, $this))->update(
            (int)$leadId,
            (string)$status,
            $userId !== null ? (int)$userId : null,
            $comment !== null ? (string)$comment : null
        );
    }
    public function assignManager($leadId, $managerId, $actorId = null) {
        return (new LeadAssignmentService($this->db))->assign($leadId, $managerId, $actorId);
    }
    public function updateConversation($leadId, $data, $userId) {
        return (new LeadConversationService($this->db))->update($leadId, $data, $userId);
    }
    public function updateCrmFields($leadId, $data, $userId) {
        return $this->updateConversation($leadId, $data, $userId);
    }
    public function getStatusHistory($leadId) {
        if (!dbTableExists('lead_status_history')) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT h.*, u.first_name, u.last_name FROM lead_status_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.lead_id=? ORDER BY h.id DESC');
        $stmt->execute([(int)$leadId]);
        return $stmt->fetchAll();
    }
}
