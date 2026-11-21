<header class="main-header organizer-header">
    <div class="container header-content">
        <a class="brand" href="/organizer/dashboard.php">
            <span class="brand__text">Азбука Декора</span>
        </a>
        <nav class="main-nav">
            <a href="/organizer/dashboard.php" class="nav-link">Главная</a>
            <a href="/organizer/orders.php" class="nav-link">Заказы</a>
            <a href="/organizer/calendar.php" class="nav-link">Календарь</a>
            <a href="/organizer/staff.php" class="nav-link">Команда</a>
            <?php if (can('manage_templates')): ?><a href="/organizer/templates.php" class="nav-link">Шаблоны</a><?php endif; ?>
        </nav>
        <div class="header-actions">
            <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
            <?php $unreadCount = (new Notification())->getUnreadCount($_SESSION['user_id']); ?>
            <a href="/organizer/notifications.php" class="notification-btn">Уведомления<?php if ($unreadCount > 0): ?><span class="badge"><?php echo $unreadCount; ?></span><?php endif; ?></a>
            <a href="/organizer/profile.php" class="profile-btn">Профиль</a>
            <a href="/logout.php" class="logout-btn">Выход</a>
        </div>
        <button class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Открыть меню"><span></span><span></span><span></span></button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="/organizer/dashboard.php">Главная</a>
        <a href="/organizer/orders.php">Заказы</a>
        <a href="/organizer/staff.php">Команда</a>
        <a href="/organizer/calendar.php">Календарь</a>
        <?php if (can('manage_templates')): ?><a href="/organizer/templates.php">Шаблоны</a><?php endif; ?>
        <a href="/organizer/notifications.php">Уведомления</a>
        <a href="/telegram-settings.php">Безопасность</a>
        <?php if (isAdminRole()): ?><a href="/organizer/public-content.php">Сайт</a><a href="/organizer/candidates.php">Кандидаты</a><a href="/organizer/audit.php">Аудит</a><a href="/organizer/notification-queue.php">Очередь</a><a href="/organizer/reviews.php">Отзывы</a><?php endif; ?>
        <a href="/organizer/profile.php">Профиль</a>
        <a href="/logout.php">Выход</a>
    </div>
</header>
<script>function toggleMobileMenu(){document.getElementById('mobileMenu').classList.toggle('show');}</script>
