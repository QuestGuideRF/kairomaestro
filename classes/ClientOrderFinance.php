<?php
class ClientOrderFinance {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function present(array $order, $userId, $role = null) {
        $this->assertAccess($order, (int)$userId, $role ?? currentRole());
        $orderId = (int)$order['id'];
        $base = $this->base($orderId);
        $payments = $this->payments($orderId);
        $paid = 0.0;
        $next = null;
        foreach ($payments as $payment) {
            if ($payment['status'] === 'paid') {
                $paid += (float)$payment['amount'];
            } elseif ($next === null && in_array($payment['status'], ['planned', 'pending', 'overdue'], true)) {
                $next = $payment;
            }
        }
        $contractTotal = (float)($base['total_cost'] ?? 0);
        if ($contractTotal <= 0) {
            $contractTotal = (float)($base['planned_revenue'] ?? 0);
        }
        return [
            'total' => $contractTotal,
            'paid' => $paid,
            'remaining' => max(0, $contractTotal - $paid),
            'next_payment' => $next,
            'payments' => $payments,
            'documents' => $this->billingDocuments($orderId),
        ];
    }
    public function statusLabels() {
        return [
            'planned' => 'Запланирован',
            'pending' => 'Ожидается',
            'paid' => 'Получен',
            'overdue' => 'Просрочен',
            'cancelled' => 'Отменён',
        ];
    }
    private function base($orderId) {
        if (!dbTableExists('order_finance')) {
            return ['total_cost' => 0, 'planned_revenue' => 0];
        }
        $stmt = $this->db->prepare(
            'SELECT total_cost,planned_revenue FROM order_finance WHERE order_id=? LIMIT 1'
        );
        $stmt->execute([(int)$orderId]);
        return $stmt->fetch() ?: ['total_cost' => 0, 'planned_revenue' => 0];
    }
    private function payments($orderId) {
        if (!dbTableExists('order_payments')) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id,title,amount,due_date,paid_at,status,proof_path
             FROM order_payments WHERE order_id=?
             ORDER BY status="paid",due_date IS NULL,due_date,id'
        );
        $stmt->execute([(int)$orderId]);
        return $stmt->fetchAll();
    }
    private function billingDocuments($orderId) {
        if (!dbTableExists('order_documents')) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT id,document_type,document_number,title,status
             FROM order_documents
             WHERE order_id=? AND document_type IN ('invoice','estimate_appendix','contract')
               AND status<>'draft' AND status<>'cancelled'
             ORDER BY id DESC"
        );
        $stmt->execute([(int)$orderId]);
        return $stmt->fetchAll();
    }
    private function assertAccess(array $order, $userId, $role) {
        $allowed = $role === 'admin'
            || ($role === 'client' && (int)$order['client_id'] === $userId);
        if (!$allowed) {
            throw new AccessDeniedException('Платежи заказа недоступны');
        }
    }
}
