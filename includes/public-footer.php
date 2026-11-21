<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <a class="brand" href="/">
                <span class="brand__text"><?php echo escape(SITE_NAME); ?></span>
            </a>
            <p>Свадьбы, флористика, декор и камерные события под ключ в Москве и Подмосковье.</p>
            <?php if (PUBLIC_LEGAL_NAME): ?><small><?php echo escape(PUBLIC_LEGAL_NAME); ?><?php echo PUBLIC_INN ? ' · ИНН ' . escape(PUBLIC_INN) : ''; ?></small><?php endif; ?>
        </div>
        <div>
            <h3>Разделы</h3>
            <a href="/services">Услуги</a>
            <a href="/portfolio">Портфолио</a>
            <a href="/stages">Этапы работы</a>
            <a href="/contacts">Контакты</a>
            <a href="/careers">Работа в команде</a>
        </div>
        <div>
            <h3>Связь</h3>
            <?php if (PUBLIC_PHONE): ?><a href="<?php echo escape(phoneLink(PUBLIC_PHONE)); ?>"><?php echo str_replace(' ', '&nbsp;', escape(PUBLIC_PHONE)); ?></a><?php endif; ?>
            <?php if (PUBLIC_EMAIL && filter_var(PUBLIC_EMAIL, FILTER_VALIDATE_EMAIL)): ?><a href="mailto:<?php echo escape(PUBLIC_EMAIL); ?>"><?php echo escape(PUBLIC_EMAIL); ?></a><?php endif; ?>
            <?php $publicTelegram = preg_replace('/\W/', '', ltrim((string)env('TELEGRAM_BOT_USERNAME', ''), '@')); ?>
            <?php if ($publicTelegram): ?><a href="https://t.me/<?php echo escape($publicTelegram); ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?>
            <a href="/quiz">Заполнить квиз</a>
            <a href="/privacy">Политика обработки данных</a>
            <a href="/cookies">Файлы браузера и аналитика</a>
            <button class="footer-link-button" type="button" data-cookie-settings>Настройки файлов браузера</button>
        </div>
    </div>
</footer>
<?php include_once __DIR__ . '/cookie-consent.php'; ?>
<?php if (empty($disablePublicAnalytics)): ?>
<script src="<?php echo asset('assets/js/metrika-goals.min.js'); ?>" defer></script>
<?php endif; ?>
<script src="<?php echo asset('assets/js/theme-toggle.min.js'); ?>" defer></script>
<script src="<?php echo asset('assets/js/app.min.js'); ?>" defer></script>
<script>
    (function () {
        var motionSrc = <?php echo json_encode(asset('assets/js/public-motion.min.js'), JSON_UNESCAPED_SLASHES); ?>;
        function loadPublicMotion() {
            if (!document.querySelector('.hero, #quizForm')) {
                return;
            }
            try {
                if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    return;
                }
            } catch (error) {}
            var script = document.createElement('script');
            script.src = motionSrc;
            script.defer = true;
            document.body.appendChild(script);
        }
        window.addEventListener('load', function () {
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(loadPublicMotion, { timeout: 1600 });
            } else {
                window.setTimeout(loadPublicMotion, 500);
            }
        }, { once: true });
    })();
</script>
