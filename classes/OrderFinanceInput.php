<?php
trait OrderFinanceInput {
    private function paymentStatusUpdateData($paymentId, $userId, $status, $paidAt) {
        if (!in_array($status, ['planned', 'pending', 'paid', 'overdue', 'cancelled'], true)) {
            throw new ValidationException('Недопустимый статус платежа');
        }
        $stmt = $this->db->prepare('SELECT * FROM order_payments WHERE id=?');
        $stmt->execute([(int)$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            throw new EntityNotFoundException('Платёж не найден');
        }
        if (!$this->canManageOrder((int)$payment['order_id'], $userId)) {
            throw new AccessDeniedException('Платёж недоступен');
        }
        $normalizedPaidAt = null;
        if ($status === 'paid') {
            $normalizedPaidAt = trim((string)$paidAt) ?: date(self::DATE_TIME_FORMAT);
            if (!$this->isDateTime($normalizedPaidAt)) {
                throw new ValidationException('Некорректная дата оплаты');
            }
        }
        return ['payment' => $payment, 'paid_at' => $normalizedPaidAt];
    }
    private function normalizePaymentInput($orderId, $userId, $data) {
        if (!$this->canManageOrder($orderId, $userId)) {
            throw new AccessDeniedException(self::ERROR_FINANCE_UNAVAILABLE);
        }
        $amount = $this->money($data['amount'] ?? 0);
        $title = trim((string)($data['title'] ?? ''));
        $paymentMethod = trim((string)($data['payment_method'] ?? ''));
        $comment = trim((string)($data['comment'] ?? ''));
        $this->validatePaymentContent($data['amount'] ?? 0, $amount, $title, $paymentMethod, $comment);
        $dueDate = $this->validatedPaymentDueDate($data['due_date'] ?? '');
        $status = $this->validatedPaymentStatus($data['status'] ?? 'planned');
        $paidAt = $this->paymentPaidAt($status, $data['paid_at'] ?? '');
        return [
            'amount' => $amount,
            'title' => $title,
            'payment_method' => $paymentMethod ?: null,
            'comment' => $comment ?: null,
            'due_date' => $dueDate,
            'paid_at' => $paidAt,
            'status' => $status,
        ];
    }
    private function validatePaymentContent($rawAmount, $amount, $title, $paymentMethod, $comment) {
        if (!$this->isMoneyValue($rawAmount) || $amount <= 0 || $title === '') {
            throw new ValidationException('Укажите название и сумму платежа');
        }
        if ($this->textLength($title) > 190 || $this->textLength($comment) > 5000 || $this->textLength($paymentMethod) > 80) {
            throw new ValidationException('Название или комментарий слишком длинные');
        }
    }
    private function validatedPaymentDueDate($value) {
        $dueDate = trim((string)$value);
        if ($dueDate !== '' && !$this->isDate($dueDate)) {
            throw new ValidationException('Некорректная дата платежа');
        }
        return $dueDate ?: null;
    }
    private function validatedPaymentStatus($value) {
        $status = (string)$value;
        if (!in_array($status, ['planned', 'pending', 'paid', 'overdue', 'cancelled'], true)) {
            throw new ValidationException('Недопустимый статус платежа');
        }
        return $status;
    }
    private function paymentPaidAt($status, $value) {
        $paidAt = null;
        if ($status === 'paid') {
            $paidAt = trim((string)$value) ?: date(self::DATE_TIME_FORMAT);
        }
        if ($paidAt !== null && !$this->isDateTime($paidAt)) {
            throw new ValidationException('Некорректная дата оплаты');
        }
        return $paidAt;
    }
    private function normalizeExpenseInput($orderId, $userId, $data) {
        if (!$this->canManageOrder($orderId, $userId)) {
            throw new AccessDeniedException(self::ERROR_FINANCE_UNAVAILABLE);
        }
        $categories = ['flowers', 'decor', 'rent', 'transport', 'installation', 'dismantling', 'contractors', 'staff', 'other'];
        $category = (string)($data['category'] ?? 'other');
        $title = trim((string)($data['title'] ?? ''));
        $comment = trim((string)($data['comment'] ?? ''));
        if (!in_array($category, $categories, true) || $title === '') {
            throw new ValidationException('Заполните статью расхода');
        }
        if (!$this->isMoneyValue($data['planned_amount'] ?? 0) || !$this->isMoneyValue($data['actual_amount'] ?? 0)) {
            throw new ValidationException('Проверьте суммы расхода');
        }
        if ($this->textLength($title) > 190 || $this->textLength($comment) > 5000) {
            throw new ValidationException('Название или комментарий слишком длинные');
        }
        $expenseDate = trim((string)($data['expense_date'] ?? ''));
        if ($expenseDate !== '' && !$this->isDate($expenseDate)) {
            throw new ValidationException('Некорректная дата расхода');
        }
        return [
            'category' => $category,
            'title' => $title,
            'planned_amount' => $this->money($data['planned_amount'] ?? 0),
            'actual_amount' => $this->money($data['actual_amount'] ?? 0),
            'expense_date' => $expenseDate ?: null,
            'comment' => $comment ?: null,
        ];
    }
    private function isDate($value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        return $date && $date->format('Y-m-d') === $value;
    }
    private function isDateTime($value) {
        $valid = false;
        foreach ([self::DATE_TIME_FORMAT, 'Y-m-d\\TH:i', 'Y-m-d\\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, (string)$value);
            if ($date && $date->format($format) === $value) {
                $valid = true;
                break;
            }
        }
        return $valid;
    }
    private function textLength($value) {
        return utf8Length((string)$value);
    }
}
