<?php
class OrderFinance {
    use OrderFinanceInput;
    private const ERROR_FINANCE_UNAVAILABLE = 'Финансы заказа недоступны';
    protected const DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function summary($orderId) {
        if (!$this->canViewOrder($orderId, (int)($_SESSION['user_id'] ?? 0))) {
            return $this->emptySummary($orderId);
        }
        $this->recalculate($orderId);
        $stmt = $this->db->prepare("SELECT * FROM order_finance WHERE order_id=?");
        $stmt->execute([(int)$orderId]);
        return $stmt->fetch() ?: $this->emptySummary($orderId);
    }
    public function updatePlan($orderId, $userId, $data) {
        if (!$this->canManageOrder($orderId, $userId)) {
            return ['success' => false, 'error' => self::ERROR_FINANCE_UNAVAILABLE];
        }
        foreach (['total_cost', 'planned_revenue', 'prepayment', 'planned_expenses'] as $field) {
            if (!$this->isMoneyValue($data[$field] ?? 0)) {
                return ['success' => false, 'error' => 'Проверьте денежные суммы'];
            }
        }
        $old = $this->summary($orderId);
        $totalCost = $this->money($data['total_cost'] ?? 0);
        $plannedRevenue = $this->money($data['planned_revenue'] ?? $totalCost);
        $prepayment = $this->money($data['prepayment'] ?? 0);
        $plannedExpenses = $this->money($data['planned_expenses'] ?? 0);
        $stmt = $this->db->prepare("\n            INSERT INTO order_finance (order_id,total_cost,planned_revenue,prepayment,planned_expenses,updated_by)\n            VALUES (?,?,?,?,?,?)\n            ON DUPLICATE KEY UPDATE total_cost=VALUES(total_cost),planned_revenue=VALUES(planned_revenue),prepayment=VALUES(prepayment),planned_expenses=VALUES(planned_expenses),updated_by=VALUES(updated_by)\n        ");
        $stmt->execute([(int)$orderId, $totalCost, $plannedRevenue, $prepayment, $plannedExpenses, (int)$userId]);
        $new = $this->recalculate($orderId);
        AuditLog::record('finance.plan_updated', 'order_finance', (int)$orderId, $old, $new, ['order_id' => (int)$orderId, 'user_id' => $userId]);
        return ['success' => true, 'summary' => $new];
    }
    public function addPayment($orderId, $userId, $data, $file = null) {
        $proof = null;
        try {
            $payment = $this->normalizePaymentInput($orderId, $userId, $data);
            if ($file && !empty($file['name'])) {
                $upload = FileStorage::store($file, 'finance', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
                if (!$upload['success']) {
                    throw new FileStorageException($upload['error'] ?? 'Не удалось загрузить подтверждение платежа');
                }
                $proof = $upload['file_path'];
            }
            $stmt = $this->db->prepare("INSERT INTO order_payments (order_id,title,amount,due_date,paid_at,status,payment_method,comment,proof_path,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                (int)$orderId,
                $payment['title'],
                $payment['amount'],
                $payment['due_date'],
                $payment['paid_at'],
                $payment['status'],
                $payment['payment_method'],
                $payment['comment'],
                $proof,
                (int)$userId,
            ]);
            $id = (int)$this->db->lastInsertId();
            $summary = $this->recalculate($orderId);
            AuditLog::record('finance.payment_created', 'order_payment', $id, null, [
                'amount' => $payment['amount'],
                'status' => $payment['status'],
                'due_date' => $payment['due_date'],
            ], ['order_id' => (int)$orderId, 'user_id' => $userId]);
            $this->notifyClientPayment($orderId, $id, $payment['title'], $payment['amount']);
            $result = ['success' => true, 'payment_id' => $id, 'summary' => $summary];
        } catch (Throwable $error) {
            FileStorage::deleteOwnedFile($proof, 'finance', 'order', (int)$orderId);
            $result = ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить платёж')];
        }
        return $result;
    }
    public function updatePaymentStatus($paymentId, $userId, $status, $paidAt = null) {
        try {
            $change = $this->paymentStatusUpdateData($paymentId, $userId, $status, $paidAt);
            $old = $change['payment'];
            $paidAt = $change['paid_at'];
            $this->db->prepare("UPDATE order_payments SET status=?,paid_at=? WHERE id=?")->execute([$status, $paidAt, (int)$paymentId]);
            $summary = $this->recalculate($old['order_id']);
            AuditLog::record('finance.payment_status_changed', 'order_payment', (int)$paymentId, ['status' => $old['status'], 'paid_at' => $old['paid_at']], ['status' => $status, 'paid_at' => $paidAt], ['order_id' => $old['order_id'], 'user_id' => $userId]);
            $result = ['success' => true, 'summary' => $summary];
        } catch (AppException $error) {
            $result = ['success' => false, 'error' => $error->getMessage()];
        }
        return $result;
    }
    public function addExpense($orderId, $userId, $data, $file = null) {
        $proof = null;
        try {
            $expense = $this->normalizeExpenseInput($orderId, $userId, $data);
            if ($file && !empty($file['name'])) {
                $upload = FileStorage::store($file, 'finance', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
                if (!$upload['success']) {
                    throw new FileStorageException($upload['error'] ?? 'Не удалось загрузить подтверждение расхода');
                }
                $proof = $upload['file_path'];
            }
            $stmt = $this->db->prepare("INSERT INTO order_expenses (order_id,category,title,planned_amount,actual_amount,expense_date,comment,proof_path,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                (int)$orderId,
                $expense['category'],
                $expense['title'],
                $expense['planned_amount'],
                $expense['actual_amount'],
                $expense['expense_date'],
                $expense['comment'],
                $proof,
                (int)$userId,
            ]);
            $id = (int)$this->db->lastInsertId();
            $summary = $this->recalculate($orderId);
            AuditLog::record('finance.expense_created', 'order_expense', $id, null, [
                'category' => $expense['category'],
                'planned_amount' => $expense['planned_amount'],
                'actual_amount' => $expense['actual_amount'],
            ], ['order_id' => (int)$orderId, 'user_id' => $userId]);
            $result = ['success' => true, 'expense_id' => $id, 'summary' => $summary];
        } catch (Throwable $error) {
            FileStorage::deleteOwnedFile($proof, 'finance', 'order', (int)$orderId);
            $result = ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить расход')];
        }
        return $result;
    }
    public function payments($orderId, $filters = []) {
        if (!$this->canViewOrder($orderId, (int)($_SESSION['user_id'] ?? 0))) {
            return [];
        }
        $sql = 'SELECT * FROM order_payments WHERE order_id=?';
        $params = [(int)$orderId];
        if (!empty($filters['status'])) {
            $sql .= ' AND status=?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['query'])) {
            $sql .= ' AND (title LIKE ? OR comment LIKE ?)';
            $term = '%' . trim($filters['query']) . '%';
            array_push($params, $term, $term);
        }
        $sql .= ' ORDER BY due_date IS NULL,due_date,id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function expenses($orderId, $filters = []) {
        if (!$this->canViewOrder($orderId, (int)($_SESSION['user_id'] ?? 0))) {
            return [];
        }
        $sql = 'SELECT * FROM order_expenses WHERE order_id=?';
        $params = [(int)$orderId];
        if (!empty($filters['category'])) {
            $sql .= ' AND category=?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['query'])) {
            $sql .= ' AND (title LIKE ? OR comment LIKE ?)';
            $term = '%' . trim($filters['query']) . '%';
            array_push($params, $term, $term);
        }
        $sql .= ' ORDER BY expense_date DESC,id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function recalculate($orderId) {
        $this->db->prepare("INSERT IGNORE INTO order_finance (order_id) VALUES (?)")->execute([(int)$orderId]);
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) actual_revenue FROM order_payments WHERE order_id=?");
        $stmt->execute([(int)$orderId]);
        $actualRevenue = (float)$stmt->fetchColumn();
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(CASE WHEN category<>'staff' THEN planned_amount ELSE 0 END),0) planned_other,COALESCE(SUM(CASE WHEN category='staff' THEN planned_amount ELSE 0 END),0) planned_staff,COALESCE(SUM(CASE WHEN category<>'staff' THEN actual_amount ELSE 0 END),0) actual_other,COALESCE(SUM(CASE WHEN category='staff' THEN actual_amount ELSE 0 END),0) actual_staff FROM order_expenses WHERE order_id=?");
        $stmt->execute([(int)$orderId]);
        $expense = $stmt->fetch();
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(hourly_rate*planned_hours),0) FROM order_team WHERE order_id=?");
        $stmt->execute([(int)$orderId]);
        $plannedWorkerPayments = (float)$stmt->fetchColumn();
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(p.total_amount),0) FROM payments p JOIN orders o ON o.event_id=p.event_id WHERE o.id=? AND p.status IN ('approved','paid')");
        $stmt->execute([(int)$orderId]);
        $actualWorkerPayments = (float)$stmt->fetchColumn();
        $stmt = $this->db->prepare("SELECT total_cost,planned_revenue,prepayment,planned_expenses FROM order_finance WHERE order_id=?");
        $stmt->execute([(int)$orderId]);
        $base = $stmt->fetch();
        $linePlannedExpenses = (float)$expense['planned_other'] + max((float)$expense['planned_staff'], $plannedWorkerPayments);
        $plannedExpenses = max((float)$base['planned_expenses'], $linePlannedExpenses);
        $actualExpenses = (float)$expense['actual_other'] + max((float)$expense['actual_staff'], $actualWorkerPayments);
        $plannedRevenue = (float)$base['planned_revenue'];
        $plannedProfit = $plannedRevenue - $plannedExpenses;
        $actualProfit = $actualRevenue - $actualExpenses;
        $margin = $actualRevenue > 0 ? ($actualProfit / $actualRevenue) * 100 : 0;
        $stmt = $this->db->prepare("UPDATE order_finance SET actual_revenue=?,planned_expenses=?,actual_expenses=?,planned_profit=?,actual_profit=?,margin_percent=? WHERE order_id=?");
        $stmt->execute([$actualRevenue, $plannedExpenses, $actualExpenses, $plannedProfit, $actualProfit, $margin, (int)$orderId]);
        $stmt = $this->db->prepare("SELECT *,GREATEST((CASE WHEN total_cost>0 THEN total_cost ELSE planned_revenue END)-actual_revenue,0) AS client_debt FROM order_finance WHERE order_id=?");
        $stmt->execute([(int)$orderId]);
        $summary = $stmt->fetch();
        $summary['planned_worker_payments'] = $plannedWorkerPayments;
        $summary['actual_worker_payments'] = $actualWorkerPayments;
        return $summary;
    }
    public function queueOverdueReminders() {
        $stmt = $this->db->query("SELECT p.*,o.client_id,o.title order_title FROM order_payments p JOIN orders o ON o.id=p.order_id WHERE p.due_date<CURDATE() AND p.status IN ('planned','pending')");
        $count = 0;
        foreach ($stmt->fetchAll() as $payment) {
            $this->db->prepare("UPDATE order_payments SET status='overdue' WHERE id=?")->execute([$payment['id']]);
            (new TelegramNotifier())->notifyUser((int)$payment['client_id'], 'payment_overdue', 'Просрочен платёж «' . $payment['title'] . '» по заказу «' . $payment['order_title'] . '».', 'payment-overdue-' . (int)$payment['id']);
            $count++;
        }
        return $count;
    }
    private function money($value) {
        $normalized = str_replace([' ', ','], ['', '.'], (string)$value);
        if (!is_numeric($normalized)) {
            return 0.0;
        }
        return round(min(999999999999.99, max(0, (float)$normalized)), 2);
    }
    private function isMoneyValue($value) {
        $normalized = str_replace([' ', ','], ['', '.'], (string)$value);
        return is_numeric($normalized) && (float)$normalized >= 0 && (float)$normalized <= 999999999999.99;
    }
    private function emptySummary($orderId) {
        return ['order_id' => $orderId, 'total_cost' => 0, 'planned_revenue' => 0, 'actual_revenue' => 0, 'prepayment' => 0, 'planned_expenses' => 0, 'actual_expenses' => 0, 'planned_profit' => 0, 'actual_profit' => 0, 'margin_percent' => 0, 'client_debt' => 0, 'planned_worker_payments' => 0, 'actual_worker_payments' => 0];
    }
    private function canManageOrder($orderId, $userId) {
        $order = (new Order())->getById((int)$orderId);
        return can('manage_finance') && (new Order())->canMutate($order, (int)$userId, currentRole());
    }
    private function canViewOrder($orderId, $userId) {
        $allowed = $userId && can('view_finance');
        if ($allowed) {
            $order = (new Order())->getById((int)$orderId);
            $allowed = (new Order())->canCollaborate($order, (int)$userId, currentRole());
        }
        return $allowed;
    }
    private function notifyClientPayment($orderId, $paymentId, $title, $amount) {
        $order = (new Order())->getById($orderId);
        if ($order && (int)$order['client_id'] > 0) {
            $message = 'Добавлен платёж «' . $title . '» на сумму ' . number_format($amount, 0, ',', ' ') . ' ₽.';
            (new Notification())->create((int)$order['client_id'], 'payment_created', 'Новый платёж', $message, '/account/order.php?id=' . (int)$orderId . '#finance');
            (new TelegramNotifier())->notifyUser((int)$order['client_id'], 'payment_created', $message, 'payment-created-' . (int)$paymentId);
        }
    }
}
