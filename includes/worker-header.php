<header class="main-header worker-header">
    <div class="container header-content">
        <a class="brand" href="/worker/dashboard.php">
            <span class="brand__text">Команда</span>
        </a>
        <nav class="main-nav">
            <a href="/worker/dashboard.php" class="nav-link">Главная</a>
            <a href="/worker/calendar.php" class="nav-link">Календарь</a>
            <a href="/chat.php" class="nav-link">Чаты</a>
            <a href="/worker/statistics.php" class="nav-link">Выплаты</a>
            <a href="/telegram-settings.php" class="nav-link">Безопасность</a>
        </nav>
        <div class="header-actions">
            <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
            <?php $unreadCount = (new Notification())->getUnreadCount($_SESSION['user_id']); ?>
            <a href="/worker/notifications.php" class="notification-btn">Уведомления<?php if ($unreadCount > 0): ?><span class="badge"><?php echo $unreadCount; ?></span><?php endif; ?></a>
            <a href="/worker/profile.php" class="profile-btn">Профиль</a>
            <a href="/logout.php" class="logout-btn">Выход</a>
        </div>
        <button class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Открыть меню"><span></span><span></span><span></span></button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="/worker/dashboard.php">Главная</a>
        <a href="/worker/calendar.php">Календарь</a>
        <a href="/chat.php">Чаты</a>
        <a href="/worker/statistics.php">Выплаты</a>
        <a href="/worker/profile.php">Профиль</a>
        <a href="/telegram-settings.php">Безопасность</a>
        <a href="/logout.php">Выход</a>
    </div>
</header>
<script>function toggleMobileMenu(){document.getElementById('mobileMenu').classList.toggle('show');}</script>
