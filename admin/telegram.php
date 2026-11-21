<?php
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    adminRedirect(
        'telegram.php',
        $adminRepository->retryInboundEvent(
            (int)($_POST['inbound_event_id'] ?? 0),
            (int)$_SESSION['user_id']
        )
    );
}

$adminTitle = 'Telegram';
$adminSection = 'telegram';
$bindings = $adminRepository->telegramBindings();
$operations = $adminRepository->syncOperations(100);
$inboundEvents = $adminRepository->inboundEvents(100);
$state = $adminRepository->systemState();
$activeBindings = array_values(
    array_filter(
        $bindings,
        static fn(array $row): bool => $row['status'] === 'active'
    )
);
$pendingInbound = array_values(
    array_filter(
        $inboundEvents,
        static fn(array $row): bool => in_array(
            $row['status'],
            ['pending', 'retry', 'processing'],
            true
        )
    )
);

require_once __DIR__ . '/header.php';
?>
<section class="admin-metrics" aria-label="Состояние Telegram">
    <article class="admin-metric">
        <strong><?php echo count($bindings); ?></strong>
        <span>Привязки групп</span>
    </article>
    <article class="admin-metric">
        <strong><?php echo count($activeBindings); ?></strong>
        <span>Активные группы</span>
    </article>
    <article class="admin-metric">
        <strong><?php echo count($pendingInbound); ?></strong>
        <span>Входящие в обработке</span>
    </article>
    <article class="admin-metric">
        <strong><?php echo escape($state['pid'] ?: '—'); ?></strong>
        <span>PID бота</span>
    </article>
</section>

<section class="admin-panel">
    <h2>Удаление сообщений в Telegram</h2>
    <p>
        Наблюдатель:
        <strong><?php echo escape($state['deletion_observer']); ?></strong>.
        Удаление с сайта ставится в очередь, но стандартный Bot API не сообщает
        обо всех удалениях, сделанных пользователями внутри Telegram.
    </p>
</section>

<section class="admin-panel">
    <h2>Привязки заказов</h2>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Заказ</th>
                    <th>Тип</th>
                    <th>Telegram chat</th>
                    <th>Статус</th>
                    <th>Участники</th>
                    <th>Ошибка</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bindings as $row): ?>
                <tr>
                    <td>
                        #<?php echo (int)$row['order_id']; ?><br>
                        <small><?php echo escape($row['order_title']); ?></small>
                    </td>
                    <td><?php echo escape($row['chat_kind']); ?></td>
                    <td><?php echo escape($row['telegram_chat_id']); ?></td>
                    <td><?php echo escape($row['status']); ?></td>
                    <td><?php echo (int)$row['joined_count']; ?></td>
                    <td><?php echo escape($row['last_error'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$bindings): ?>
                <tr>
                    <td colspan="6">Привязок пока нет.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="admin-panel">
    <h2>Входящие события Telegram</h2>
    <p>
        Transport inbox хранит событие до записи в общий сайт-чат.
        Содержимое завершённых событий очищается автоматически.
    </p>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Тип</th>
                    <th>Telegram</th>
                    <th>Статус</th>
                    <th>Попытки</th>
                    <th>Сообщение сайта</th>
                    <th>Ошибка</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($inboundEvents as $row): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo escape($row['event_type']); ?></td>
                    <td>
                        chat <?php echo escape($row['telegram_chat_id']); ?><br>
                        <small>
                            message
                            <?php echo escape($row['telegram_message_id'] ?: '—'); ?>
                        </small>
                    </td>
                    <td><?php echo escape($row['status']); ?></td>
                    <td>
                        <?php echo (int)$row['attempts']; ?>/<?php
                        echo (int)$row['max_attempts'];
                        ?>
                    </td>
                    <td><?php echo escape($row['site_message_id'] ?: '—'); ?></td>
                    <td><?php echo escape($row['last_error'] ?: '—'); ?></td>
                    <td>
                    <?php if (in_array($row['status'], ['failed', 'retry'], true)): ?>
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <input
                                type="hidden"
                                name="inbound_event_id"
                                value="<?php echo (int)$row['id']; ?>"
                            >
                            <button class="admin-button" type="submit">
                                Повторить
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="admin-muted">—</span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$inboundEvents): ?>
                <tr>
                    <td colspan="8">Входящих событий пока нет.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="admin-panel">
    <h2>Последние операции синхронизации</h2>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Операция</th>
                    <th>Контекст</th>
                    <th>Статус</th>
                    <th>Попытки</th>
                    <th>Ошибка</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($operations as $row): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo escape($row['operation_type']); ?></td>
                    <td>
                        заказ: <?php echo escape($row['order_id'] ?: '—'); ?><br>
                        чат: <?php echo escape($row['site_chat_id'] ?: '—'); ?><br>
                        сообщение:
                        <?php echo escape($row['site_message_id'] ?: '—'); ?>
                    </td>
                    <td><?php echo escape($row['status']); ?></td>
                    <td><?php echo (int)$row['attempts']; ?></td>
                    <td><?php echo escape($row['last_error'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$operations): ?>
                <tr>
                    <td colspan="6">Операций синхронизации пока нет.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
