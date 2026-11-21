<?php
$resolvedKeywords = trim((string)($pageKeywords ?? SITE_KEYWORDS));
$defaultPublicRobots = isset($pageCanonical)
    ? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'
    : '';
$resolvedRobots = trim((string)($pageRobots ?? $defaultPublicRobots));
?>
<?php if ($resolvedKeywords !== ''): ?>
    <meta name="keywords" content="<?php echo escape($resolvedKeywords); ?>">
<?php endif; ?>
<?php if ($resolvedRobots !== ''): ?>
    <meta name="robots" content="<?php echo escape($resolvedRobots); ?>">
<?php endif; ?>
<meta name="author" content="<?php echo escape(SITE_NAME); ?>">
<meta name="yandex-verification" content="c2474f517056875c">
<?php include_once __DIR__ . '/favicon-links.php'; ?>
<meta name="theme-color" content="#f8f4f1" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121011" media="(prefers-color-scheme: dark)">
