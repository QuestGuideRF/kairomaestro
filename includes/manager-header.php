<header class="main-header manager-header">
    <div class="container header-content">
        <a class="brand" href="/manager/dashboard.php">
            <span class="brand__text">Менеджер</span>
        </a>
        <nav class="main-nav">
            <a href="/manager/dashboard.php" class="nav-link">Заявки</a>
            <a href="/organizer/orders.php" class="nav-link">Все заказы</a>
            <a href="/manager/templates.php" class="nav-link">Мои шаблоны</a>
        </nav>
        <div class="header-actions">
            <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
            <?php $unreadCount = (new Notification())->getUnreadCount($_SESSION['user_id']); ?>
            <a href="/organizer/notifications.php" class="notification-btn">Уведомления<?php if ($unreadCount > 0): ?><span class="badge"><?php echo $unreadCount; ?></span><?php endif; ?></a>
            <a href="/logout.php" class="logout-btn">Выход</a>
        </div>
        <button class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Открыть меню"><span></span><span></span><span></span></button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="/manager/dashboard.php">Заявки</a>
        <a href="/organizer/orders.php">Все заказы</a>
        <a href="/manager/templates.php">Мои шаблоны</a>
        <a href="/organizer/notifications.php">Уведомления</a>
        <a href="/telegram-settings.php">Безопасность</a>
        <a href="/logout.php">Выход</a>
    </div>
</header>
<script>function toggleMobileMenu(){document.getElementById('mobileMenu').classList.toggle('show');}</script>
