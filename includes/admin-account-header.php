<header class="main-header admin-account-header">
    <div class="container header-content">
        <a class="brand" href="/admin/">
            <span class="brand__text">Панель администратора</span>
        </a>
        <nav class="main-nav" aria-label="Разделы администратора">
            <a href="/admin/" class="nav-link">Обзор</a>
            <a href="/admin/users.php" class="nav-link">Пользователи</a>
            <a href="/admin/telegram.php" class="nav-link">Telegram</a>
            <a href="/admin/settings.php" class="nav-link">Настройки</a>
            <a href="/telegram-settings.php" class="nav-link" aria-current="page">Безопасность</a>
        </nav>
        <div class="header-actions">
            <button class="theme-toggle"
                    id="themeToggle"
                    type="button"
                    onclick="toggleTheme()"
                    aria-label="Тема оформления">🌙</button>
            <a href="/admin/" class="profile-btn">Управление</a>
            <a href="/logout.php" class="logout-btn">Выход</a>
        </div>
        <button class="mobile-menu-btn"
                type="button"
                onclick="toggleMobileMenu()"
                aria-label="Открыть меню"
                aria-controls="mobileMenu"
                aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="/admin/">Обзор</a>
        <a href="/admin/users.php">Пользователи</a>
        <a href="/admin/telegram.php">Telegram</a>
        <a href="/admin/settings.php">Настройки</a>
        <a href="/telegram-settings.php" aria-current="page">Безопасность</a>
        <a href="/logout.php">Выход</a>
    </div>
</header>
<script>
function toggleMobileMenu() {
    var menu = document.getElementById('mobileMenu');
    var button = document.querySelector('.admin-account-header .mobile-menu-btn');
    var isOpen = menu.classList.toggle('show');
    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}
</script>
