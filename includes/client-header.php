<header class="main-header">
    <div class="container header-content">
        <a class="brand" href="/account/">
            <span class="brand__text">Кабинет клиента</span>
        </a>
        <nav class="main-nav">
            <a href="/account/" class="nav-link">Заказы</a>
            <a href="/chat.php" class="nav-link">Чаты</a>
            <a href="/quiz" class="nav-link">Новая заявка</a>
            <a href="/telegram-settings.php" class="nav-link">Безопасность</a>
        </nav>
        <div class="header-actions">
            <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" aria-label="Тема оформления">🌙</button>
            <a href="/logout.php" class="logout-btn">Выход</a>
        </div>
        <button class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Открыть меню"><span></span><span></span><span></span></button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="/account/">Заказы</a>
        <a href="/chat.php">Чаты</a>
        <a href="/quiz">Новая заявка</a>
        <a href="/telegram-settings.php">Безопасность</a>
        <a href="/logout.php">Выход</a>
    </div>
</header>
<script>function toggleMobileMenu(){document.getElementById('mobileMenu').classList.toggle('show');}</script>
