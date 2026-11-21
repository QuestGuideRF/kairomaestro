<?php
class OrganizerProjectOverview {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function build(array $order, $userId) {
        if (!(new Order())->canMutate($order, (int)$userId, currentRole())) {
            throw new AccessDeniedException('Обзор проекта недоступен');
        }
        $overview = [
            'tasks' => $this->taskStats((int)$order['id']),
            'calendar' => $this->calendarStats((int)$order['id']),
            'timeline' => $this->timelineStats((int)$order['id']),
            'team' => $this->teamStats((int)$order['id']),
            'documents' => $this->documentStats((int)$order['id']),
            'concepts' => $this->conceptStats((int)$order['id']),
            'payments' => $this->paymentStats((int)$order['id']),
            'expenses' => $this->expenseStats((int)$order['id']),
            'messages' => $this->messageStats((int)$order['id'], (int)$userId),
        ];
        $overview['attention'] = $this->attention($overview, (int)$order['id']);
        return $overview;
    }
    private function taskStats($orderId) {
        if (!dbTableExists('order_tasks')) {
            return ['open' => 0, 'overdue' => 0, 'soon' => 0];
        }
        $stmt = $this->db->prepare(
            "SELECT
                SUM(status IN ('todo','in_progress')) open,
                SUM(status IN ('todo','in_progress') AND due_date<CURDATE()) overdue,
                SUM(status IN ('todo','in_progress') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) soon
             FROM order_tasks WHERE order_id=?"
        );
        $stmt->execute([(int)$orderId]);
        $stats = $this->ints($stmt->fetch(), ['open', 'overdue', 'soon']);
        $items = $this->db->prepare(
            "SELECT id,title,due_date,status FROM order_tasks
             WHERE order_id=? AND status IN ('todo','in_progress')
               AND due_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY)
             ORDER BY due_date IS NULL,due_date,id LIMIT 8"
        );
        $items->execute([(int)$orderId]);
        $stats['items'] = $items->fetchAll();
        return $stats;
    }
    private function calendarStats($orderId) {
        if (!dbTableExists('calendar_entries')) {
            return ['upcoming' => 0, 'next_at' => null, 'next_title' => null, 'conflicts' => 0];
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) upcoming,MIN(starts_at) next_at
             FROM calendar_entries
             WHERE order_id=? AND status NOT IN ('completed','cancelled') AND ends_at>=NOW()"
        );
        $stmt->execute([(int)$orderId]);
        $stats = $stmt->fetch() ?: [];
        $stats['upcoming'] = (int)($stats['upcoming'] ?? 0);
        $stats['next_title'] = null;
        if (!empty($stats['next_at'])) {
            $next = $this->db->prepare(
                'SELECT title FROM calendar_entries WHERE order_id=? AND starts_at=? ORDER BY id LIMIT 1'
            );
            $next->execute([(int)$orderId, $stats['next_at']]);
            $stats['next_title'] = $next->fetchColumn() ?: null;
        }
        $stats['conflicts'] = 0;
        if (dbTableExists('calendar_entry_participants')) {
            $conflicts = $this->db->prepare(
                'SELECT COUNT(*) FROM calendar_entry_participants participant
                 JOIN calendar_entries entry ON entry.id=participant.calendar_entry_id
                 WHERE entry.order_id=? AND participant.conflict_override=1'
            );
            $conflicts->execute([(int)$orderId]);
            $stats['conflicts'] = (int)$conflicts->fetchColumn();
        }
        return $stats;
    }
    private function timelineStats($orderId) {
        if (!dbTableExists('order_timeline_items')) {
            return ['upcoming' => 0, 'next_at' => null, 'next_title' => null];
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) upcoming,MIN(starts_at) next_at
             FROM order_timeline_items
             WHERE order_id=? AND status<>'completed' AND starts_at>=NOW()"
        );
        $stmt->execute([(int)$orderId]);
        $stats = $stmt->fetch() ?: [];
        $stats['upcoming'] = (int)($stats['upcoming'] ?? 0);
        $stats['next_title'] = null;
        if (!empty($stats['next_at'])) {
            $next = $this->db->prepare(
                'SELECT title FROM order_timeline_items WHERE order_id=? AND starts_at=? ORDER BY id LIMIT 1'
            );
            $next->execute([(int)$orderId, $stats['next_at']]);
            $stats['next_title'] = $next->fetchColumn() ?: null;
        }
        return $stats;
    }
    private function teamStats($orderId) {
        if (!dbTableExists('order_team')) {
            return ['total' => 0, 'pending' => 0, 'problems' => 0];
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) total,
                    SUM(participation_status='pending') pending,
                    SUM(participation_status='declined') problems
             FROM order_team WHERE order_id=?"
        );
        $stmt->execute([(int)$orderId]);
        return $this->ints($stmt->fetch(), ['total', 'pending', 'problems']);
    }
    private function documentStats($orderId) {
        if (!dbTableExists('order_documents')) {
            return ['total' => 0, 'waiting' => 0];
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) total,
                    SUM(status IN ('sent','viewed','changes_requested')) waiting
             FROM order_documents WHERE order_id=?"
        );
        $stmt->execute([(int)$orderId]);
        return $this->ints($stmt->fetch(), ['total', 'waiting']);
    }
    private function conceptStats($orderId) {
        if (!dbTableExists('order_concepts') || !dbTableExists('concept_versions')) {
            return ['total' => 0, 'waiting' => 0];
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) total,
                    SUM(version.status IN ('sent','viewed','changes_requested')) waiting
             FROM order_concepts concept
             LEFT JOIN concept_versions version ON version.id=concept.current_version_id
             WHERE concept.order_id=?"
        );
        $stmt->execute([(int)$orderId]);
        return $this->ints($stmt->fetch(), ['total', 'waiting']);
    }
    private function paymentStats($orderId) {
        if (!dbTableExists('order_payments')) {
            return ['unpaid' => 0, 'overdue' => 0, 'amount_due' => 0.0];
        }
        $stmt = $this->db->prepare(
            "SELECT SUM(status IN ('planned','pending','overdue')) unpaid,
                    SUM(status='overdue' OR (status IN ('planned','pending') AND due_date<CURDATE())) overdue,
                    COALESCE(SUM(CASE WHEN status IN ('planned','pending','overdue') THEN amount ELSE 0 END),0) amount_due
             FROM order_payments WHERE order_id=?"
        );
        $stmt->execute([(int)$orderId]);
        $stats = $this->ints($stmt->fetch(), ['unpaid', 'overdue']);
        $stats['amount_due'] = (float)($stats['amount_due'] ?? 0);
        return $stats;
    }
    private function expenseStats($orderId) {
        if (!dbTableExists('order_expenses')) {
            return ['without_proof' => 0];
        }
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM order_expenses
             WHERE order_id=? AND actual_amount>0 AND (proof_path IS NULL OR proof_path="")'
        );
        $stmt->execute([(int)$orderId]);
        return ['without_proof' => (int)$stmt->fetchColumn()];
    }
    private function messageStats($orderId, $userId) {
        if (!dbTableExists('chats') || !dbTableExists('messages')) {
            return ['unread' => 0];
        }
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM messages message
             JOIN chats chat ON chat.id=message.chat_id
             WHERE chat.order_id=? AND message.is_read=0
               AND (message.user_id IS NULL OR message.user_id<>?)
               AND message.deleted_at IS NULL'
        );
        $stmt->execute([(int)$orderId, (int)$userId]);
        return ['unread' => (int)$stmt->fetchColumn()];
    }
    private function attention(array $overview, int $orderId): array {
        $items = [];
        foreach ($overview['tasks']['items'] ?? [] as $task) {
            $overdue = !empty($task['due_date']) && $task['due_date'] < date('Y-m-d');
            $items[] = [
                'level' => $overdue ? 'critical' : 'soon',
                'title' => $task['title'],
                'detail' => $overdue ? 'Задача просрочена' : 'Срок в ближайшие 7 дней',
                'href' => '#order-tasks',
            ];
        }
        $signals = [
            ['value' => $overview['team']['pending'] ?? 0, 'level' => 'warning', 'title' => 'Есть неподтверждённые участники', 'href' => '#order-team'],
            ['value' => $overview['team']['problems'] ?? 0, 'level' => 'critical', 'title' => 'Есть отказавшиеся участники', 'href' => '#order-team'],
            ['value' => $overview['calendar']['conflicts'] ?? 0, 'level' => 'warning', 'title' => 'Назначения с подтверждённым пересечением', 'href' => '/organizer/calendar.php?order_id=' . $orderId],
            ['value' => $overview['documents']['waiting'] ?? 0, 'level' => 'warning', 'title' => 'Документы ждут решения клиента', 'href' => '/organizer/order-workspace.php?id=' . $orderId . '&section=documents'],
            ['value' => $overview['concepts']['waiting'] ?? 0, 'level' => 'warning', 'title' => 'Концепция ждёт решения клиента', 'href' => '/organizer/order-workspace.php?id=' . $orderId . '&section=concept'],
            ['value' => $overview['payments']['overdue'] ?? 0, 'level' => 'critical', 'title' => 'Есть просроченные платежи', 'href' => '/organizer/order-workspace.php?id=' . $orderId . '&section=finance'],
            ['value' => $overview['expenses']['without_proof'] ?? 0, 'level' => 'warning', 'title' => 'Расходы без подтверждения', 'href' => '/organizer/order-workspace.php?id=' . $orderId . '&section=finance'],
            ['value' => $overview['messages']['unread'] ?? 0, 'level' => 'soon', 'title' => 'Новые сообщения по проекту', 'href' => '/chat.php'],
        ];
        foreach ($signals as $signal) {
            if ((int)$signal['value'] < 1) {
                continue;
            }
            $items[] = [
                'level' => $signal['level'],
                'title' => $signal['title'],
                'detail' => (int)$signal['value'] . ' шт.',
                'href' => $signal['href'],
            ];
        }
        if (!empty($overview['timeline']['next_at'])) {
            $items[] = [
                'level' => 'soon',
                'title' => $overview['timeline']['next_title'] ?: 'Ближайший пункт таймлайна',
                'detail' => formatRussianDateTime($overview['timeline']['next_at']),
                'href' => '/organizer/order-workspace.php?id=' . $orderId . '&section=timeline',
            ];
        }
        return array_slice($items, 0, 12);
    }
    private function ints($row, array $keys) {
        $row = is_array($row) ? $row : [];
        foreach ($keys as $key) {
            $row[$key] = (int)($row[$key] ?? 0);
        }
        return $row;
    }
}
