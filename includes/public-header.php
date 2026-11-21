<?php if (empty($disablePublicAnalytics)): ?>
    <?php include_once __DIR__ . '/yandex-metrika-noscript.php'; ?>
<?php endif; ?>
<script>
    (function () {
        try {
            var saved = localStorage.getItem('theme');
            var preferred = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            var theme = saved === 'dark' || saved === 'light' ? saved : preferred;
            document.documentElement.setAttribute('data-theme', theme);
        } catch (error) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    })();
</script>
<header class="site-header site-header--public">
    <div class="container site-header__inner">
        <a class="brand" href="/">
            <span class="brand__text"><?php echo escape(SITE_NAME); ?></span>
        </a>
        <nav class="site-nav" aria-label="Основная навигация">
            <a href="/services">Услуги</a>
            <a href="/portfolio">Портфолио</a>
            <a href="/stages">Этапы</a>
            <a href="/about">О нас</a>
            <a href="/contacts">Контакты</a>
        </nav>
        <div class="site-actions">
            <button class="theme-toggle" id="themeToggle" type="button" onclick="toggleTheme()">
                <span class="theme-toggle__icon" aria-hidden="true">🌙</span>
                <span class="sr-only">Тема оформления</span>
            </button>
            <?php if (isLoggedIn()): ?>
                <a class="btn btn-outline btn-sm" href="<?php echo dashboardPathForRole(currentRole()); ?>">Кабинет</a>
            <?php else: ?>
                <a class="btn btn-outline btn-sm" href="/login">Вход</a>
            <?php endif; ?>
        </div>
    </div>
</header>
